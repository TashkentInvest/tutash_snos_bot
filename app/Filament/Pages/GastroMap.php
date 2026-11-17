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
        $streets = GastroStreet::orderBy('name')->get(['id', 'district_id', 'name', 'line']);
        $resp = GastroResponsible::with('staff')->get()->keyBy('district_id');
        $districts = District::orderBy('name')->get(['id', 'name', 'boundary']);
        $radius = \App\Support\Limits::leaseRadius();

        // amaldagi shartnomalar (o'chirilganlar yo'q): xaritada nuqta (xodim turgan joy) va chizilgan hudud
        $contracts = \App\Models\LeaseContract::with('staff:id,full_name')->whereNotNull('docx_path')->orderBy('id')
            ->get(['id', 'district_id', 'gastro_street_id', 'staff_id', 'party', 'tenant_name', 'mfy', 'house', 'area_m2', 'tariff',
                'annual_amount', 'start_date', 'lat', 'lng', 'polygon', 'street_distance_m', 'created_at'])
            ->map(fn (\App\Models\LeaseContract $c) => [
                'id' => $c->id, 'd' => $c->district_id, 's' => $c->gastro_street_id,
                'p' => [$c->lat, $c->lng], 'poly' => $c->polygon ?? [],
                // holat: uzoqdan (cheklov o'chiq paytda, sinov) / yuridik / jismoniy
                'k' => (int) $c->street_distance_m > $radius ? 'remote' : $c->party,
                'name' => $c->tenant_name, 'addr' => trim($c->mfy.(filled($c->house) ? ', '.$c->house : '')),
                'area' => (float) $c->area_m2, 'monthly' => $c->tariff ? round($c->area_m2 * $c->tariff, 2) : null,
                'annual' => (float) $c->annual_amount, 'staff' => $c->staff?->full_name, 'at' => $c->created_at?->format('d.m.Y H:i'),
                'url' => \App\Filament\Resources\LeaseContractResource::getUrl('view', ['record' => $c->id]),
            ])->values();
        $perStreet = $contracts->countBy('s');

        return [
            'rows' => $districts->map(fn ($d) => [
                'name' => $d->name,
                'streets' => $streets->where('district_id', $d->id)->pluck('name')->unique()->values()->all(),
                'responsible' => ($resp[$d->id] ?? null)?->label(),
                'contracts' => $contracts->where('d', $d->id)->count(),
            ])->all(),
            'map' => [
                'districts' => $districts->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'rings' => $d->boundary ?? []])->all(),
                'streets' => $streets->map(fn ($s) => ['id' => $s->id, 'd' => $s->district_id, 'name' => $s->name, 'line' => $s->line,
                    'district' => $districts->firstWhere('id', $s->district_id)?->name, 'n' => (int) ($perStreet[$s->id] ?? 0)])->values()->all(),
                'contracts' => $contracts->all(),
            ],
        ];
    }
}
