<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // withoutOverlapping(N): jarayon to'satdan o'lsa (OOM, qayta yuklash) qulf N daqiqada o'zi ochiladi (standart 24 soat edi).
        $schedule->command('inspections:prune-drafts')->hourly();
        $schedule->command('queue:prune-failed --hours=720')->daily();

        // Davomat: ish kunlari 08:00 da so'rov yuboriladi, 09:00 da yopiladi (app.timezone bo'yicha).
        // send_at dan boshlab muddatgacha har daqiqa: vaqtinchalik xato tufayli yetmagan so'rovlar qayta yuboriladi
        // (yuborilganlarga takror yubormaydi). Bitta yozuv — bir vaqtda ikki nusxa ishlab, qo'sh xabar ketmasligi uchun.
        $schedule->command('attendance:send')->days(config('tutash.attendance.days'))->everyMinute()
            ->between(config('tutash.attendance.send_at'), config('tutash.attendance.deadline'))->withoutOverlapping(10);
        // Kunlik buzilish (tekshiruvlar) hisoboti — har kuni kechqurun (dam olish kuni natija bo'lmasa yuborilmaydi).
        $schedule->command('inspections:report')->dailyAt(config('tutash.daily_report_at'))->withoutOverlapping(60)->runInBackground();
        $schedule->command('attendance:remind')->days(config('tutash.attendance.days'))->dailyAt(config('tutash.attendance.remind_at'))->withoutOverlapping(30);
        // qayta ochilgan davomatlar (attendance:reopen) — o'z muddati o'tgach yopiladi
        $schedule->command('attendance:close --reopened')->everyFiveMinutes()->between('09:05', '23:55')->withoutOverlapping(10);
        // muddat + 3 daqiqa: video yuklanayotganlar ulgursin (Attendance::GRACE_MINUTES)
        $schedule->command('attendance:close')->days(config('tutash.attendance.days'))->dailyAt(\App\Models\Attendance::closeAt()->format('H:i'))->withoutOverlapping(30)->runInBackground();
        // Shartnoma xodimlari: soatlik qayd (10:00–18:59, 13:00–14:00 tushlik — CHECKIN_SLOTS); har daqiqa — yetmaganlar qayta yuboriladi.
        $schedule->command('checkins:run')->days(config('tutash.attendance.days'))->everyMinute()
            ->between(\App\Models\Checkin::slots()[0] ?? '10:00', \App\Models\Checkin::scheduleUntil())->withoutOverlapping(10);
        // Soatlik qayd hisobotlari (oluvchilar — Sozlamalar → Hisobotlar): har soat H:59:59 va kun yakuni 17:59:59.
        // Buyruq H:59 da boshlanib 59-soniyani kutadi — boshqa vazifalarni to'smasligi uchun fonda.
        $schedule->command('checkins:report')->days(config('tutash.attendance.days'))->hourlyAt(59)
            ->between(\App\Models\Checkin::slots()[0] ?? '10:00', \App\Models\Checkin::scheduleUntil())->runInBackground()->withoutOverlapping(5);
        $schedule->command('checkins:report --daily')->days(config('tutash.attendance.days'))
            ->dailyAt(config('tutash.checkins.daily_report_at'))->runInBackground()->withoutOverlapping(5);
        // Bajarilmagan moderator izohlari bo'yicha eslatma — ish kunlari ertalab.
        $schedule->command('followups:remind')->days(config('tutash.attendance.days'))->dailyAt(config('tutash.followup_remind_at'))->withoutOverlapping(30);
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
