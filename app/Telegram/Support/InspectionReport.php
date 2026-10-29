<?php

namespace App\Telegram\Support;

use App\Models\Inspection;
use App\Models\Site;
use Carbon\Carbon;

/** Kunlik tekshiruvlar (buzilish) xulosasi: davomat hisoboti oluvchilariga botda yuboriladi. */
class InspectionReport
{
    /** @return string[] Telegram limitiga mos bo'laklar */
    public static function chunks(Carbon $day): array
    {
        // rad etilganlar (arxiv) hisobga olinmaydi
        $rows = Inspection::counted()->with(['site.district', 'site.mahalla', 'staff'])
            ->whereDate('submitted_at', $day)->orderBy('submitted_at')->get();

        $demolished = $rows->where('is_demolished', true);
        $approved = $demolished->where('review_status', Inspection::REVIEW_APPROVED)->count();
        $pending = $demolished->where('review_status', Inspection::REVIEW_PENDING)->count();
        $standing = $rows->where('is_demolished', false);

        $total = Site::count();
        $done = Site::where('status', Site::DEMOLISHED)->count();

        $lines = [
            '📊 <b>Kunlik hisobot (buzilish) — '.$day->format('d.m.Y').'</b>',
            "Tekshiruvlar: <b>{$rows->count()}</b> · 🏚 buzilgan: <b>{$demolished->count()}</b>"
                ." (✅ tasdiqlangan: {$approved}, ⏳ tasdiq kutilmoqda: {$pending}) · 🏠 buzilmagan: <b>{$standing->count()}</b>",
            "Umumiy bajarilish: <b>{$done}</b> / {$total}".($total ? ' ('.round($done / $total * 100, 1).'%)' : ''),
        ];

        if ($rows->isEmpty()) {
            $lines[] = "\nBugun tekshiruv natijasi kiritilmagan.";

            return AttendanceReport::split($lines);
        }

        $lines[] = "\n<b>Tumanlar kesimida</b>";
        foreach ($rows->groupBy(fn ($i) => $i->site->district->name)->sortKeys() as $district => $items) {
            $d = $items->where('is_demolished', true)->count();
            $lines[] = '• '.Ui::e($district).": 🏚 {$d} · 🏠 ".($items->count() - $d);
        }

        if ($demolished->isNotEmpty()) {
            $lines[] = "\n🏚 <b>Buzilgan obyektlar ({$demolished->count()})</b>";
            foreach ($demolished->values() as $i => $in) {
                $state = $in->review_status === Inspection::REVIEW_APPROVED ? '✅' : '⏳';
                $lines[] = ($i + 1).". {$state} ".self::where($in).' — '.Ui::e($in->staff?->full_name ?? '—').', '.$in->submitted_at->format('H:i')
                    .($in->suspicious ? ' · 🚩' : '');
            }
        }

        if ($standing->isNotEmpty()) {
            $lines[] = "\n🏠 <b>Buzilmagan ({$standing->count()})</b>";
            foreach ($standing->values() as $i => $in) {
                $lines[] = ($i + 1).'. '.self::where($in).' — <i>'.Ui::e(Ui::cut((string) $in->reason, 120)).'</i>';
            }
        }

        return AttendanceReport::split($lines);
    }

    private static function where(Inspection $in): string
    {
        $site = $in->site;

        return Ui::e(preg_replace('/\s+tumani$/u', '', $site->district->name)).', '
            .($site->mahalla ? Ui::e($site->mahalla->name).', ' : '')
            .'<b>'.Ui::e(Ui::cut($site->address, 50)).'</b>'.($site->source_no ? " (№{$site->source_no})" : '');
    }
}
