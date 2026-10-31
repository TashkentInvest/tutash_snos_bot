<?php

namespace App\Filament\Resources\InspectionResource\Pages;

use App\Filament\Resources\InspectionResource;
use App\Models\Inspection;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListInspections extends ListRecords
{
    protected static string $resource = InspectionResource::class;

    protected function getHeaderActions(): array
    {
        return [\App\Filament\Pages\Dashboard::remindAllAction()];
    }

    public function getTabs(): array
    {
        $pending = fn () => Inspection::where('status', Inspection::SUBMITTED)->where('review_status', Inspection::REVIEW_PENDING)->count();

        return [
            'all' => Tab::make('Barchasi'),
            'pending' => Tab::make('Tasdiqlanmaganlar')->badge($pending() ?: null)->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('review_status', Inspection::REVIEW_PENDING)),
        ];
    }

}
