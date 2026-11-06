<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Telegram\Support\Script;
use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

class CloseAttendance extends Command
{
    // --reopened: faqat qayta ochilgan (o'z muddati bor) yozuvlar, muddati o'tganda; adminlarga hisobot yuborilmaydi
    protected $signature = 'attendance:close {--reopened}';

    protected $description = 'Muddat (09:00) tugagach, javob bermagan yoki tugatmagan xodimlarni "javob bermadi" deb belgilaydi.';

    public function handle(Nutgram $bot): int
    {
        $closed = 0;

        $reopened = (bool) $this->option('reopened');
        $graceAgo = now()->subMinutes(Attendance::GRACE_MINUTES);
        Attendance::with('staff')->whereDate('date', today())->whereIn('status', Attendance::OPEN)
            // qayta ochilgan yozuv o'z muddati (+ qo'shimcha daqiqalar) tugaguncha yopilmaydi
            ->when($reopened, fn ($q) => $q->whereNotNull('deadline_at')->where('deadline_at', '<', $graceAgo),
                fn ($q) => $q->where(fn ($q) => $q->whereNull('deadline_at')->orWhere('deadline_at', '<', $graceAgo)))
            ->each(function (Attendance $row) use ($bot, &$closed) {
            // muddatgacha hech yetkazib bo'lmagan so'rov — xodim aybi emas, hisobotda ajratib ko'rsatiladi
            $row->update(['status' => Attendance::MISSED] + ($row->sent_at ? [] : ['flags' => ['undeliverable']]));
            $closed++;

            if (in_array('undeliverable', $row->flags ?? [], true)) {
                return;
            }

            try {
                if ($row->message_id) {
                    $bot->editMessageReplyMarkup(['chat_id' => $row->staff->telegram_id, 'message_id' => $row->message_id]);
                }
                $bot->sendMessage(Script::apply('⏰ Davomatni qabul qilish muddati ('.$row->dueAt()->format('H:i').') tugadi. Javobingiz qayd etilmadi.'), [
                    'chat_id' => $row->staff->telegram_id,
                    'parse_mode' => ParseMode::HTML,
                    'reply_markup' => \SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove::make(true),
                ]);
            } catch (\Throwable) {
                // xodim botni bloklagan bo'lishi mumkin — davomat baribir "missed" bo'lib qoladi
            }
        });

        if ($reopened) {
            $closed && $this->info("Qayta ochilganlardan yopildi: {$closed}");

            return self::SUCCESS;
        }
        $reported = \App\Telegram\Support\AttendanceReport::sendToAdmins($bot, today());
        $this->info("Yopildi: {$closed}, adminlarga xabar: {$reported}");

        return self::SUCCESS;
    }
}
