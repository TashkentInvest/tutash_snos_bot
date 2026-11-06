<?php

namespace App\Telegram\Support;

use App\Models\Attendance;
use App\Models\Checkin;
use App\Models\ReportSetting;
use App\Models\Staff;
use App\Support\Svod;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

/**
 * Soatlik qayd hisobotlari (rasm + qisqa izoh), oluvchilar — Sozlamalar → Hisobotlar:
 *  - har soat yakuni (H:59:59): shu soat bo'yicha kim qayd etdi, kim javob bermadi;
 *  - kun yakuni (17:59:59): shu paytgacha tugagan barcha soatlar jadvali.
 */
class CheckinReport
{
    /** Soatlik qayd yuritiladigan faol xodimlar. */
    private static function staff(): Collection
    {
        return Staff::where('status', Staff::ACTIVE)->hourlyCheckin()->whereNotNull('telegram_id')->orderBy('full_name')->get();
    }

    /** Bugun sabab bilan "ishda emas" deganlar (ularga soatlik so'rov yuborilmaydi). */
    private static function absentIds(Carbon $day): array
    {
        return Attendance::whereDate('date', $day)->where('status', Attendance::ABSENT)->pluck('staff_id')->all();
    }

    /** @return array{0: string, 1: ?string} [holat matni, rang] */
    private static function state(?Checkin $c, bool $absent): array
    {
        return match (true) {
            $c?->status === Checkin::PRESENT => ['Qayd etildi', 'green'],
            $absent => ['Ishda emas (sabab)', 'orange'],
            $c && in_array('undeliverable', $c->flags ?? [], true) => ['Xabar yetmadi', 'orange'],
            $c !== null => ['Javob bermadi', 'red'],
            default => ['So‘rov yuborilmagan', null],
        };
    }

    /** @return array{png: string, caption: string, total: int} */
    public static function hourly(Carbon $day, string $slot): array
    {
        $staff = self::staff();
        $absent = self::absentIds($day);
        $rows = Checkin::whereDate('date', $day)->where('slot', $slot)->whereIn('staff_id', $staff->pluck('id'))->get()->keyBy('staff_id');
        $present = $rows->where('status', Checkin::PRESENT)->count();
        $missed = $staff->filter(fn (Staff $s) => self::state($rows[$s->id] ?? null, in_array($s->id, $absent, true))[1] === 'red')->count();

        $table = [['cells' => ['', 'Jami', "{$present} / {$staff->count()}", '', ''], 'sum' => true, 'colors' => []]];
        foreach ($staff->values() as $i => $s) {
            $c = $rows[$s->id] ?? null;
            [$label, $color] = self::state($c, in_array($s->id, $absent, true));
            $table[] = [
                'cells' => [(string) ($i + 1), Str::limit((string) $s->full_name, 30), $label,
                    $c?->status === Checkin::PRESENT ? (string) $c->responded_at?->format('H:i') : '', Str::limit((string) $c?->comment, 42)],
                'sum' => false,
                'colors' => $color ? [2 => $color] : [],
            ];
        }

        $t = fn (string $s) => Script::apply($s);
        $png = Svod::table('Soatlik qayd — '.$slot.' · '.$day->format('d.m.Y'), ['№', 'Xodim', 'Holat', 'Vaqt', 'Izoh'], [44, 250, 190, 80, 380], $table, $t);
        $caption = "🕐 <b>Soatlik qayd — {$slot}</b> ({$day->format('d.m.Y')})\n✅ Qayd etdi: <b>{$present}</b> / {$staff->count()}"
            .($missed ? " · ❌ Javob bermadi: <b>{$missed}</b>" : '');

        return ['png' => $png, 'caption' => $caption, 'total' => $staff->count()];
    }

    /** Kun yakuni: $until gacha muddati tugagan soatlar (17:59:59 da — 17:00 gacha). */
    public static function daily(Carbon $day, ?Carbon $until = null): array
    {
        $until ??= now();
        $slots = collect(Checkin::slots())->filter(fn ($s) => Checkin::deadlineFor($s, $day)->lessThanOrEqualTo($until))->values()->all();
        $staff = self::staff();
        $absent = self::absentIds($day);
        $checkins = Checkin::whereDate('date', $day)->whereIn('staff_id', $staff->pluck('id'))->get()->groupBy('staff_id');
        $attendance = Attendance::whereDate('date', $day)->whereIn('staff_id', $staff->pluck('id'))->get()->keyBy('staff_id');

        $perSlot = array_fill_keys($slots, 0);
        $done = 0;
        $full = 0;
        $body = [];
        foreach ($staff->values() as $i => $s) {
            $cells = [(string) ($i + 1), Str::limit((string) $s->full_name, 28)];
            $colors = [];
            $a = $attendance[$s->id] ?? null;
            [$cells[], $aColor] = match ($a?->status) {
                Attendance::PRESENT => [(string) $a->responded_at?->format('H:i'), 'green'],
                Attendance::ABSENT => ['sabab', 'orange'],
                Attendance::MISSED => ['yo‘q', 'red'],
                null => ['—', null],
                default => ['…', null],
            };
            $aColor && $colors[2] = $aColor;
            $mine = ($checkins[$s->id] ?? collect())->keyBy('slot');
            $count = 0;
            foreach ($slots as $j => $slot) {
                $c = $mine[$slot] ?? null;
                [$label, $color] = self::state($c, in_array($s->id, $absent, true));
                $cells[] = match ($color) {
                    'green' => (string) $c->responded_at?->format('H:i'),
                    'red' => '—',
                    'orange' => 'yo‘q',
                    default => '',
                };
                $color && $colors[3 + $j] = $color;
                if ($color === 'green') {
                    $count++;
                    $perSlot[$slot]++;
                }
            }
            $cells[] = "{$count}/".count($slots);
            $done += $count;
            $count === count($slots) && $count > 0 && $full++;
            $body[] = ['cells' => $cells, 'sum' => false, 'colors' => $colors];
        }

        $possible = $staff->count() * count($slots);
        $sum = ['cells' => ['', 'Jami', (string) $attendance->where('status', Attendance::PRESENT)->count(), ...array_map(fn ($s) => (string) $perSlot[$s], $slots), "{$done}/{$possible}"], 'sum' => true, 'colors' => []];

        $t = fn (string $s) => Script::apply($s);
        $png = Svod::table('Soatlik qayd — kun yakuni · '.$day->format('d.m.Y'),
            ['№', 'Xodim', 'Davomat', ...$slots, 'Jami'], [44, 230, 92, ...array_fill(0, count($slots), 74), 86], [$sum, ...$body], $t);
        $caption = "📊 <b>Soatlik qayd — kun yakuni</b> ({$day->format('d.m.Y')})\nQayd etildi: <b>{$done}</b> / {$possible}"
            ." · barcha soatlarda qayd etgan: <b>{$full}</b> / {$staff->count()}";

        return ['png' => $png, 'caption' => $caption, 'total' => $staff->count()];
    }

    /** Rasmni sozlamadagi oluvchilarga yuboradi. Yuborilganlar soni qaytadi. */
    public static function send(Nutgram $bot, string $key, array $report): int
    {
        if (! $report['total']) {
            return 0; // soatlik qayd yuritiladigan xodim yo'q
        }
        $file = tempnam(sys_get_temp_dir(), 'checkin');
        file_put_contents($file, $report['png']);
        $caption = Script::apply($report['caption']);
        $sent = 0;
        try {
            foreach (ReportSetting::recipients($key) as $to) {
                try {
                    $fh = fopen($file, 'r');
                    $bot->sendPhoto($fh, ['chat_id' => $to->telegram_id, 'caption' => $caption, 'parse_mode' => ParseMode::HTML]);
                    $sent++;
                } catch (\Throwable $e) {
                    report($e); // botni bloklagan bo'lsa — ErrorAlert 403 ni o'tkazib yuboradi
                }
            }
        } finally {
            @unlink($file);
        }

        return $sent;
    }
}
