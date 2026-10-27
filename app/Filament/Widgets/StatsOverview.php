<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\InspectionResource;
use App\Filament\Resources\SiteResource;
use App\Models\Inspection;
use App\Models\Site;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Umumiy holat';

    protected function getStats(): array
    {
        $total = Site::count();
        $demolished = Site::where('status', Site::DEMOLISHED)->count();
        $today = Inspection::counted()->whereDate('submitted_at', today())->count();
        $suspicious = Inspection::counted()->where('suspicious', true)->count();
        $awaiting = Inspection::where('status', Inspection::SUBMITTED)->where('review_status', Inspection::REVIEW_PENDING)->count();

        $sites = fn (array $filters = []) => SiteResource::getUrl('index', $filters ? ['tableFilters' => $filters] : []);
        $inspections = fn (array $filters) => InspectionResource::getUrl('index', ['tableFilters' => $filters]);

        return [
            Stat::make('Jami obyektlar', number_format($total, 0, '', ' '))->url($sites()),
            Stat::make('Buzilgan', number_format($demolished, 0, '', ' '))
                ->description($total ? round($demolished / $total * 100, 1).'% bajarildi' : '')->color('success')
                ->url($sites(['status' => ['value' => Site::DEMOLISHED]])),
            Stat::make('Buzilmagan', number_format($total - $demolished, 0, '', ' '))->color('warning')
                ->url($sites(['status' => ['value' => Site::NOT_DEMOLISHED]])),
            Stat::make('Tasdiqlanmagan', $awaiting)->color($awaiting ? 'danger' : 'gray')
                ->description('Moderator tasdig‘ini kutayotgan «buzilgan» natijalar')
                ->url($inspections(['review_status' => ['value' => Inspection::REVIEW_PENDING]])),
            Stat::make('Bugungi tekshiruvlar', $today)
                ->url($inspections(['date' => ['from' => today()->toDateString(), 'to' => today()->toDateString()]])),
            Stat::make('Shubhali tekshiruvlar', $suspicious)->color($suspicious ? 'danger' : 'gray')
                ->description('Obyektdan uzoqda yoki takroriy fayl')
                ->url($inspections(['suspicious' => ['value' => 1]])),
        ];
    }
}
