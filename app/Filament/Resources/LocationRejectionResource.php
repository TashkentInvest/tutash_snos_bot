<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationRejectionResource\Pages;
use App\Models\LocationRejection;
use App\Models\User;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * «Xatolikka uchraganlar»: tekshiruv, davomat va soatlik qaydda joylashuvi qabul qilinmagan urinishlar — xodim qayerdan yubordi,
 * obyekt qayerda, orasi necha metr. Ichiga kirilsa ikkala nuqta xaritada.
 */
class LocationRejectionResource extends Resource
{
    protected static ?string $model = LocationRejection::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Xatolikka uchraganlar';

    protected static ?string $modelLabel = 'xatolik';

    protected static ?string $pluralModelLabel = 'Xatolikka uchraganlar';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return User::currentIsAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        return ($n = LocationRejection::whereDate('created_at', today())->count()) ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Bugun';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['staff', 'site.district', 'site.mahalla']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Vaqt')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('staff.full_name')->label('Xodim')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('source')->label('Qayerda')->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => LocationRejection::SOURCES[$state] ?? $state),
                Tables\Columns\TextColumn::make('site.district.name')->label('Tuman')->toggleable(),
                Tables\Columns\TextColumn::make('site.address')->label('Obyekt')->limit(40)->wrap()
                    ->description(fn (LocationRejection $r) => $r->note ?? $r->site?->mahalla?->name),
                Tables\Columns\TextColumn::make('reason')->label('Sabab')->badge()
                    ->formatStateUsing(fn ($state) => LocationRejection::REASONS[$state] ?? $state)
                    ->color(fn ($state) => $state === LocationRejection::LOW_ACCURACY ? 'warning' : 'danger'),
                Tables\Columns\TextColumn::make('distance_m')->label('Masofa')->sortable()->placeholder('—')
                    ->formatStateUsing(fn ($state) => self::meters($state))
                    ->color(fn ($state) => $state > config('tutash.max_distance_m') ? 'danger' : null),
                Tables\Columns\TextColumn::make('accuracy')->label('GPS aniqligi')->placeholder('—')
                    ->formatStateUsing(fn ($state) => '±'.self::meters($state))->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')->label('Qayerda')->options(LocationRejection::SOURCES),
                Tables\Filters\SelectFilter::make('reason')->label('Sabab')->options(LocationRejection::REASONS),
                Tables\Filters\SelectFilter::make('district')->label('Tuman')->relationship('site.district', 'name'),
                Tables\Filters\SelectFilter::make('staff_id')->label('Xodim')->relationship('staff', 'full_name')->searchable(),
                Tables\Filters\Filter::make('today')->label('Faqat bugun')->query(fn ($query) => $query->whereDate('created_at', today())),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Xaritada')])
            ->recordUrl(fn (LocationRejection $r) => self::getUrl('view', ['record' => $r]))
            ->defaultSort('created_at', 'desc');
    }

    public static function meters(int|float|null $m): string
    {
        if ($m === null) {
            return '—';
        }

        return $m >= 1000 ? number_format($m / 1000, 1, ',', ' ').' km' : round($m).' m';
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('staff.full_name')->label('Xodim'),
                Infolists\Components\TextEntry::make('source')->label('Qayerda')->formatStateUsing(fn ($state) => LocationRejection::SOURCES[$state] ?? $state),
                Infolists\Components\TextEntry::make('created_at')->label('Vaqt')->dateTime('d.m.Y H:i:s'),
                Infolists\Components\TextEntry::make('reason')->label('Sabab')->badge()
                    ->formatStateUsing(fn ($state) => LocationRejection::REASONS[$state] ?? $state)
                    ->color(fn ($state) => $state === LocationRejection::LOW_ACCURACY ? 'warning' : 'danger'),
                Infolists\Components\TextEntry::make('note')->label('Izoh')->columnSpanFull()->color('danger')->hidden(fn ($state) => blank($state)),
                Infolists\Components\TextEntry::make('distance_m')->label(fn (LocationRejection $r) => $r->source === 'inspection' ? 'Obyektgacha' : 'Eng yaqin obyektgacha')
                    ->formatStateUsing(fn ($state) => self::meters($state).' (ruxsat: '.config('tutash.max_distance_m').' m)')->placeholder('—'),
                Infolists\Components\TextEntry::make('site.address')->label(fn (LocationRejection $r) => $r->source === 'inspection' ? 'Obyekt' : 'Eng yaqin obyekt (o‘z tumanida)')->columnSpan(2)
                    ->formatStateUsing(fn ($state, LocationRejection $r) => collect([$r->site?->district?->name, $r->site?->mahalla?->name, $state])->filter()->join(' · '))
                    ->url(fn (LocationRejection $r) => $r->site_id ? SiteResource::getUrl('view', ['record' => $r->site_id]) : null),
                Infolists\Components\TextEntry::make('accuracy')->label('GPS aniqligi')->formatStateUsing(fn ($state) => '±'.self::meters($state))->placeholder('—'),
                Infolists\Components\TextEntry::make('attempts')->label('Shu xodim, bugun')
                    ->state(fn (LocationRejection $r) => LocationRejection::where('staff_id', $r->staff_id)->where('source', $r->source)
                        ->when($r->source === 'inspection', fn ($q) => $q->where('site_id', $r->site_id))->whereDate('created_at', $r->created_at)->count().' marta'),
                Infolists\Components\TextEntry::make('lat')->label('Xodim yuborgan nuqta')
                    ->formatStateUsing(fn ($state, LocationRejection $r) => round($r->lat, 6).', '.round($r->lng, 6))
                    ->url(fn (LocationRejection $r) => \App\Support\Maps::url($r->lat, $r->lng), true)->color('danger'),
                Infolists\Components\TextEntry::make('site_lat')->label('Bizdagi obyekt nuqtasi')->placeholder('Obyektda koordinata yo‘q')
                    ->formatStateUsing(fn ($state, LocationRejection $r) => round($r->site_lat, 6).', '.round($r->site_lng, 6))
                    ->url(fn (LocationRejection $r) => $r->site_lat ? \App\Support\Maps::url($r->site_lat, $r->site_lng) : null, true)->color('primary'),
                Infolists\Components\TextEntry::make('device')->label('Qurilma')->columnSpan(2)->placeholder('—')->limit(120),
            ]),
            Infolists\Components\ViewEntry::make('map')->hiddenLabel()->columnSpanFull()
                ->view('filament.location-rejection-map'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocationRejections::route('/'),
            'view' => Pages\ViewLocationRejection::route('/{record}'),
        ];
    }
}
