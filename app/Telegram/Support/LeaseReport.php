<?php

namespace App\Telegram\Support;

use App\Models\ReportSetting;
use App\Support\LeaseSvod;
use App\Support\Svod;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

/**
 * Gastronomik ko'chalar → ijara shartnomalari svodi Telegram'ga: tumanlar kesimida rasm (jami — barcha davr, «Bugun» — shu kun
 * tuzilganlar) va qisqa izoh. Takrorlarsiz; «faqat joyida» — sozlamaga ko'ra (sinov uchun uzoqdan tuzilganlar chiqmaydi).
 */
class LeaseReport
{
    private static function money(float $v): string
    {
        return number_format($v, 0, ',', ' ');
    }

    private static function area(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
    }

    public static function png(CarbonInterface $day, bool $onsite, ?callable $t = null): string
    {
        $all = LeaseSvod::data(null, $day, true, $onsite);
        $today = collect(LeaseSvod::data($day, $day, true, $onsite)['rows'])->keyBy('name');
        $rows = [];
        foreach ([$all['total'], ...$all['rows']] as $n => $r) {
            $new = $n ? (int) ($today[$r['name']]['count'] ?? 0) : array_sum(array_column($today->all(), 'count'));
            $rows[] = [
                'cells' => [$n ? (string) $n : '', $r['name'], $r['count'] ? (string) $r['count'] : '—', $new ? '+'.$new : '—',
                    $r['count'] ? self::area($r['area']) : '—', $r['count'] ? self::money($r['monthly']) : '—', $r['count'] ? self::money($r['annual']) : '—'],
                'sum' => $n === 0,
                'colors' => $new ? [3 => 'green'] : [],
            ];
        }

        return Svod::table(
            'Gastronomik ko‘chalar — ijara shartnomalari, '.$day->format('d.m.Y'),
            ['№', 'Tuman nomi', "Shartnomalar\n(jami)", "Bugun\ntuzilgan", "Maydon\n(m²)", "Oylik to‘lov\n(so‘m)", "Joriy yil to‘lovi\n(so‘m)"],
            [44, 250, 120, 104, 120, 170, 190], $rows, $t);
    }

    public static function caption(CarbonInterface $day, bool $onsite): string
    {
        $t = LeaseSvod::data(null, $day, true, $onsite)['total'];
        $new = LeaseSvod::data($day, $day, true, $onsite)['total'];

        return "🍽 <b>Ijara shartnomalari svodi</b> — {$day->format('d.m.Y')}\n\n"
            ."Jami: <b>{$t['count']}</b> ta (yuridik {$t['legal']}, jismoniy {$t['individual']}) · ".self::area($t['area'])." m²\n"
            .'Oylik to‘lov: <b>'.self::money($t['monthly'])." so‘m</b>\n"
            .'Joriy yil to‘lovi: <b>'.self::money($t['annual'])." so‘m</b>\n"
            .'To‘liq yil hajmi (12 oy): '.self::money($t['yearly'])." so‘m\n"
            .($new['count'] ? "\nBugun tuzilgan: <b>{$new['count']}</b> ta · ".self::area($new['area']).' m² · oyiga '.self::money($new['monthly']).' so‘m' : "\nBugun yangi shartnoma yo‘q.")
            .($onsite ? "\n\n<i>Faqat joyida tuzilgan shartnomalar (uzoqdan — sinov — tuzilganlar hisobga olinmagan).</i>" : '');
    }

    /** Oluvchilarga rasm + izoh; yuborilgan xabarlar soni. $to — bo'sh bo'lsa sozlamadagilar. */
    public static function send(Nutgram $bot, CarbonInterface $day, ?Collection $to = null, ?bool $onsite = null): int
    {
        $onsite ??= ReportSetting::gastroOptions()['onsite'];
        $png = self::png($day, $onsite, fn (string $s) => Script::apply($s));
        $caption = Script::apply(self::caption($day, $onsite));
        $sent = 0;
        $fileId = null; // birinchi yuborishdan keyin Telegram file_id qayta ishlatiladi
        foreach ($to ?? ReportSetting::recipients(ReportSetting::GASTRO) as $staff) {
            try {
                if ($fileId) {
                    $bot->sendPhoto($fileId, ['chat_id' => $staff->telegram_id, 'caption' => $caption, 'parse_mode' => ParseMode::HTML]);
                } else {
                    $fh = fopen('php://temp', 'r+');
                    fwrite($fh, $png);
                    rewind($fh);
                    $msg = $bot->sendPhoto(\SergiX44\Nutgram\Telegram\Types\Internal\InputFile::make($fh, 'gastro-svod.png'),
                        ['chat_id' => $staff->telegram_id, 'caption' => $caption, 'parse_mode' => ParseMode::HTML]);
                    $fileId = collect($msg?->photo ?? [])->last()?->file_id;
                }
                $sent++;
            } catch (\Throwable $e) {
                report($e); // oluvchi botni bloklagan bo'lishi mumkin
            }
        }

        return $sent;
    }
}
