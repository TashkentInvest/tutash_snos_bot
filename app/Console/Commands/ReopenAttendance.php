<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Telegram\Handlers\AttendanceHandler;
use App\Telegram\Support\Script;
use App\Telegram\Support\Ui;
use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

/**
 * Bugungi davomatni tanlangan xodimlarga qayta ochadi (masalan bot xatosi tufayli javobi qabul qilinmagan bo'lsa):
 * holat "so'rov yuborildi" ga qaytadi, o'z muddati beriladi va uzr bilan yangi so'rov yuboriladi.
 *
 *   php artisan attendance:reopen 12 15 --minutes=60
 *   php artisan attendance:reopen --missed-contract --minutes=60   (bugun "javob bermadi" bo'lgan shartnoma xodimlari)
 */
class ReopenAttendance extends Command
{
    protected $signature = 'attendance:reopen {staff?* : xodim id lari} {--missed-contract : bugun javob bermagan shartnoma xodimlari}
        {--minutes=60 : javob uchun necha daqiqa} {--dry : faqat ro\'yxatni ko\'rsatish}';

    protected $description = 'Bugungi davomatni tanlangan xodimlarga qayta ochadi va uzr bilan qayta so‘raydi.';

    public function handle(Nutgram $bot): int
    {
        $ids = array_map('intval', $this->argument('staff') ?? []);
        $query = Staff::where('status', Staff::ACTIVE)->whereNotNull('telegram_id');
        if ($this->option('missed-contract')) {
            $query->where('role', Staff::ROLE_CONTRACT)
                ->whereHas('attendances', fn ($q) => $q->whereDate('date', today())->where('status', Attendance::MISSED));
        } elseif ($ids) {
            $query->whereIn('id', $ids);
        } else {
            $this->error('Xodim id lari yoki --missed-contract kerak.');

            return self::FAILURE;
        }

        $until = now()->addMinutes(max(10, (int) $this->option('minutes')))->startOfMinute();
        $staff = $query->orderBy('full_name')->get();
        $this->table(['id', 'F.I.SH', 'rol'], $staff->map(fn (Staff $s) => [$s->id, $s->full_name, $s->role]));
        if ($this->option('dry') || $staff->isEmpty()) {
            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($staff as $s) {
            $row = Attendance::firstOrNew(['staff_id' => $s->id, 'date' => today()->toDateString()]);
            if ($row->status === Attendance::PRESENT) {
                continue; // allaqachon qayd etilgan
            }
            $row->fill(['status' => Attendance::SENT, 'deadline_at' => $until, 'sent_at' => now(), 'responded_at' => null, 'reason' => null])->save();

            try {
                $message = $bot->sendMessage(Script::apply(
                    '🔄 <b>Davomat qayta ochildi</b>'."\n\nHurmatli <b>".Ui::e($s->full_name).'</b>!'
                    ."\nBotdagi texnik kamchilik tufayli bugungi davomatingiz qabul qilinmagan edi. Kamchilik to‘g‘rilandi — "
                    .'iltimos, davomatni qaytadan yuboring. Noqulaylik uchun uzr so‘raymiz.'
                    ."\n\n<i>Javob muddati: soat ".$until->format('H:i').' gacha.</i>'
                ), ['chat_id' => $s->telegram_id, 'parse_mode' => ParseMode::HTML, 'reply_markup' => AttendanceHandler::promptMarkup()]);
                $row->update(['message_id' => $message?->message_id]);
                $sent++;
            } catch (\Throwable $e) {
                $this->warn("Yuborilmadi: {$s->full_name} — {$e->getMessage()}");
            }
            AuditLog::record('attendance.reopened', null, ['staff' => $s->id, 'until' => $until->format('H:i')]);
            usleep(60_000);
        }

        $this->info("Qayta ochildi va yuborildi: {$sent}, muddat {$until->format('H:i')}");

        return self::SUCCESS;
    }
}
