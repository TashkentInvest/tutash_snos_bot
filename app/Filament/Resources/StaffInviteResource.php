<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffInviteResource\Pages;
use App\Models\District;
use App\Models\StaffInvite;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Admin oldindan qo'shgan xodimlar (Xodimlar → «Xodim qo'shish»): botga kirishini kutayotganlar va ro'yxatdan o'tganlar. */
class StaffInviteResource extends Resource
{
    protected static ?string $model = StaffInvite::class;

    public static function canViewAny(): bool
    {
        return User::currentIsAdmin();
    }

    protected static ?string $navigationGroup = 'Sozlamalar';

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $modelLabel = 'qo‘shilgan xodim';

    protected static ?string $pluralModelLabel = 'Qo‘shilgan xodimlar';

    public static function getNavigationBadge(): ?string
    {
        return ($n = StaffInvite::whereNull('used_at')->count()) ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Botga hali kirmaganlar';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('staff'))
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('F.I.SH')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('Telefon')->searchable(),
                Tables\Columns\TextColumn::make('role')->label('Rol')->badge()
                    ->formatStateUsing(fn ($state) => StaffResource::ROLES[$state] ?? $state)
                    ->color(fn ($state) => $state === \App\Models\Staff::ROLE_CONTRACT ? 'warning' : 'primary'),
                Tables\Columns\TextColumn::make('district_ids')->label('Tumanlar')->badge()->placeholder('—')
                    ->state(fn (StaffInvite $record) => District::whereIn('id', $record->district_ids ?? [])->orderBy('name')->pluck('name')->all())
                    ->limitList(3)->expandableLimitedList(),
                Tables\Columns\TextColumn::make('used_at')->label('Holat')->badge()
                    ->state(fn (StaffInvite $record) => $record->used_at ? 'Botga kirdi: '.$record->staff?->full_name : 'Botga kirishi kutilmoqda')
                    ->color(fn (StaffInvite $record) => $record->used_at ? 'success' : 'warning')
                    ->description(fn (StaffInvite $record) => $record->used_at?->format('d.m.Y H:i')),
                Tables\Columns\TextColumn::make('created_at')->label('Qo‘shilgan')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('used')->label('Holat')
                    ->trueLabel('Botga kirganlar')->falseLabel('Kutilayotganlar')
                    ->queries(true: fn ($q) => $q->whereNotNull('used_at'), false: fn ($q) => $q->whereNull('used_at')),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()->label('Bekor qilish')->visible(fn (StaffInvite $record) => ! $record->used_at),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false; // Xodimlar sahifasidagi «Xodim qo'shish» orqali
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageStaffInvites::route('/')];
    }
}
