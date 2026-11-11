<?php

namespace App\Console\Commands;

use App\Models\Checkin;
use App\Models\ReportSetting;
use App\Telegram\Support\CheckinReport;
use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;

/**
 * Soatlik qayd hisobotlari. Rejada har soat H:59 da ishga tushadi va shu daqiqaning 59-soniyasini kutadi (H:59:59) —
 * so'rov muddati aynan tugagan paytdagi holat yuboriladi. Ketma-ketlik bitta jarayonda: avval shu soat hisoboti,
 * kunning oxirgi soati bo'lsa — ortidan kun yakuni (ikkalasi aralashib, kun yakuni oldin kelib qolmasin).
 *   checkins:report          — tugayotgan soat (+ oxirgi soat bo'lsa kun yakuni)
 *   checkins:report --daily  — faqat kun yakuni, hozirgacha tugagan soatlar (qo'lda)
 */
class ReportCheckins extends Command
{
    protected $signature = 'checkins:report {--daily} {--no-wait : 59-soniyani kutmasdan (sinov uchun)}';

    protected $description = 'Soatlik qayd hisobotini (rasm) sozlamadagi oluvchilarga yuboradi; oxirgi soatdan keyin — kun yakuni.';

    public function handle(Nutgram $bot): int
    {
        if (! in_array(now()->dayOfWeekIso, config('tutash.attendance.days'), true)) {
            $this->info('Dam olish kuni.');

            return self::SUCCESS;
        }

        // H:59:59 gacha kutish — so'rov muddati aynan shu paytda tugaydi
        $at = now()->copy()->setTime(now()->hour, 59, 59);
        if (! $this->option('no-wait') && now()->minute === 59 && now()->lessThan($at)) {
            sleep(max(0, min(60, $at->getTimestamp() - now()->getTimestamp())));
        }

        if ($this->option('daily')) {
            $this->sendDaily($bot);

            return self::SUCCESS;
        }

        // muddati shu soatda tugayotgan so'rov (window 60 daqiqa: 15:00 -> 15:59:59)
        $slots = Checkin::slots();
        $slot = collect($slots)->first(fn ($s) => Checkin::deadlineFor($s)->format('H') === now()->format('H'));
        if (! $slot) {
            $this->info('Bu soatda so‘rov yo‘q.');

            return self::SUCCESS;
        }

        if (ReportSetting::enabled(ReportSetting::CHECKIN_HOURLY)) {
            $sent = CheckinReport::send($bot, ReportSetting::CHECKIN_HOURLY, CheckinReport::hourly(today(), $slot));
            $this->info("Soat {$slot}: yuborildi {$sent}");
        } else {
            $this->info('Soatlik hisobot sozlamada o‘chirilgan.');
        }

        // kunning oxirgi soati — ortidan kun yakuni (barcha soatlar bilan)
        if ($slot === collect($slots)->max()) {
            $this->sendDaily($bot);
        }

        return self::SUCCESS;
    }

    private function sendDaily(Nutgram $bot): void
    {
        if (! ReportSetting::enabled(ReportSetting::CHECKIN_DAILY)) {
            $this->info('Kun yakuni hisoboti sozlamada o‘chirilgan.');

            return;
        }
        $sent = CheckinReport::send($bot, ReportSetting::CHECKIN_DAILY, CheckinReport::daily(today(), now()));
        $this->info("Kun yakuni: yuborildi {$sent}");
    }
}
