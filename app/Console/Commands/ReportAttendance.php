<?php

namespace App\Console\Commands;

use App\Telegram\Support\AttendanceReport;
use Carbon\Carbon;
use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;

class ReportAttendance extends Command
{
    protected $signature = 'attendance:report {--date= : Sana (YYYY-MM-DD), standart — bugun}';

    protected $description = 'Kunlik davomat xulosasini adminlarga botda yuboradi.';

    public function handle(Nutgram $bot): int
    {
        $day = $this->option('date') ? Carbon::parse($this->option('date')) : today();
        $sent = AttendanceReport::sendToAdmins($bot, $day);
        $this->info("Yuborilgan xabarlar: {$sent}");

        return self::SUCCESS;
    }
}
