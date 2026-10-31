<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\InspectionResource;
use App\Support\ReportPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Tanlangan kun natijasi (hisobot davri: oldingi kun 18:00 dan shu kun 18:00 gacha) — 18:00 hisobotidagi sonlar bilan bir xil.
 * Joriy davr har kuni 18:00 da 0 dan boshlanadi.
 */
class PeriodStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 0;

    protected static ?string $pollingInterval = '60s';

    private function period(): ReportPeriod
    {
        return ReportPeriod::fromFilter($this->filters['day'] ?? null);
    }

    protected function getHeading(): ?string
    {
        $p = $this->period();

        return 'Kunlik natija — '.$p->day->format('d.m.Y').($p->isOpen() ? ' (joriy)' : '');
    }

    protected function getDescription(): ?string
    {
        return 'Davr: '.$this->period()->label();
    }

    protected function getStats(): array
    {
        $p = $this->period();
        $s = $p->summary();
        $url = InspectionResource::getUrl('index', ['tableFilters' => ['date' => ['from' => $p->from->toDateString(), 'to' => $p->to->toDateString()]]]);

        return [
            Stat::make('Tekshiruvlar', $s['inspections'])->icon('fas-clipboard-check')->url($url),
            Stat::make('Buzilgan', $s['demolished'])->icon('fas-house-crack')->color('success')
                ->description($s['demolished'] ? "{$s['approved']} tasdiqlangan · {$s['pending']} tasdiq kutilmoqda" : null)
                ->descriptionIcon($s['pending'] ? 'fas-clock' : ($s['demolished'] ? 'fas-circle-check' : null)),
            Stat::make('Jarayonda', $s['progress'])->icon('fas-person-digging')->color('info'),
            Stat::make('Sud qarori bekor', $s['court'])->icon('fas-scale-balanced')->color('gray'),
            Stat::make('Buzilmagan (sabab bilan)', $s['other'])->icon('fas-house')->color('warning'),
        ];
    }
}
