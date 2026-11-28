<?php

namespace App\Filament\Resources\Dizayn\InspectionResource\Pages;

use App\Filament\Resources\Dizayn\InspectionResource;
use App\Models\Dizayn\Inspection;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListInspections extends ListRecords
{
    protected static string $resource = InspectionResource::class;

    public function getSubheading(): ?string
    {
        return 'Tuman xodimlari Dizayn-kod boti (Mini App) orqali yuborgan dalillar. Qaror xodimga bot orqali boradi.';
    }

    /** Bosqichlar: avval moderator kutayotganlar, keyin «Jarayonli tasdiqlandi», tasdiqlangan, rad etilgan. */
    public function getTabs(): array
    {
        $count = fn (array $reviews) => Inspection::whereIn('review', $reviews)->count() ?: null;
        $tab = fn (string $label, array $reviews) => Tab::make($label)->modifyQueryUsing(fn (Builder $query) => $query->whereIn('review', $reviews));

        return [
            'open' => $tab('Tekshiruv kutmoqda', Inspection::OPEN)->badge($count(Inspection::OPEN))->badgeColor('warning'),
            'process' => $tab(Inspection::REVIEWS[Inspection::CONFIRMING], [Inspection::CONFIRMING])->badge($count([Inspection::CONFIRMING]))->badgeColor('primary'),
            'approved' => $tab(Inspection::REVIEWS[Inspection::APPROVED], [Inspection::APPROVED]),
            'rejected' => $tab(Inspection::REVIEWS[Inspection::REJECTED], [Inspection::REJECTED]),
            'all' => Tab::make('Hammasi'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }
}
