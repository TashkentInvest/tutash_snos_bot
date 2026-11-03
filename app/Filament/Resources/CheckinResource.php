<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CheckinResource\Pages;
use App\Models\Checkin;
use App\Models\Staff;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Shartnoma xodimlarining soatlik qaydlari (HR: qachon va qayerda bo'lgani) — faqat ko'rish, faqat admin. */
class CheckinResource extends Resource
{
    protected static ?string $model = Checkin::class;

    public static function canViewAny(): bool
    {
        return User::currentIsAdmin();
    }

    protected static ?string $navigationGroup = 'Shartnoma';

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $modelLabel = 'soatlik qayd';

    protected static ?string $pluralModelLabel = 'Soatlik qaydlar';

    protected static ?int $navigationSort = 2;

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            Checkin::PRESENT => 'success',
            Checkin::MISSED => 'danger',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('staff'))
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Sana')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('slot')->label('Soat')->sortable(),
                Tables\Columns\TextColumn::make('staff.full_name')->label('Xodim')->searchable(),
                Tables\Columns\TextColumn::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state) => Checkin::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => self::statusColor($state)),
                Tables\Columns\TextColumn::make('responded_at')->label('Javob vaqti')->dateTime('H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('lat')->label('Joylashuv')
                    ->formatStateUsing(fn ($state) => $state ? 'Xaritada ochish' : '—')
                    ->url(fn (Checkin $record) => $record->lat ? \App\Support\Maps::url($record->lat, $record->lng) : null, true),
                Tables\Columns\TextColumn::make('nearest_site_m')->label('Eng yaqin obyekt (m)')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('video_path')->label('Video')
                    ->formatStateUsing(fn ($state) => $state ? 'Ko‘rish' : '—')
                    ->url(fn (Checkin $record) => $record->video_path ? route('admin.checkin-video', ['checkin' => $record, 'v' => $record->updated_at?->timestamp]) : null, true),
                Tables\Columns\TextColumn::make('face_video_path')->label('Yuz')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state) => $state ? 'Ko‘rish' : '—')
                    ->url(fn (Checkin $record) => $record->face_video_path ? route('admin.checkin-video', ['checkin' => $record, 'face' => 1]) : null, true),
                Tables\Columns\TextColumn::make('flags')->label('Belgi')->badge()->color('warning')
                    ->formatStateUsing(fn ($state) => $state === 'undeliverable' ? 'Xabar yetkazilmadi' : \App\Support\Gps::flagLabel($state))->placeholder('—'),
                Tables\Columns\TextColumn::make('device')->label('Qurilma')->formatStateUsing(fn ($state) => \App\Support\Device::label($state))->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('staff_id')->label('Xodim')
                    ->options(fn () => Staff::where('is_contract', true)->orderBy('full_name')->pluck('full_name', 'id')),
                Tables\Filters\SelectFilter::make('status')->label('Holat')->options(Checkin::STATUSES),
                Tables\Filters\Filter::make('date')->label('Sana')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Dan')->default(today()),
                        \Filament\Forms\Components\DatePicker::make('to')->label('Gacha'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($query, $v) => $query->whereDate('date', '>=', $v))
                        ->when($data['to'] ?? null, fn ($query, $v) => $query->whereDate('date', '<=', $v))),
            ])
            ->defaultSort(fn ($query) => $query->orderByDesc('date')->orderByDesc('slot'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCheckins::route('/')];
    }
}
