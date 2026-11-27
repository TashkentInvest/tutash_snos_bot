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

    // standart 5 soniya o'rniga: sust aloqada sahifa og'irlashmasin
    protected static ?string $pollingInterval = '60s';

    // nazorat: tasdiq kutayotganlar, moderator izohlari, shubhali tekshiruvlar (jami / buzilgan / qolgan — «Overview» kartochkalarida)
    protected ?string $heading = 'Nazorat';

    protected function getStats(): array
    {
        $suspicious = Inspection::counted()->where('suspicious', true)->count();
        $followDone = Inspection::where('followup', Inspection::FOLLOWUP_DONE)->count();
        $followOpen = Inspection::where('followup', Inspection::FOLLOWUP_OPEN)->count();
        $followAck = Inspection::where('followup', Inspection::FOLLOWUP_OPEN)->whereNotNull('followup_ack_at')->count();
        $awaiting = Inspection::where('status', Inspection::SUBMITTED)->where('review_status', Inspection::REVIEW_PENDING)->count();

        $inspections = fn (array $filters) => InspectionResource::getUrl('index', ['tableFilters' => $filters]);

        return [
            Stat::make('Tasdiqlanmagan', $awaiting)->icon('fas-hourglass-half')->color($awaiting ? 'danger' : 'gray')
                ->description('Moderator tasdig‘ini kutayotgan natijalar (buzilgan / sud qarori bekor)')
                ->url($inspections(['review_status' => ['value' => Inspection::REVIEW_PENDING]])),
            Stat::make('Moderator izohlari', $followDone)->icon('fas-comment-dots')->color($followDone ? 'danger' : 'gray')
                ->description('Tekshirish kerak · bajarilmagan: '.$followOpen.($followOpen ? " (tanishgan: {$followAck}, javobsiz: ".($followOpen - $followAck).')' : ''))
                ->url($inspections(['followup' => ['value' => $followDone || ! $followOpen ? Inspection::FOLLOWUP_DONE : Inspection::FOLLOWUP_OPEN]])),
            Stat::make('Shubhali tekshiruvlar', $suspicious)->icon('fas-triangle-exclamation')->color($suspicious ? 'danger' : 'gray')
                ->description('Obyektdan uzoqda yoki takroriy fayl')
                ->url($inspections(['suspicious' => ['value' => 1]])),
        ];
    }
}
