<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\ReportSetting;
use App\Models\Staff;
use App\Support\ReportPeriod;
use App\Telegram\Support\Script;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SergiX44\Nutgram\Nutgram;

/**
 * Botdagi «Boshqaruv → Hisobot yuborish»: tanlangan hisobot «faqat menga» yoki sozlamadagi oluvchilarga.
 * Navbatda (rasm/PDF yasash vaqt oladi — bot javobi kutib qolmasin); oxirida yuborgan xodimga natija.
 */
class BotSendReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    /** Hisobot → [nomi, oluvchilar sozlamasi kaliti]. */
    public const REPORTS = [
        'svod' => ['Buzilish svodi (2 ta rasm)', ReportSetting::SVOD],
        'pdf' => ['PDF hisobot', ReportSetting::SVOD],
        'gastro' => ['Gastronomik ko‘chalar — ijara svodi', ReportSetting::GASTRO],
        'dk' => ['Dizayn-kod ko‘chalari svodi', ReportSetting::SVOD],
        'att' => ['Ertalabki davomat', ReportSetting::ATTENDANCE],
    ];

    public function __construct(public string $report, public bool $onlyMe, public int $staffId)
    {
    }

    /** Kimlarga boradi (faqat menga — o'zi). */
    public static function recipients(string $report, bool $onlyMe, Staff $me)
    {
        return $onlyMe ? collect([$me]) : ReportSetting::recipients(self::REPORTS[$report][1]);
    }

    public function handle(Nutgram $bot): void
    {
        $me = Staff::find($this->staffId);
        if (! $me || ! isset(self::REPORTS[$this->report])) {
            return;
        }
        $to = self::recipients($this->report, $this->onlyMe, $me);
        $day = ReportPeriod::current()->day;
        $sent = 0;
        try {
            $sent = match ($this->report) {
                'svod' => \App\Telegram\Support\InspectionReport::send($bot, $day, $to, ['svod', 'bajarilish']),
                'pdf' => \App\Support\ReportPdf::send($bot, $day, $to),
                'gastro' => \App\Telegram\Support\LeaseReport::send($bot, $day, $to),
                'dk' => \App\Telegram\Support\DesignCodeReport::send($bot, $to, now()),
                'att' => \App\Telegram\Support\CheckinReport::send($bot, ReportSetting::ATTENDANCE,
                    \App\Telegram\Support\AttendanceReport::image(\App\Filament\Pages\Dashboard::attendanceDay(ReportPeriod::current())->toMutable()), $to),
            };
        } catch (\Throwable $e) {
            report($e);
        }
        AuditLog::record('report.bot.'.$this->report, $me->telegram_id, ['staff' => $me->id, 'only_me' => $this->onlyMe, 'sent' => $sent]);

        if (! $this->onlyMe) { // o'ziga natija (faqat o'ziga yuborganda hisobotning o'zi — natija)
            try {
                $bot->sendMessage(Script::apply('✅ «'.self::REPORTS[$this->report][0].'» yuborildi: '.$sent.' ta xabar.'), ['chat_id' => $me->telegram_id]);
            } catch (\Throwable) {
            }
        }
    }
}
