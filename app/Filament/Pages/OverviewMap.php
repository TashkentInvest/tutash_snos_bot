<?php

namespace App\Filament\Pages;

use App\Models\District;
use App\Models\Staff;
use App\Models\User;
use App\Support\MapLayers;
use Filament\Pages\Page;

/**
 * «Umumiy xarita»: bitta xaritada qatlamlar — gastronomik ko'chalar va shartnomalar (standart yoqilgan), obyektlar, davomat,
 * soatlik qayd, tekshiruvlar, xatolikka uchraganlar. Har biri belgi bilan yoqiladi, shaffofligi sozlanadi; filtr — tuman, kun, xodim.
 */
class OverviewMap extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-globe-asia-australia';

    protected static ?string $navigationLabel = 'Umumiy xarita';

    protected static ?string $title = 'Umumiy xarita';

    protected static ?string $slug = 'map';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.overview-map';

    public static function canAccess(): bool
    {
        return User::currentIsAdmin();
    }

    protected function getViewData(): array
    {
        return ['cfg' => [
            'layers' => collect(MapLayers::catalog())->map(fn ($m, $k) => ['key' => $k, 'name' => $m[0], 'states' => $m[1], 'on' => $k === 'gastro'])->values(),
            'districts' => District::orderBy('name')->get(['id', 'name', 'boundary'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'rings' => $d->boundary ?? []]),
            'staff' => Staff::where('status', Staff::ACTIVE)->whereNotNull('telegram_id')->orderBy('full_name')->get(['id', 'full_name', 'phone'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->full_name ?: $s->phone]),
            'url' => url('/admin-map'),
            'today' => today()->toDateString(),
        ]];
    }
}
