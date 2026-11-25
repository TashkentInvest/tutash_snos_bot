<?php

namespace App\Telegram\Support;

use App\Support\DesignCodeSvod;
use App\Support\Svod;
use Illuminate\Support\Collection;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

/**
 * «Dizayn-kod svodi» Telegram'ga: tumanlar kesimida rasm (admin paneldagi jadval kabi) — Dizayn-kod ko'chalari va
 * ko'chadan N m ichidagi noqonuniy obyektlar. Bosh sahifadagi «Buzilish hisobotini yuborish» oynasidan tanlanadi.
 */
class DesignCodeReport
{
    private static function num(float $v, int $dec = 1): string
    {
        $s = number_format($v, $dec, ',', ' ');

        return $dec ? rtrim(rtrim($s, '0'), ',') : $s; // butun son: 0 — bo'sh katak emas, «0»
    }

    public static function png(int $buffer, \DateTimeInterface $day, ?callable $t = null): string
    {
        $svod = DesignCodeSvod::build($buffer);
        $rows = [];
        foreach ([['name' => 'Toshkent shahar jami'] + $svod['total'], ...$svod['districts']] as $n => $r) {
            $s = $r['snos'];
            $rows[] = [
                'cells' => [$n ? (string) $n : '', $r['name'], (string) $r['streets'], self::num($r['km']),
                    self::num($r['dk_total'], 0), self::num($r['dk_installed'], 0), self::num($r['dk_pct']).'%',
                    (string) $s['total'], (string) $s['not_demolished'], (string) $s['in_progress'], (string) $s['demolished'],
                    $s['total'] ? self::num($s['pct']).'%' : '—'],
                'sum' => $n === 0,
                'colors' => [5 => 'green', 9 => 'orange', 10 => 'green'],
            ];
        }

        return Svod::table(
            'Dizayn-kod ko‘chalari va noqonuniy obyektlar (ko‘chadan '.$buffer.' m ichida), '.$day->format('d.m.Y'),
            ['№', 'Tuman nomi', "Ko‘cha-\nlar", 'km', "Dizayn-kod\nobyektlari", "Talabga\nkeltirildi", "Bajarilish\n(DK)",
                "Noqonuniy\n(jami)", "Buzil-\nmagan", "Jarayon-\nda", "Buzil-\ngan", "Bajarilish\n(buzish)"],
            [40, 210, 74, 64, 104, 98, 98, 98, 80, 80, 74, 98], $rows, $t);
    }

    public static function caption(int $buffer, \DateTimeInterface $day): string
    {
        $t = DesignCodeSvod::build($buffer)['total'];
        $s = $t['snos'];

        return "🛣 <b>Dizayn-kod ko‘chalari svodi</b> — {$day->format('d.m.Y')}\n\n"
            ."Ko‘chalar: <b>{$t['streets']}</b> ta · ".self::num($t['km'])." km\n"
            .'Dizayn-kod obyektlari: <b>'.self::num($t['dk_total'], 0).'</b>, talabga keltirildi <b>'.self::num($t['dk_installed'], 0).'</b> ('.self::num($t['dk_pct'])."%)\n\n"
            ."Ko‘chadan {$buffer} m ichidagi noqonuniy obyektlar: <b>{$s['total']}</b> ta\n"
            ."Buzilgan: <b>{$s['demolished']}</b> · jarayonda: {$s['in_progress']} · qolgan: <b>{$s['left']}</b> (".self::num($s['pct']).'% bajarildi)';
    }

    /** Tuman ko'chalari kesimida (svod sahifasidagi 2-jadval kabi): tuman jami + har bir ko'cha, ko'p obyektlisi tepada. */
    public static function districtPng(int $buffer, int $districtId, \DateTimeInterface $day, ?callable $t = null): string
    {
        $svod = DesignCodeSvod::build($buffer, $districtId);
        $d = collect($svod['districts'])->firstWhere('id', $districtId);
        $rows = [];
        foreach ([['name' => ($d['name'] ?? '').' jami', 'km' => $d['km'] ?? 0] + ($d ?? []), ...$svod['streets']] as $n => $r) {
            $s = $r['snos'] ?? DesignCodeSvod::siteCounts([]);
            $km = $n ? ($r['length_km'] ?? $r['drawn_km'] ?? 0) : $r['km'];
            $rows[] = [
                'cells' => [$n ? (string) $n : '', $r['name'].($n && empty($r['shape']) ? ' *' : ''), self::num((float) $km),
                    self::num($r['dk_total'] ?? 0, 0), self::num($r['dk_installed'] ?? 0, 0), self::num($r['dk_pct'] ?? 0).'%',
                    (string) $s['total'], (string) $s['not_demolished'], (string) $s['in_progress'], (string) $s['demolished'],
                    $s['total'] ? self::num($s['pct']).'%' : '—'],
                'sum' => $n === 0,
                'colors' => [4 => 'green', 8 => 'orange', 9 => 'green'],
            ];
        }

        return Svod::table(
            ($d['name'] ?? 'Tuman').' — Dizayn-kod ko‘chalari kesimida (ko‘chadan '.$buffer.' m ichida), '.$day->format('d.m.Y'),
            ['№', 'Ko‘cha nomi', 'km', "Dizayn-kod\nobyektlari", "Talabga\nkeltirildi", "Bajarilish\n(DK)",
                "Noqonuniy\n(jami)", "Buzil-\nmagan", "Jarayon-\nda", "Buzil-\ngan", "Bajarilish\n(buzish)"],
            [40, 300, 64, 104, 98, 98, 98, 80, 80, 74, 98], $rows, $t);
    }

    public static function districtCaption(int $buffer, int $districtId, \DateTimeInterface $day): string
    {
        $d = collect(DesignCodeSvod::build($buffer)['districts'])->firstWhere('id', $districtId);
        if (! $d) {
            return '🛣 <b>Dizayn-kod ko‘chalari</b> — '.$day->format('d.m.Y');
        }
        $s = $d['snos'];

        return "🛣 <b>{$d['name']} — Dizayn-kod ko‘chalari</b> — {$day->format('d.m.Y')}\n\n"
            ."Ko‘chalar: <b>{$d['streets']}</b> ta · ".self::num($d['km'])." km\n"
            .'Dizayn-kod obyektlari: <b>'.self::num($d['dk_total'], 0).'</b>, talabga keltirildi <b>'.self::num($d['dk_installed'], 0).'</b> ('.self::num($d['dk_pct'])."%)\n"
            ."Ko‘chadan {$buffer} m ichida noqonuniy: <b>{$s['total']}</b> · buzilgan <b>{$s['demolished']}</b> · qolgan <b>{$s['left']}</b>"
            .(collect(DesignCodeSvod::build($buffer, $districtId)['streets'])->contains(fn ($x) => ! $x['shape']) ? "\n\n<i>* — ko‘cha chizmasi taxminiy (boshi va oxiri).</i>" : '');
    }

    /** Tumanlar ro'yxati (yuborish oynasidagi belgilash uchun): Dizayn-kod ko'chasi bor tumanlar. */
    public static function districtOptions(int $buffer = DesignCodeSvod::DEFAULT_BUFFER): array
    {
        return collect(DesignCodeSvod::build($buffer)['districts'])->mapWithKeys(fn ($d) => [(string) $d['id'] => $d['name'].' ('.$d['streets'].' ko‘cha)'])->all();
    }

    /**
     * Oluvchilarga: shahar svodi ($city) va tanlangan tumanlar ko'chalar kesimida — har biri alohida rasm + izoh.
     * @return int yuborilgan xabarlar soni
     */
    public static function send(Nutgram $bot, Collection $to, \DateTimeInterface $day, int $buffer = DesignCodeSvod::DEFAULT_BUFFER, array $districtIds = [], bool $city = true): int
    {
        $t = fn (string $s) => Script::apply($s);
        $images = $city ? [[self::png($buffer, $day, $t), Script::apply(self::caption($buffer, $day)), 'dizayn-kod-svod.png']] : [];
        foreach (array_map('intval', $districtIds) as $id) {
            $images[] = [self::districtPng($buffer, $id, $day, $t), Script::apply(self::districtCaption($buffer, $id, $day)), "dizayn-kod-tuman-{$id}.png"];
        }
        $sent = 0;
        foreach ($images as [$png, $caption, $name]) {
            $fileId = null; // birinchi yuborishdan keyin Telegram file_id qayta ishlatiladi
            foreach ($to as $staff) {
                try {
                    if ($fileId) {
                        $bot->sendPhoto($fileId, ['chat_id' => $staff->telegram_id, 'caption' => $caption, 'parse_mode' => ParseMode::HTML]);
                    } else {
                        $fh = fopen('php://temp', 'r+');
                        fwrite($fh, $png);
                        rewind($fh);
                        $msg = $bot->sendPhoto(\SergiX44\Nutgram\Telegram\Types\Internal\InputFile::make($fh, $name),
                            ['chat_id' => $staff->telegram_id, 'caption' => $caption, 'parse_mode' => ParseMode::HTML]);
                        $fileId = collect($msg?->photo ?? [])->last()?->file_id;
                    }
                    $sent++;
                } catch (\Throwable $e) {
                    report($e); // oluvchi botni bloklagan bo'lishi mumkin
                }
            }
        }

        return $sent;
    }
}
