<?php

namespace App\Telegram\Support;

use App\Models\Attendance;
use App\Models\Staff;
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

        $lines = [
            '📋 <b>Davomat xulosasi — '.$day->format('d.m.Y').'</b>',
            "Jami: <b>{$rows->count()}</b> · ✅ ishda: <b>{$present->count()}</b> · ⚠️ sabab bilan yo‘q: <b>{$absent->count()}</b> · ❌ javob bermadi: <b>{$missed->count()}</b>",
        ];

        if ($present->isNotEmpty()) {
            $lines[] = "\n✅ <b>Ishda ({$present->count()})</b>";
            foreach ($present as $i => $a) {
                $map = $a->lat ? ' · <a href="'.\App\Support\Maps::url($a->lat, $a->lng).'">xaritada</a>' : '';
                $lines[] = ($i + 1).'. '.$who($a).' — '.$a->responded_at?->format('H:i').$map;
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

        // Qatorlarni 4096 belgidan oshmaydigan bo'laklarga ajratish
        $chunks = [];
        $current = '';
        foreach ($lines as $line) {
            if (mb_strlen($current) + mb_strlen($line) + 1 > 3800) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : "\n").$line;
        }
        $chunks[] = $current;

        return $chunks;
    }

    /** Barcha tumanlarni ko'radigan faol adminlarga yuboradi; yuborilgan xabarlar soni qaytadi. */
    public static function sendToAdmins(Nutgram $bot, Carbon $day): int
    {
        $chunks = self::chunks($day);
        $sent = 0;

        foreach (Staff::where('status', Staff::ACTIVE)->where('is_global', true)->get() as $admin) {
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
}
