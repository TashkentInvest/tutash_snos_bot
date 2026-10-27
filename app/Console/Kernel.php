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

        // Davomat: ish kunlari 08:45 da so'rov yuboriladi, 09:30 da yopiladi (app.timezone bo'yicha).
        $schedule->command('attendance:send')->days(config('tutash.attendance.days'))->dailyAt(config('tutash.attendance.send_at'))->withoutOverlapping();
        $schedule->command('attendance:close')->days(config('tutash.attendance.days'))->dailyAt(config('tutash.attendance.deadline'))->withoutOverlapping();
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
