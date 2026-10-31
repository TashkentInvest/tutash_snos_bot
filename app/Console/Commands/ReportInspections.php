<?php

namespace App\Console\Commands;

use App\Models\Inspection;
use App\Telegram\Support\AttendanceReport;
use App\Telegram\Support\InspectionReport;
use Carbon\Carbon;
use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;

class ReportInspections extends Command
{
    protected $signature = 'inspections:report {--date= : Sana (YYYY-MM-DD), standart — bugun}';

    protected $description = 'Kunlik tekshiruvlar (buzilish) xulosasini davomat hisoboti oluvchilariga botda yuboradi.';

    public function handle(Nutgram $bot): int
    {
        $day = $this->option('date') ? Carbon::parse($this->option('date')) : today();

        // dam olish kunida natija bo'lmasa — bo'sh hisobot yuborilmaydi
        $workday = in_array($day->dayOfWeekIso, config('tutash.attendance.days'), true);
        if (! $workday && ! Inspection::counted()->whereDate('submitted_at', $day)->exists()) {
            $this->info('Dam olish kuni, natija yo‘q — yuborilmadi.');

            return self::SUCCESS;
        }

        $sent = InspectionReport::send($bot, $day);
        $this->info("Yuborilgan xabarlar: {$sent}");

        return self::SUCCESS;
    }
}
