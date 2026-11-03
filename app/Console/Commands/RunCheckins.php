<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Checkin;
use App\Models\Staff;
use App\Telegram\Support\Script;
use App\Telegram\Support\Ui;
use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;

/**
 * Shartnoma xodimlarining soatlik qaydi (har daqiqada): muddati o'tganlar "javob bermagan" bo'ladi,
 * joriy soat so'rovi hali yuborilmaganlarga yuboriladi (vaqtinchalik xato bo'lsa — keyingi daqiqada qayta).
 * Ertalab "sabab bilan yo'q" deb javob berganlarga so'rov yuborilmaydi.
 */
class RunCheckins extends Command
{
    protected $signature = 'checkins:run';

    protected $description = 'Shartnoma xodimlariga soatlik joylashuv va video so‘rovini yuboradi, muddati o‘tganlarni yopadi.';

    public static function markup(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            Ui::t('📍 Joylashuv va video yuborish'),
            web_app: new WebAppInfo(rtrim((string) config('app.url'), '/').'/tg/checkin'),
        ));
    }

    public static function text(Staff $staff, Checkin $row): string
    {
        return "🕐 <b>Soatlik qayd — {$row->slot}</b>\n\nHurmatli <b>".Ui::e($staff->full_name).'</b>!'
            ."\nTurgan joyingiz (joylashuv) va hududdagi holatni ko‘rsatuvchi qisqa video yuboring."
            ."\n\n<i>Javob muddati: soat ".$row->deadline_at->format('H:i').' gacha.</i>'
            ."\n⚠️ <i>Javob bermasangiz, «javob bermagan» deb belgilanadi.</i>";
    }

    public function handle(Nutgram $bot): int
    {
        $closed = Checkin::whereIn('status', Checkin::OPEN)->where('deadline_at', '<', now())->update(['status' => Checkin::MISSED]);

        $slot = collect(Checkin::slots())->first(fn ($s) => now()->betweenIncluded(today()->setTimeFromTimeString($s), Checkin::deadlineFor($s)));
        if (! $slot || ! in_array(now()->dayOfWeekIso, config('tutash.attendance.days'), true)) {
            $this->info("So‘rov vaqti emas. Yopildi: {$closed}");

            return self::SUCCESS;
        }

        $absent = Attendance::whereDate('date', today())->where('status', Attendance::ABSENT)->pluck('staff_id')->all();
        $sent = $retry = 0;
        Staff::where('status', Staff::ACTIVE)->where('role', Staff::ROLE_EMPLOYEE)->where('is_contract', true)->whereNotNull('telegram_id')
            ->whereNotIn('id', $absent)->get()
            ->each(function (Staff $staff) use ($bot, $slot, &$sent, &$retry) {
                $row = Checkin::where('staff_id', $staff->id)->whereDate('date', today())->where('slot', $slot)->first()
                    ?? Checkin::create(['staff_id' => $staff->id, 'date' => today()->toDateString(), 'slot' => $slot,
                        'status' => Checkin::SENT, 'deadline_at' => Checkin::deadlineFor($slot)]);
                if ($row->sent_at) {
                    return; // allaqachon yuborilgan
                }
                try {
                    $message = $bot->sendMessage(Script::apply(self::text($staff, $row)), [
                        'chat_id' => $staff->telegram_id, 'parse_mode' => ParseMode::HTML, 'reply_markup' => self::markup(),
                    ]);
                    $row->update(['sent_at' => now(), 'message_id' => $message?->message_id]);
                    $sent++;
                } catch (\Throwable $e) {
                    if (SendAttendance::isPermanent($e)) {
                        $row->update(['sent_at' => now(), 'flags' => ['undeliverable']]);
                    } else {
                        $retry++; // keyingi daqiqada qayta urinish
                    }
                    $this->warn("Yuborilmadi: {$staff->full_name} — {$e->getMessage()}");
                }
                usleep(60_000);
            });

        $this->info("Soat {$slot}: yuborildi {$sent}, qayta uriniladi {$retry}, yopildi {$closed}");

        return self::SUCCESS;
    }
}
