<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Telegram\Support\Script;
use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

class CloseAttendance extends Command
{
    protected $signature = 'attendance:close';

    protected $description = 'Muddat (09:30) tugagach, javob bermagan yoki tugatmagan xodimlarni "javob bermadi" deb belgilaydi.';

    public function handle(Nutgram $bot): int
    {
        $closed = 0;

        Attendance::with('staff')->whereDate('date', today())->whereIn('status', Attendance::OPEN)->each(function (Attendance $row) use ($bot, &$closed) {
            $row->update(['status' => Attendance::MISSED]);
            $closed++;

            if (in_array('undeliverable', $row->flags ?? [], true)) {
                return;
            }

            try {
                if ($row->message_id) {
                    $bot->editMessageReplyMarkup(['chat_id' => $row->staff->telegram_id, 'message_id' => $row->message_id]);
                }
                $bot->sendMessage(Script::apply('⏰ Davomatni qabul qilish muddati ('.config('tutash.attendance.deadline').') tugadi. Javobingiz qayd etilmadi.'), [
                    'chat_id' => $row->staff->telegram_id,
                    'parse_mode' => ParseMode::HTML,
                    'reply_markup' => \SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove::make(true),
                ]);
            } catch (\Throwable) {
                // xodim botni bloklagan bo'lishi mumkin — davomat baribir "missed" bo'lib qoladi
            }
        });

        $reported = \App\Telegram\Support\AttendanceReport::sendToAdmins($bot, today());
        $this->info("Yopildi: {$closed}, adminlarga xabar: {$reported}");

        return self::SUCCESS;
    }
}
