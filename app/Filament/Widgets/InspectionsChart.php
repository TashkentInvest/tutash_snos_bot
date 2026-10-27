<?php

namespace App\Filament\Widgets;

use App\Models\Inspection;
use Filament\Widgets\ChartWidget;

class InspectionsChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Oxirgi 14 kun tekshiruvlari';

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn ($i) => today()->subDays($i));

        $rows = Inspection::where('status', Inspection::SUBMITTED)
            ->where('submitted_at', '>=', $days->first())
            ->get(['submitted_at', 'is_demolished'])
            ->groupBy(fn ($record) => $record->submitted_at->toDateString());

        $count = fn (bool $demolished) => $days->map(fn ($d) => $rows->get($d->toDateString(), collect())->where('is_demolished', $demolished)->count())->all();

        return [
            'datasets' => [
                ['label' => 'Buzilgan', 'data' => $count(true), 'backgroundColor' => '#16a34a'],
                ['label' => 'Buzilmagan', 'data' => $count(false), 'backgroundColor' => '#f59e0b'],
            ],
            'labels' => $days->map(fn ($d) => $d->format('d.m'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
