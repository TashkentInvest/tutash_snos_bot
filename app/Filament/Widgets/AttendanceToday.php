<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AttendanceResource;
use App\Models\Attendance;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AttendanceToday extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Bugungi davomat';

    protected function getStats(): array
    {
        $count = fn (array $statuses) => Attendance::whereDate('date', today())->whereIn('status', $statuses)->count();
        $total = Attendance::whereDate('date', today())->count();

        $today = ['from' => today()->toDateString(), 'to' => today()->toDateString()];
        $url = fn (?string $status = null) => AttendanceResource::getUrl('index', ['tableFilters' => ['date' => $today] + ($status ? ['status' => ['value' => $status]] : [])]);

        return [
            Stat::make('So‘rov yuborilgan', $total)->url($url()),
            Stat::make('Ishda', $count([Attendance::PRESENT]))->color('success')->url($url(Attendance::PRESENT)),
            Stat::make('Sabab bilan yo‘q', $count([Attendance::ABSENT]))->color('warning')->url($url(Attendance::ABSENT)),
            Stat::make('Javob bermagan', $count([Attendance::MISSED, ...Attendance::OPEN]))->color('danger')->url($url(Attendance::MISSED)),
        ];
    }
}
