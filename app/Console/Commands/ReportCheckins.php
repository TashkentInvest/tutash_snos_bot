<?php

namespace App\Console\Commands;

use App\Models\Checkin;
use App\Models\ReportSetting;
use App\Telegram\Support\CheckinReport;
use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;

/**
 * Soatlik qayd hisobotlari. Rejada H:59 da ishga tushadi va shu daqiqaning 59-soniyasini kutadi (H:59:59),
 * so'rov muddati aynan tugagan paytdagi holat yuboriladi.
 *   checkins:report          — tugayotgan soat bo'yicha (masalan 15:59:59 da 15:00)
 *   checkins:report --daily  — kun yakuni (17:59:59): shu paytgacha tugagan barcha soatlar
 */
class ReportCheckins extends Command
{
    protected $signature = 'checkins:report {--daily} {--no-wait : 59-soniyani kutmasdan (sinov uchun)}';

    protected $description = 'Soatlik qayd hisobotini (rasm) sozlamadagi oluvchilarga yuboradi.';

    public function handle(Nutgram $bot): int
    {
        $daily = (bool) $this->option('daily');
        $key = $daily ? ReportSetting::CHECKIN_DAILY : ReportSetting::CHECKIN_HOURLY;
        if (! in_array(now()->dayOfWeekIso, config('tutash.attendance.days'), true)) {
            $this->info('Dam olish kuni.');

            return self::SUCCESS;
        }
        if (! ReportSetting::enabled($key)) {
            $this->info('Hisobot sozlamada o‘chirilgan.');

            return self::SUCCESS;
        }

        // H:59:59 gacha kutish — so'rov muddati aynan shu paytda tugaydi
        $at = now()->copy()->setTime(now()->hour, 59, 59);
        if (! $this->option('no-wait') && now()->minute === 59 && now()->lessThan($at)) {
            sleep(max(0, min(60, $at->getTimestamp() - now()->getTimestamp())));
        }

        if ($daily) {
            $report = CheckinReport::daily(today(), now());
        } else {
            // muddati shu soatda tugayotgan so'rov (window 60 daqiqa: 15:00 -> 15:59:59); tushlik soatida yo'q
            $slot = collect(Checkin::slots())->first(fn ($s) => Checkin::deadlineFor($s)->format('H') === now()->format('H'));
            if (! $slot) {
                $this->info('Bu soatda so‘rov yo‘q.');

                return self::SUCCESS;
            }
            $report = CheckinReport::hourly(today(), $slot);
        }

        $sent = CheckinReport::send($bot, $key, $report);
        $this->info("Yuborildi: {$sent}");

        return self::SUCCESS;
    }
}
