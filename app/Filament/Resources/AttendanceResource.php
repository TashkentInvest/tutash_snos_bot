<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceResource\Pages;
use App\Models\Attendance;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Kunlik davomat jurnali: faqat ko'rish. */
class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $modelLabel = 'davomat';

    protected static ?string $pluralModelLabel = 'Davomat';

    protected static ?int $navigationSort = 3;

    public const STATUSES = [
        Attendance::SENT => 'Javob kutilmoqda',
        Attendance::LOCATING => 'Joylashuv kutilmoqda',
        Attendance::RECORDING => 'Video kutilmoqda',
        Attendance::REASONING => 'Sabab kutilmoqda',
        Attendance::PRESENT => 'Ishda',
        Attendance::ABSENT => 'Ishda emas (sabab bilan)',
        Attendance::MISSED => 'Javob bermadi',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['staff.districts']))
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Sana')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('staff.full_name')->label('Xodim')->searchable(),
                Tables\Columns\TextColumn::make('staff.districts.name')->label('Tuman')->badge(),
                Tables\Columns\TextColumn::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        Attendance::PRESENT => 'success',
                        Attendance::ABSENT => 'warning',
                        Attendance::MISSED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('responded_at')->label('Javob vaqti')->dateTime('H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('lat')->label('Joylashuv')
                    ->formatStateUsing(fn ($state, Attendance $record) => $state ? 'Xaritada ochish' : '—')
                    ->url(fn (Attendance $record) => $record->lat ? \App\Support\Maps::url($record->lat, $record->lng) : null, true),
                Tables\Columns\TextColumn::make('video_path')->label('Video')
                    ->formatStateUsing(fn ($state, Attendance $record) => $state ? 'Ko‘rish' : ($record->video_file_id ? 'Yuklanmoqda…' : '—'))
                    ->url(fn (Attendance $record) => $record->video_path ? route('admin.attendance-video', ['attendance' => $record, 'v' => $record->updated_at?->timestamp]) : null, true),
                Tables\Columns\TextColumn::make('face_video_path')->label('Yuz (birlashtirilmagan)')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state) => $state ? 'Ko‘rish' : '—')
                    ->url(fn (Attendance $record) => $record->face_video_path ? route('admin.attendance-video', ['attendance' => $record, 'face' => 1, 'v' => $record->updated_at?->timestamp]) : null, true),
                Tables\Columns\TextColumn::make('device')->label('Qurilma')->formatStateUsing(fn ($state) => \App\Support\Device::label($state))->wrap()->toggleable(),
                Tables\Columns\TextColumn::make('flags')->label('Belgi')->badge()->color('warning')
                    ->formatStateUsing(fn ($state) => \App\Support\Gps::flagLabel($state))->placeholder('—'),
                Tables\Columns\TextColumn::make('reason')->label('Sabab')->limit(60)->tooltip(fn (Attendance $record) => $record->reason)->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Holat')->options(self::STATUSES),
                Tables\Filters\Filter::make('date')->label('Sana')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Dan')->default(today()),
                        \Filament\Forms\Components\DatePicker::make('to')->label('Gacha'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($query, $v) => $query->whereDate('date', '>=', $v))
                        ->when($data['to'] ?? null, fn ($query, $v) => $query->whereDate('date', '<=', $v))),
            ])
            ->defaultSort('date', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAttendances::route('/')];
    }
}
