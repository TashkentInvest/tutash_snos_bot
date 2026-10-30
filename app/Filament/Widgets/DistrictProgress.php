<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SiteResource;
use App\Models\District;
use App\Models\Site;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class DistrictProgress extends BaseWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Tumanlar kesimida';

    protected int|string|array $columnSpan = 'full';

    private static function sitesUrl(District $district, ?string $status = null): string
    {
        return SiteResource::getUrl('index', ['tableFilters' => ['district_id' => ['value' => $district->id]] + ($status ? ['status' => ['value' => $status]] : [])]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(District::query()
                ->withCount(['sites' => fn ($q) => $q->countable(), 'sites as demolished_count' => fn ($q) => $q->where('status', 'demolished')]))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Tuman')->sortable()->url(fn (District $record) => self::sitesUrl($record)),
                Tables\Columns\TextColumn::make('sites_count')->label('Jami')->sortable()->url(fn (District $record) => self::sitesUrl($record)),
                Tables\Columns\TextColumn::make('demolished_count')->label('Buzilgan')->sortable()->color('success')->url(fn (District $record) => self::sitesUrl($record, Site::DEMOLISHED)),
                Tables\Columns\TextColumn::make('left')->label('Qolgan')
                    ->state(fn (District $record) => $record->sites_count - $record->demolished_count)->color('warning')
                    ->url(fn (District $record) => self::sitesUrl($record, Site::NOT_DEMOLISHED)),
                Tables\Columns\TextColumn::make('percent')->label('Bajarilish')
                    ->state(fn (District $record) => $record->sites_count ? round($record->demolished_count / $record->sites_count * 100, 1).'%' : '—'),
            ])
            ->recordUrl(fn (District $record) => self::sitesUrl($record))
            ->paginated(false)
            ->defaultSort('name');
    }
}
