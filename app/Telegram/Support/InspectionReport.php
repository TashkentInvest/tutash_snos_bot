<?php

namespace App\Telegram\Support;

use App\Models\Site;
use App\Support\ReportPeriod;
use App\Support\Svod;
use Carbon\CarbonInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

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

        $svod = Svod::data($day)['total'];
        $p = Svod::progress($svod); // sud qarori bekor qilinganlar ham bajarilgan hisoblanadi

        $lines = [
            '📊 <b>Kunlik hisobot — '.$day->format('d.m.Y').'</b>',
            '<i>'.$period->label().'</i>',
            "Tekshiruvlar: <b>{$s['inspections']}</b>",
            "🏚 Buzilgan: <b>{$s['demolished']}</b>",
            ...($s['court'] ? ["⚖️ Sud qarori bekor: <b>{$s['court']}</b>"] : []),
            "🚧 Jarayonda: <b>{$s['progress']}</b>",
        ];
        $s['before'] && $lines[] = "📷 Oldingi holat qayd etildi: <b>{$s['before']}</b>";
        $lines[] = '';
        $lines[] = "Qoldiq: <b>{$svod['left']}</b> ta · ".Svod::area($svod['area']).' ga';
        $lines[] = "Umumiy bajarilish: <b>{$p['done']}</b> / {$p['all']} (".Svod::pct($p['pct']).')';
        $s['inspections'] || $lines[] = "\nBu davrda tekshiruv natijasi kiritilmagan.";

        return implode("\n", $lines);
    }

    /**
     * Hisobotni barcha oluvchilarga yuboradi: bitta albomda ikki rasm — svod jadval (izoh bilan) va tumanlar kesimida bajarilish.
     * Yuborilgan hisobotlar soni qaytadi.
     */
    public static function send(Nutgram $bot, CarbonInterface $day): int
    {
        $caption = Script::apply(self::caption($day));
        $t = fn (string $s) => Script::apply($s);
        $files = [];
        foreach (['svod' => Svod::png($day, $t), 'bajarilish' => Svod::progressPng($day, $t)] as $name => $png) {
            file_put_contents($files[$name] = tempnam(sys_get_temp_dir(), $name).'.png', $png);
        }

        $sent = 0;
        $ids = null; // birinchi yuborishdan keyin Telegram file_id'lari qayta ishlatiladi
        try {
            foreach (AttendanceReport::recipients() as $admin) {
                try {
                    $media = array_map(fn ($src) => ['type' => 'photo', 'media' => $src], $ids ?? array_map(fn ($f) => fopen($f, 'r'), array_values($files)));
                    $media[0] += ['caption' => $caption, 'parse_mode' => ParseMode::HTML];
                    $messages = $bot->sendMediaGroup($media, ['chat_id' => $admin->telegram_id]);
                    $ids ??= self::fileIds($messages);
                    $sent++;
                } catch (\Throwable $e) {
                    report($e); // admin botni bloklagan bo'lishi mumkin
                }
            }
        } finally {
            array_map(fn ($f) => @unlink($f), $files);
        }

        return $sent;
    }

    /** Albomdagi rasmlarning file_id'lari (ikkalasi ham bo'lsa). */
    private static function fileIds(?array $messages): ?array
    {
        $ids = collect($messages ?? [])->map(fn ($m) => collect($m?->photo ?? [])->last()?->file_id)->filter()->values()->all();

        return count($ids) === 2 ? $ids : null;
    }
}
