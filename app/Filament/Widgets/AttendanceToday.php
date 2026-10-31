<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AttendanceResource;
use App\Models\Attendance;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AttendanceToday extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return \App\Models\User::currentIsAdmin();
    }

    protected static ?int $sort = 1;

    // standart 5 soniya o'rniga: sust aloqada sahifa og'irlashmasin
    protected static ?string $pollingInterval = '60s';

    private function day(): \Carbon\CarbonImmutable
    {
        return \App\Filament\Pages\Dashboard::attendanceDay(\App\Support\ReportPeriod::fromFilter($this->filters['day'] ?? null));
    }

    protected function getHeading(): ?string
    {
        return $this->day()->isToday() ? 'Bugungi davomat' : 'Davomat — '.$this->day()->format('d.m.Y');
    }

    protected function getStats(): array
    {
        $day = $this->day()->toDateString();
        $count = fn (array $statuses) => Attendance::whereDate('date', $day)->whereIn('status', $statuses)->count();
        $total = Attendance::whereDate('date', $day)->count();

        $today = ['from' => $day, 'to' => $day];
        $url = fn (?string $status = null) => AttendanceResource::getUrl('index', ['tableFilters' => ['date' => $today] + ($status ? ['status' => ['value' => $status]] : [])]);

        return [
            Stat::make('So‘rov yuborilgan', $total)->url($url()),
            Stat::make('Ishda', $count([Attendance::PRESENT]))->color('success')->url($url(Attendance::PRESENT)),
            Stat::make('Sabab bilan yo‘q', $count([Attendance::ABSENT]))->color('warning')->url($url(Attendance::ABSENT)),
            Stat::make('Javob bermagan', $count([Attendance::MISSED, ...Attendance::OPEN]))->color('danger')->url($url(Attendance::MISSED)),
        ];
    }
}
