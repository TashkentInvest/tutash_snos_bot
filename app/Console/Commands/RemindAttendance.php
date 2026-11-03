<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Telegram\Handlers\AttendanceHandler;
use App\Telegram\Support\Script;
use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

/** Davomat muddati tugashidan oldin (jadval bo'yicha 08:45) hali javob bermagan xodimlarga eslatma. */
class RemindAttendance extends Command
{
    protected $signature = 'attendance:remind';

    protected $description = 'Davomatga hali javob bermagan (yoki tugatmagan) xodimlarga muddat tugashidan oldin eslatma yuboradi.';

    public function handle(Nutgram $bot): int
    {
        if (now()->greaterThan(Attendance::deadline())) {
            $this->info('Davomat muddati tugagan — eslatma yuborilmaydi.');

            return self::SUCCESS;
        }

        $sent = 0;
        Attendance::with('staff')->whereDate('date', today())->whereIn('status', Attendance::OPEN)->whereNotNull('sent_at')->get()
            ->reject(fn (Attendance $a) => in_array('undeliverable', $a->flags ?? [], true) || ! $a->staff?->telegram_id)
            ->each(function (Attendance $a) use ($bot, &$sent) {
                if (! $msg = AttendanceHandler::reminder($a)) {
                    return;
                }
                try {
                    $bot->sendMessage(Script::apply($msg[0]), array_filter([
                        'chat_id' => $a->staff->telegram_id, 'parse_mode' => ParseMode::HTML, 'reply_markup' => $msg[1],
                    ]));
                    $sent++;
                } catch (\Throwable $e) {
                    $this->warn("Yuborilmadi: {$a->staff->full_name} — {$e->getMessage()}"); // botni bloklagan bo'lishi mumkin
                }
                usleep(60_000);
            });

        $this->info("Eslatma yuborildi: {$sent}");

        return self::SUCCESS;
    }
}
