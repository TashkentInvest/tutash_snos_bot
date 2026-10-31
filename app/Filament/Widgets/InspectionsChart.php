<?php

namespace App\Filament\Widgets;

use App\Models\Inspection;
use Filament\Widgets\ChartWidget;

class InspectionsChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = null; // grafik kunlik ma'lumot — qayta so'ralmaydi

    protected static ?string $heading = 'Oxirgi 14 kun tekshiruvlari';

    /**
     * Kunlar bo'yicha natijalar: "buzilgan" faqat moderator tasdiqlaganidan keyin hisoblanadi,
     * tasdiq kutayotganlari alohida, rad etilganlari (arxiv) umuman hisoblanmaydi.
     *
     * @return array{labels: string[], approved: int[], pending: int[], not_demolished: int[]}
     */
    public static function series(int $days = 14): array
    {
        $range = collect(range($days - 1, 0))->map(fn ($i) => today()->subDays($i));

        $rows = Inspection::counted()
            ->where('submitted_at', '>=', $range->first())
            ->get(['submitted_at', 'is_demolished', 'review_status'])
            ->groupBy(fn ($record) => $record->submitted_at->toDateString());

        $count = fn (callable $match) => $range->map(fn ($d) => $rows->get($d->toDateString(), collect())->filter($match)->count())->all();

        return [
            'labels' => $range->map(fn ($d) => $d->format('d.m'))->all(),
            'approved' => $count(fn ($r) => $r->is_demolished && $r->review_status === Inspection::REVIEW_APPROVED),
            'pending' => $count(fn ($r) => $r->is_demolished && $r->review_status === Inspection::REVIEW_PENDING),
            'not_demolished' => $count(fn ($r) => ! $r->is_demolished),
        ];
    }

    protected function getData(): array
    {
        $s = self::series();

        return [
            'datasets' => [
                ['label' => 'Buzilgan (tasdiqlangan)', 'data' => $s['approved'], 'backgroundColor' => '#16a34a'],
                ['label' => 'Tasdiq kutilmoqda', 'data' => $s['pending'], 'backgroundColor' => '#60a5fa'],
                ['label' => 'Buzilmagan', 'data' => $s['not_demolished'], 'backgroundColor' => '#f59e0b'],
            ],
            'labels' => $s['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
