<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DistrictResource\Pages;
use App\Models\District;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DistrictResource extends Resource
{
    protected static ?string $model = District::class;

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
                Tables\Columns\TextColumn::make('sites_count')->label('Obyektlar')->counts('sites'),
                Tables\Columns\TextColumn::make('staff_count')->label('Xodimlar')->counts('staff'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDistricts::route('/')];
    }
}
