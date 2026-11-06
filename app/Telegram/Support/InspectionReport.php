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

    /** Hisobotdagi rasmlar (dashboard'da tanlanadi). */
    public const IMAGES = ['svod' => 'Tumanlar kesimida svod', 'bajarilish' => 'Tumanlar kesimida bajarilish'];

    /**
     * Hisobotni yuboradi: tanlangan rasmlar (2 ta — bitta albom, 1 ta — rasm, 0 — faqat matn) va izoh.
     * $to — oluvchilar (bo'sh bo'lsa — Sozlamalar → Hisobotlardagilar). Yuborilgan hisobotlar soni qaytadi.
     */
    public static function send(Nutgram $bot, CarbonInterface $day, ?\Illuminate\Support\Collection $to = null, array $images = ['svod', 'bajarilish']): int
    {
        $caption = Script::apply(self::caption($day));
        $t = fn (string $s) => Script::apply($s);
        $make = ['svod' => fn () => Svod::png($day, $t), 'bajarilish' => fn () => Svod::progressPng($day, $t)];
        $files = [];
        foreach (array_intersect_key($make, array_flip($images)) as $name => $build) {
            $png = $build();
            $tmp = tempnam(sys_get_temp_dir(), $name);
            @unlink($tmp); // tempnam bo'sh fayl yaratadi — biz .png nomini ishlatamiz, eski bo'sh fayl qolib ketmasin
            file_put_contents($files[$name] = $tmp.'.png', $png);
        }

        $sent = 0;
        $ids = null; // birinchi yuborishdan keyin Telegram file_id'lari qayta ishlatiladi
        try {
            foreach ($to ?? \App\Models\ReportSetting::recipients(\App\Models\ReportSetting::SVOD) as $admin) {
                // tarmoq uzilishi/timeout bo'lsa — bir marta qayta urinish; bloklangan bot kabi doimiy xatoda qayta urinilmaydi
                for ($try = 1; $try <= 2; $try++) {
                    try {
                        $opt = ['chat_id' => $admin->telegram_id];
                        if (count($files) >= 2) {
                            $media = array_map(fn ($src) => ['type' => 'photo', 'media' => $src], $ids ?? array_map(fn ($f) => fopen($f, 'r'), array_values($files)));
                            $media[0] += ['caption' => $caption, 'parse_mode' => ParseMode::HTML];
                            $messages = $bot->sendMediaGroup($media, $opt);
                            $ids ??= self::fileIds($messages);
                        } elseif ($files) {
                            $bot->sendPhoto(fopen(array_values($files)[0], 'r'), $opt + ['caption' => $caption, 'parse_mode' => ParseMode::HTML]);
                        } else {
                            $bot->sendMessage($caption, $opt + ['parse_mode' => ParseMode::HTML]);
                        }
                        $sent++;
                        break;
                    } catch (\Throwable $e) {
                        if ($try === 2 || \App\Console\Commands\SendAttendance::isPermanent($e)) {
                            report($e); // admin botni bloklagan bo'lishi mumkin
                            break;
                        }
                        // 429 — Telegram aytgan vaqtcha kutamiz (ko'pi bilan 30 s)
                        $wait = $e instanceof \SergiX44\Nutgram\Telegram\Exceptions\TelegramException && $e->getCode() === 429
                            ? (int) $e->getParameter('retry_after', 5) : 2;
                        sleep(min(max($wait, 1), 30));
                    }
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
