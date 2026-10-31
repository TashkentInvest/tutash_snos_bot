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

    // jami (boshidan beri) holat — kunlik sonlar PeriodStats'da
    protected ?string $heading = 'Umumiy holat (jami)';

    protected function getStats(): array
    {
        $total = Site::countable()->count();
        $exempt = Site::where('status', Site::EXEMPT)->count();
        $demolished = Site::where('status', Site::DEMOLISHED)->count();
        $inProgress = Site::where('status', Site::IN_PROGRESS)->count();
        $suspicious = Inspection::counted()->where('suspicious', true)->count();
        $followDone = Inspection::where('followup', Inspection::FOLLOWUP_DONE)->count();
        $followOpen = Inspection::where('followup', Inspection::FOLLOWUP_OPEN)->count();
        $followAck = Inspection::where('followup', Inspection::FOLLOWUP_OPEN)->whereNotNull('followup_ack_at')->count();
        $awaiting =Inspection::where('status', Inspection::SUBMITTED)->where('review_status', Inspection::REVIEW_PENDING)->count();

        $sites = fn (array $filters = []) => SiteResource::getUrl('index', $filters ? ['tableFilters' => $filters] : []);
        $inspections = fn (array $filters) => InspectionResource::getUrl('index', ['tableFilters' => $filters]);

        return [
            Stat::make('Jami obyektlar', number_format($total, 0, '', ' '))->icon('fas-building')->url($sites())
                ->description($exempt ? "Sud qarori bekor qilingan {$exempt} ta obyekt hisobga olinmagan" : ''),
            Stat::make('Buzilgan', number_format($demolished, 0, '', ' '))->icon('fas-house-crack')
                ->description($total ? round($demolished / $total * 100, 1).'% bajarildi' : '')->color('success')
                ->url($sites(['status' => ['value' => Site::DEMOLISHED]])),
            Stat::make('Jarayonda', number_format($inProgress, 0, '', ' '))->icon('fas-person-digging')->color('info')
                ->description($total ? round($inProgress / $total * 100, 1).'% — buzish boshlangan' : '')
                ->url($sites(['status' => ['value' => Site::IN_PROGRESS]])),
            Stat::make('Buzilmagan', number_format($total - $demolished - $inProgress, 0, '', ' '))->icon('fas-house')->color('warning')
                ->url($sites(['status' => ['value' => Site::NOT_DEMOLISHED]])),
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
