<?php

namespace App\Filament\Pages;

use App\Models\District;
use App\Models\GastroResponsible;
use App\Models\GastroStreet;
use App\Models\User;
use Filament\Pages\Page;

/** Gastronomik ko'chalar → «Ko'chalar xaritasi»: KMZ dagi gastronomik va turizm ko'chalari tumanlar chegarasi ustida. */
class GastroMap extends Page
{
    protected static ?string $navigationGroup = 'Gastronomik ko‘chalar';

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Ko‘chalar xaritasi';

    protected static ?string $title = 'Gastronomik va turizm ko‘chalari';

    protected static ?string $slug = 'gastro-map';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.gastro-map';

    public static function canAccess(): bool
    {
        return User::currentIsAdmin();
    }

    protected function getViewData(): array
    {
        $streets = GastroStreet::orderBy('name')->get(['district_id', 'name', 'line'])->groupBy('district_id');
        $resp = GastroResponsible::with('staff')->get()->keyBy('district_id');
        $districts = District::orderBy('name')->get(['id', 'name', 'boundary']);

        return [
            'rows' => $districts->map(fn ($d) => [
                'name' => $d->name,
                'streets' => ($streets[$d->id] ?? collect())->pluck('name')->unique()->values()->all(),
                'responsible' => $resp[$d->id]?->staff?->full_name ?? ($resp[$d->id]?->phone ? $resp[$d->id]->phone.' (botga kirmagan)' : null),
            ])->all(),
            'map' => [
                'districts' => $districts->map(fn ($d) => ['name' => $d->name, 'rings' => $d->boundary ?? []])->all(),
                'streets' => $streets->flatten(1)->map(fn ($s) => ['name' => $s->name, 'line' => $s->line,
                    'district' => $districts->firstWhere('id', $s->district_id)?->name])->values()->all(),
            ],
        ];
    }
}
