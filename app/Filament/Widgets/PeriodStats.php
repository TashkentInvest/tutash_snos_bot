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
        // har bir karta — tekshiruvlar ro'yxati shu hisobot davri (18:00–18:00) va natija bo'yicha
        $url = fn (?string $result = null) => InspectionResource::getUrl('index', ['tableFilters' => ['period' => ['day' => $p->day->toDateString()]]
            + ($result ? ['result' => ['value' => $result]] : [])]);

        return [
            Stat::make('Tekshiruvlar', $s['inspections'])->icon('fas-clipboard-check')->url($url()),
            Stat::make('Buzilgan', $s['demolished'])->icon('fas-house-crack')->color('success')->url($url('demolished'))
                ->description($s['demolished'] ? "{$s['approved']} tasdiqlangan · {$s['pending']} tasdiq kutilmoqda" : null)
                ->descriptionIcon($s['pending'] ? 'fas-clock' : ($s['demolished'] ? 'fas-circle-check' : null)),
            Stat::make('Jarayonda', $s['progress'])->icon('fas-person-digging')->color('info')->url($url('in_progress')),
            Stat::make('Sud qarori bekor', $s['court'])->icon('fas-scale-balanced')->color('gray')->url($url('court_cancelled')),
            Stat::make('Oldingi holat qayd etildi', $s['before'])->icon('fas-camera')->color('gray')->url($url('before')),
        ];
    }
}
