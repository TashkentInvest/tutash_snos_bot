<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteResource\Pages;
use App\Filament\Resources\SiteResource\RelationManagers;
use App\Models\Mahalla;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $modelLabel = 'obyekt';

    protected static ?string $pluralModelLabel = 'Obyektlar';

    protected static ?int $navigationSort = 1;

    private const STATUS = [Site::NOT_DEMOLISHED => 'Buzilmagan', Site::IN_PROGRESS => 'Jarayonda', Site::DEMOLISHED => 'Buzilgan', Site::EXEMPT => 'Buzilmaydi (sud qarori bekor)'];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Joylashuv')->columns(2)->schema([
                Forms\Components\Select::make('district_id')->label('Tuman')->relationship('district', 'name')->required()->live(),
                Forms\Components\Select::make('mahalla_id')->label('MFY')
                    ->options(fn (Forms\Get $get) => Mahalla::where('district_id', $get('district_id'))->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                Forms\Components\TextInput::make('address')->label('Manzil (ko\'cha)')->required()->columnSpanFull(),
                Forms\Components\ViewField::make('map_picker')->hiddenLabel()->dehydrated(false)
                    ->view('filament.forms.site-map-picker')->columnSpanFull(),
                Forms\Components\TextInput::make('lat')->label('Kenglik (lat)')->numeric()->minValue(-90)->maxValue(90),
                Forms\Components\TextInput::make('lng')->label('Uzunlik (lng)')->numeric()->minValue(-180)->maxValue(180),
            ]),
            Forms\Components\Section::make('Huquqbuzar')->columns(2)->schema([
                Forms\Components\TextInput::make('violator_name')->label('F.I.SH / tashkilot'),
                Forms\Components\TextInput::make('violator_passport')->label('Pasport'),
                Forms\Components\TextInput::make('org_inn')->label('STIR'),
                Forms\Components\TextInput::make('area_m2')->label('Egallangan maydon (m²)')->numeric(),
            ]),
            Forms\Components\Section::make('Sud va holat')->columns(2)->schema([
                Forms\Components\Toggle::make('is_tutash')->label('Tutash'),
                Forms\Components\Select::make('status')->label('Holat')->options(self::STATUS)->required(),
                Forms\Components\DatePicker::make('court_date')->label('Sud qarori sanasi'),
                Forms\Components\TextInput::make('court_number')->label('Sud qarori raqami'),
                Forms\Components\TextInput::make('court_status')->label('Sud holati'),
                Forms\Components\DatePicker::make('demolished_at')->label('Buzilgan sana'),
            ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(3)->schema([
                Infolists\Components\TextEntry::make('district.name')->label('Tuman'),
                Infolists\Components\TextEntry::make('mahalla.name')->label('MFY'),
                Infolists\Components\TextEntry::make('address')->label('Manzil'),
                Infolists\Components\TextEntry::make('violator_name')->label('Huquqbuzar'),
                Infolists\Components\TextEntry::make('area_m2')->label('Maydon (m²)'),
                Infolists\Components\TextEntry::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state, Site $record) => $state !== Site::DEMOLISHED && $record->pendingReview ? 'Ko‘rib chiqilmoqda' : (self::STATUS[$state] ?? $state))
                    ->color(fn ($state, Site $record) => $state === Site::DEMOLISHED ? 'success' : ($record->pendingReview ? 'info' : ($state === Site::IN_PROGRESS ? 'primary' : 'warning'))),
                Infolists\Components\TextEntry::make('court_number')->label('Sud qarori'),
                Infolists\Components\TextEntry::make('court_date')->label('Sud sanasi')->date('d.m.Y'),
                Infolists\Components\TextEntry::make('last_inspected_at')->label('Oxirgi tekshiruv')->dateTime('d.m.Y H:i'),
                Infolists\Components\TextEntry::make('map')->label('Xarita')
                    ->state(fn (Site $record) => $record->lat ? 'Yandex Xaritada ochish' : '—')
                    ->url(fn (Site $record) => $record->lat ? \App\Support\Maps::url($record->lat, $record->lng) : null, true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['district', 'mahalla', 'pendingReview']))
            ->columns([
                Tables\Columns\TextColumn::make('district.name')->label('Tuman')->sortable(),
                Tables\Columns\TextColumn::make('mahalla.name')->label('MFY')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('address')->label('Manzil')->searchable()->limit(40)->tooltip(fn (Site $record) => $record->address),
                Tables\Columns\TextColumn::make('violator_name')->label('Huquqbuzar')->searchable()->limit(30),
                Tables\Columns\TextColumn::make('area_m2')->label('m²')->numeric(decimalPlaces: 0)->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state, Site $record) => $state !== Site::DEMOLISHED && $record->pendingReview ? 'Ko‘rib chiqilmoqda' : (self::STATUS[$state] ?? $state))
                    ->color(fn ($state, Site $record) => $state === Site::DEMOLISHED ? 'success' : ($record->pendingReview ? 'info' : ($state === Site::IN_PROGRESS ? 'primary' : 'warning'))),
                Tables\Columns\TextColumn::make('last_inspected_at')->label('Oxirgi tekshiruv')->dateTime('d.m.Y H:i')->sortable()->placeholder('Tekshirilmagan'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('district_id')->label('Tuman')->relationship('district', 'name'),
                Tables\Filters\SelectFilter::make('status')->label('Holat')->options(self::STATUS),
                Tables\Filters\Filter::make('in_review')->label('Ko‘rib chiqilmoqda')->toggle()->query(fn ($query) => $query->whereHas('pendingReview')),
                Tables\Filters\TernaryFilter::make('inspected')->label('Tekshirilgan')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('last_inspected_at'),
                        false: fn ($query) => $query->whereNull('last_inspected_at'),
                    ),
            ])
            ->actions([Tables\Actions\ViewAction::make(), Tables\Actions\EditAction::make()])
            ->defaultSort('id');
    }

    public static function getRelations(): array
    {
        return [RelationManagers\InspectionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'view' => Pages\ViewSite::route('/{record}'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}
