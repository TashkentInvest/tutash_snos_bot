<?php

namespace App\Filament\Resources\SiteResource\RelationManagers;

use App\Filament\Resources\InspectionResource;
use App\Models\Inspection;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class InspectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inspections';

    protected static ?string $title = 'Tekshiruvlar tarixi';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('status', Inspection::SUBMITTED)->whereNull('archived_at')->with('staff'))
            ->columns([
                Tables\Columns\TextColumn::make('submitted_at')->label('Sana')->dateTime('d.m.Y H:i'),
                Tables\Columns\TextColumn::make('staff.full_name')->label('Xodim'),
                Tables\Columns\TextColumn::make('result')->label('Natija')->badge()
                    ->state(fn (Inspection $record) => InspectionResource::result($record))
                    ->color(fn (Inspection $record) => InspectionResource::resultColor($record)),
                Tables\Columns\TextColumn::make('reason')->label('Sabab')->limit(50)->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('open')->label('Ochish')->icon('heroicon-o-eye')
                    ->url(fn (Inspection $record) => InspectionResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('submitted_at', 'desc');
    }
}
