<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Staff;
use App\Telegram\Handlers\AttendanceHandler;
use App\Telegram\Support\Script;
use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

class SendAttendance extends Command
{
    protected $signature = 'attendance:send';

    protected $description = 'Faol xodimlarga kunlik davomat so‘rovini yuboradi (jadval bo‘yicha 08:00 da). Qayta ishga tushirilsa, takror yubormaydi.';

    public function handle(Nutgram $bot): int
    {
        if (now()->greaterThan(Attendance::deadline())) {
            $this->info('Davomat muddati tugagan — yuborilmaydi.');

            return self::SUCCESS;
        }

        $sent = $failed = $retry = 0;

        Staff::where('status', Staff::ACTIVE)->where('role', Staff::ROLE_EMPLOYEE)->where('attendance_required', true)
            ->each(function (Staff $staff) use ($bot, &$sent, &$failed, &$retry) {
                $row = Attendance::firstOrCreate(['staff_id' => $staff->id, 'date' => today()->toDateString()], ['status' => Attendance::SENT]);
                if ($row->sent_at) {
                    return; // allaqachon yuborilgan
                }

                try {
                    $message = $bot->sendMessage(Script::apply(AttendanceHandler::promptText($staff)), [
                        'chat_id' => $staff->telegram_id,
                        'parse_mode' => ParseMode::HTML,
                        'reply_markup' => AttendanceHandler::promptMarkup(),
                    ]);
                    $row->update(['sent_at' => now(), 'message_id' => $message?->message_id]);
                    $sent++;
                } catch (\Throwable $e) {
                    if (self::isPermanent($e)) {
                        $row->update(['sent_at' => now(), 'flags' => ['undeliverable']]);
                        $failed++;
                    } else {
                        // vaqtinchalik xato (tarmoq, 429, 5xx): sent_at bo'sh qoladi, keyingi ishga tushishda qayta yuboriladi
                        $retry++;
                    }
                    $this->warn("Yuborilmadi: {$staff->full_name} — {$e->getMessage()}");
                }

                usleep(60_000); // Telegram limiti (~30 xabar/soniya) dan ancha past
            });

        $this->info("Yuborildi: {$sent}, yetkazib bo‘lmaydi: {$failed}, qayta uriniladi: {$retry}");

        return self::SUCCESS;
    }

    /** Qayta urinish foyda bermaydigan xato: xodim botni bloklagan (403) yoki chat mavjud emas (400). */
    public static function isPermanent(\Throwable $e): bool
    {
        return $e instanceof TelegramException && in_array($e->getCode(), [400, 403], true);
    }
}
