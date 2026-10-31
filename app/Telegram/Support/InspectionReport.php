<?php

namespace App\Telegram\Support;

use App\Models\Site;
use App\Support\ReportPeriod;
use App\Support\Svod;
use Carbon\CarbonInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

/**
 * Kunlik tekshiruvlar (buzilish) hisoboti: tumanlar kesimidagi svod jadval rasmi va qisqa izoh (xodim ismlari va manzillarsiz).
 * Davomat hisoboti oluvchilariga (adminlar) botda yuboriladi.
 */
class InspectionReport
{
    /** Hisobot davri: oldingi kun 18:00 dan shu kun 18:00 gacha (ReportPeriod) — dashboarddagi "Kunlik natija" bilan bir xil. */
    public static function caption(CarbonInterface $day): string
    {
        $period = ReportPeriod::for($day);
        $s = $period->summary(); // rad etilganlar (arxiv) hisobga olinmaydi

        $total = Site::countable()->count();
        $svod = Svod::data($day)['total'];
        $done = $total - $svod['left'];

        $lines = [
            '📊 <b>Kunlik hisobot — '.$day->format('d.m.Y').'</b>',
            '<i>'.$period->label().'</i>',
            "Tekshiruvlar: <b>{$s['inspections']}</b>",
            "🏚 Buzilgan: <b>{$s['demolished']}</b>",
            "🚧 Jarayonda: <b>{$s['progress']}</b>",
        ];
        $s['court'] && $lines[] = "⚖️ Sud qarori bekor: <b>{$s['court']}</b>";
        $s['before'] && $lines[] = "📷 Oldingi holat qayd etildi: <b>{$s['before']}</b>";
        $lines[] = '';
        $lines[] = "Qoldiq: <b>{$svod['left']}</b> ta · ".Svod::area($svod['area']).' ga';
        $lines[] = "Umumiy bajarilish: <b>{$done}</b> / {$total}".($total ? ' ('.round($done / $total * 100, 1).'%)' : '');
        $s['inspections'] || $lines[] = "\nBu davrda tekshiruv natijasi kiritilmagan.";

        return implode("\n", $lines);
    }

    /** Svod jadval rasmi + izohni barcha oluvchilarga yuboradi; yuborilgan xabarlar soni qaytadi. */
    public static function send(Nutgram $bot, CarbonInterface $day): int
    {
        $caption = Script::apply(self::caption($day));
        $file = tempnam(sys_get_temp_dir(), 'svod').'.png';
        file_put_contents($file, Svod::png($day, fn (string $s) => Script::apply($s)));

        $sent = 0;
        $photo = null; // birinchi yuborishdan keyin Telegram file_id qayta ishlatiladi
        try {
            foreach (AttendanceReport::recipients() as $admin) {
                try {
                    $message = $bot->sendPhoto($photo ?? InputFile::make(fopen($file, 'r'), 'svod.png'), [
                        'chat_id' => $admin->telegram_id, 'caption' => $caption, 'parse_mode' => ParseMode::HTML,
                    ]);
                    $photo ??= collect($message?->photo ?? [])->last()?->file_id;
                    $sent++;
                } catch (\Throwable $e) {
                    report($e); // admin botni bloklagan bo'lishi mumkin
                }
            }
        } finally {
            @unlink($file);
        }

        return $sent;
    }
}
