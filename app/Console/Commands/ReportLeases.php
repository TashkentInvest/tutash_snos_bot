<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ReportSetting;
use App\Telegram\Support\LeaseReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;

/**
 * Gastronomik svod: har daqiqa ishga tushadi, lekin faqat admin panelda (Sozlamalar → Hisobotlar) belgilangan
 * kun va vaqtda yuboradi — kuniga bir marta. --force — jadvaldan qat'i nazar hozir yuborish.
 */
class ReportLeases extends Command
{
    protected $signature = 'leases:report {--force : Jadvalni tekshirmasdan hozir yuborish}';

    protected $description = 'Gastronomik ko‘chalar ijara shartnomalari svodini Telegram’ga yuboradi (sozlamadagi vaqtda).';

    public function handle(Nutgram $bot): int
    {
        $setting = ReportSetting::for(ReportSetting::GASTRO);
        $o = ReportSetting::gastroOptions();
        $now = now();

        if (! $this->option('force')) {
            if (! $setting->enabled || ! in_array($now->dayOfWeekIso, $o['days'], true) || $now->format('H:i') !== $o['at']) {
                return self::SUCCESS; // vaqti emas
            }
            if (! Cache::add('leases-report:'.$now->toDateString(), 1, now()->endOfDay())) {
                return self::SUCCESS; // bugun yuborilgan
            }
        }

        $sent = LeaseReport::send($bot, today(), null, $o['onsite']);
        AuditLog::record('report.gastro_svod', null, ['sent' => $sent, 'forced' => (bool) $this->option('force')]);
        $this->info("Yuborilgan xabarlar: {$sent}");

        return self::SUCCESS;
    }
}
