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
        $schedule->command('inspections:prune-drafts')->hourly();
        $schedule->command('queue:prune-failed --hours=720')->daily();

        // Davomat: ish kunlari 08:00 da so'rov yuboriladi, 09:00 da yopiladi (app.timezone bo'yicha).
        // send_at dan boshlab muddatgacha har daqiqa: vaqtinchalik xato tufayli yetmagan so'rovlar qayta yuboriladi
        // (yuborilganlarga takror yubormaydi). Bitta yozuv — bir vaqtda ikki nusxa ishlab, qo'sh xabar ketmasligi uchun.
        $schedule->command('attendance:send')->days(config('tutash.attendance.days'))->everyMinute()
            ->between(config('tutash.attendance.send_at'), config('tutash.attendance.deadline'))->withoutOverlapping();
        // Kunlik buzilish (tekshiruvlar) hisoboti — har kuni kechqurun (dam olish kuni natija bo'lmasa yuborilmaydi).
        $schedule->command('inspections:report')->dailyAt(config('tutash.daily_report_at'))->withoutOverlapping();
        $schedule->command('attendance:close')->days(config('tutash.attendance.days'))->dailyAt(config('tutash.attendance.deadline'))->withoutOverlapping();
        // Bajarilmagan moderator izohlari bo'yicha eslatma — ish kunlari ertalab.
        $schedule->command('followups:remind')->days(config('tutash.attendance.days'))->dailyAt(config('tutash.followup_remind_at'))->withoutOverlapping();
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
