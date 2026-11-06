<?php

namespace App\Telegram\Support;

use App\Models\Attendance;
use App\Models\Staff;
use App\Support\Gps;
use Carbon\Carbon;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

/** Kunlik davomat xulosasi: adminlar uchun (bot xabari). */
class AttendanceReport
{
    /** @return string[] Telegram 4096 belgi limitiga mos bo'laklar */
    public static function chunks(Carbon $day): array
    {
        $rows = Attendance::with('staff.districts')->whereDate('date', $day)->get()
            ->sortBy(fn ($a) => $a->staff?->full_name)->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $of = fn (string ...$st) => $rows->whereIn('status', $st)->values();
        $present = $of(Attendance::PRESENT);
        $absent = $of(Attendance::ABSENT);
        $missed = $of(Attendance::MISSED, ...Attendance::OPEN);

        $who = fn (Attendance $a) => '<b>'.Ui::e($a->staff?->full_name ?: '—').'</b>'
            .($a->staff?->districts->isNotEmpty() ? ' ('.Ui::e($a->staff->districts->pluck('name')->join(', ')).')' : '');

        // Joylashuv bo'yicha shubhali belgilar (hududdan uzoq, soxta GPS gumoni) — "Xabar yetkazilmadi" bu yerga kirmaydi.
        $warnings = fn (Attendance $a) => collect($a->flags ?? [])->reject(fn ($f) => $f === 'undeliverable')
            ->map(fn ($f) => Gps::flagLabel($f))->values();
        $suspicious = $present->filter(fn ($a) => $warnings($a)->isNotEmpty())->count();

        $lines = [
            '📋 <b>Davomat xulosasi — '.$day->format('d.m.Y').'</b>',
            "Jami: <b>{$rows->count()}</b> · ✅ ishda: <b>{$present->count()}</b> · ⚠️ sabab bilan yo‘q: <b>{$absent->count()}</b> · ❌ javob bermadi: <b>{$missed->count()}</b>"
                .($suspicious ? " · 🚩 shubhali: <b>{$suspicious}</b>" : ''),
        ];

        if ($present->isNotEmpty()) {
            $lines[] = "\n✅ <b>Ishda ({$present->count()})</b>";
            foreach ($present as $i => $a) {
                $map = $a->lat ? ' · <a href="'.\App\Support\Maps::url($a->lat, $a->lng).'">xaritada</a>' : '';
                $flags = $warnings($a)->isNotEmpty() ? ' · 🚩 <i>'.Ui::e($warnings($a)->join(', ')).'</i>' : '';
                $lines[] = ($i + 1).'. '.$who($a).' — '.$a->responded_at?->format('H:i').$map.$flags;
            }
        }
        if ($absent->isNotEmpty()) {
            $lines[] = "\n⚠️ <b>Sabab bilan yo‘q ({$absent->count()})</b>";
            foreach ($absent as $i => $a) {
                $lines[] = ($i + 1).'. '.$who($a).' — '.$a->responded_at?->format('H:i').'. Sabab: <i>'.Ui::e(Ui::cut($a->reason, 200)).'</i>';
            }
        }
        if ($missed->isNotEmpty()) {
            $lines[] = "\n❌ <b>Javob bermadi ({$missed->count()})</b>";
            foreach ($missed as $i => $a) {
                $lines[] = ($i + 1).'. '.$who($a);
            }
        }

        return self::split($lines);
    }

    /** Qatorlarni Telegram 4096 belgi limitidan oshmaydigan bo'laklarga ajratadi (boshqa hisobotlar uchun ham). */
    public static function split(array $lines): array
    {
        $chunks = [];
        $current = '';
        foreach ($lines as $line) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($line) + 1 > 3800) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : "\n").$line;
        }
        $chunks[] = $current;

        return $chunks;
    }

    /**
     * Davomat hisoboti rasm sifatida (uzun matn o'rniga): №, xodim, holat, vaqt, izoh/sabab, belgilar.
     *
     * @return array{png: string, caption: string, total: int}
     */
    public static function image(Carbon $day): array
    {
        $rows = Attendance::with('staff')->whereDate('date', $day)->get()->sortBy(fn ($a) => $a->staff?->full_name)->values();
        $count = fn (string ...$st) => $rows->whereIn('status', $st)->count();
        $present = $count(Attendance::PRESENT);
        $absent = $count(Attendance::ABSENT);
        $missed = $count(Attendance::MISSED, ...Attendance::OPEN);

        $table = [['cells' => ['', 'Jami', "Ishda: {$present} / {$rows->count()}", '', '', ''], 'sum' => true, 'colors' => []]];
        foreach ($rows as $i => $a) {
            [$label, $color] = match ($a->status) {
                Attendance::PRESENT => ['Ishda', 'green'],
                Attendance::ABSENT => ['Sabab bilan yo‘q', 'orange'],
                Attendance::MISSED => [in_array('undeliverable', $a->flags ?? [], true) ? 'Xabar yetmadi' : 'Javob bermadi', 'red'],
                default => ['Kutilmoqda', null],
            };
            $note = $a->status === Attendance::ABSENT ? $a->reason : $a->comment;
            $flags = collect($a->flags ?? [])->reject(fn ($f) => $f === 'undeliverable')->map(fn ($f) => Gps::flagLabel($f))->join(', ');
            $table[] = [
                'cells' => [(string) ($i + 1), \Illuminate\Support\Str::limit((string) $a->staff?->full_name, 30), $label,
                    in_array($a->status, [Attendance::PRESENT, Attendance::ABSENT], true) ? (string) $a->responded_at?->format('H:i') : '',
                    \Illuminate\Support\Str::limit((string) $note, 40), \Illuminate\Support\Str::limit($flags, 24)],
                'sum' => false,
                'colors' => array_filter([2 => $color, 5 => $flags !== '' ? 'orange' : null]),
            ];
        }

        $t = fn (string $s) => Script::apply($s);
        $png = \App\Support\Svod::table('Ertalabki davomat — '.$day->format('d.m.Y'), ['№', 'Xodim', 'Holat', 'Vaqt', 'Izoh / sabab', 'Belgi'],
            [44, 250, 190, 76, 330, 190], $table, $t);
        $caption = '📋 <b>Davomat — '.$day->format('d.m.Y')."</b>\n✅ Ishda: <b>{$present}</b> / {$rows->count()}"
            .($absent ? " · ⚠️ Sabab bilan: <b>{$absent}</b>" : '').($missed ? " · ❌ Javob bermadi: <b>{$missed}</b>" : '');

        return ['png' => $png, 'caption' => $caption, 'total' => $rows->count()];
    }

    /** Davomat hisobotini (rasm) oluvchilarga ($to yoki sozlamadagilar) yuboradi; yuborilganlar soni qaytadi. */
    public static function sendToAdmins(Nutgram $bot, Carbon $day, ?\Illuminate\Support\Collection $to = null): int
    {
        $report = self::image($day);

        return CheckinReport::send($bot, \App\Models\ReportSetting::ATTENDANCE, $report, $to);
    }

    /** Hisobot bo'laklarini oluvchilarga ($to yoki sozlamadagilar) yuboradi; yuborilgan xabarlar soni qaytadi. */
    public static function send(Nutgram $bot, array $chunks, ?\Illuminate\Support\Collection $to = null): int
    {
        $sent = 0;

        foreach ($to ?? self::recipients() as $admin) {
            foreach ($chunks as $text) {
                try {
                    $bot->sendMessage(Script::apply($text), [
                        'chat_id' => $admin->telegram_id,
                        'parse_mode' => ParseMode::HTML,
                        'disable_web_page_preview' => true,
                    ]);
                    $sent++;
                } catch (\Throwable) {
                    break; // admin botni bloklagan bo'lishi mumkin
                }
            }
        }

        return $sent;
    }

    /** Hisobot oluvchilar: Sozlamalar → Hisobotlar sahifasida shu hisobot uchun belgilanganlar. */
    public static function recipients(string $report = \App\Models\ReportSetting::ATTENDANCE): \Illuminate\Support\Collection
    {
        return \App\Models\ReportSetting::recipients($report);
    }
}
