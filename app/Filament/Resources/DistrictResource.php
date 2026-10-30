<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DistrictResource\Pages;
use App\Models\District;
use Filament\Forms;
use Filament\Forms\Form;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DistrictResource extends Resource
{
    protected static ?string $model = District::class;

    // Kuzatuvchi (is_viewer) bu bo'limni ko'rmaydi — faqat admin.
    public static function canViewAny(): bool
    {
        return User::currentIsAdmin();
    }

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Sozlamalar';

    protected static ?string $modelLabel = 'tuman';

    protected static ?string $pluralModelLabel = 'Tumanlar';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Tuman nomi')->required()->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('responsible_name')->label('Mas’ul xodim F.I.SH')->maxLength(100),
            Forms\Components\TextInput::make('phone')->label('Mas\'ul xodim telefoni (9 raqam)')
                ->helperText('Masalan: 992012272. Xodim shu raqam bilan botga kirganda tumanga biriktiriladi.')
                ->regex('/^\d{9}$/')->length(9),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Tuman')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('responsible_name')->label('Mas’ul xodim')->placeholder('—'),
                Tables\Columns\TextColumn::make('phone')->label('Telefon'),
                Tables\Columns\TextColumn::make('responsibleStaff.full_name')->label('Botda')->badge()
                    ->state(fn (District $record) => $record->responsibleStaff
                        ? '✅ '.$record->responsibleStaff->full_name
                        : ($record->phone ? '❌ Ro‘yxatdan o‘tmagan' : 'Telefon kiritilmagan'))
                    ->color(fn (District $record) => $record->responsibleStaff ? 'success' : 'danger')
                    ->tooltip(fn (District $record) => $record->responsibleStaff?->registered_at
                        ? 'Ro‘yxatdan o‘tgan: '.$record->responsibleStaff->registered_at->format('d.m.Y H:i') : null),
                Tables\Columns\TextColumn::make('sites_count')->label('Obyektlar')->counts('sites'),
                Tables\Columns\TextColumn::make('staff_count')->label('Xodimlar')->counts('staff'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('responsibleStaff'))
            ->filters([
                Tables\Filters\TernaryFilter::make('registered')->label('Mas’ul botda')
                    ->trueLabel('Ro‘yxatdan o‘tgan')->falseLabel('Ro‘yxatdan o‘tmagan')
                    ->queries(
                        true: fn ($query) => $query->whereHas('responsibleStaff'),
                        false: fn ($query) => $query->unregistered(),
                    ),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('name');
    }

    /** Menyuda: mas'uli hali botda ro'yxatdan o'tmagan tumanlar soni. */
    public static function getNavigationBadge(): ?string
    {
        return ($n = District::unregistered()->count()) ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Mas’uli botda ro‘yxatdan o‘tmagan tumanlar';
    }

    /** "Ro'yxatdan o'tmagan" filtri yoqilgan ro'yxat (yuqori paneldagi ogohlantirishdan). */
    public static function unregisteredUrl(): string
    {
        return self::getUrl('index', ['tableFilters' => ['registered' => ['value' => 0]]]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDistricts::route('/')];
    }
}
