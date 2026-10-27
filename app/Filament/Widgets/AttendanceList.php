<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AttendanceResource;
use App\Models\Attendance;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/** Dashboard: bugungi davomat ro'yxati (vaqt, joylashuv xaritasi, video, sabab). */
class AttendanceList extends BaseWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Bugungi davomat ro‘yxati';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(Attendance::query()->with('staff.districts')->whereDate('date', today()))
            ->columns([
                Tables\Columns\TextColumn::make('staff.full_name')->label('Xodim')->searchable(),
                Tables\Columns\TextColumn::make('staff.districts.name')->label('Tuman')->badge(),
                Tables\Columns\TextColumn::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state) => AttendanceResource::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        Attendance::PRESENT => 'success',
                        Attendance::ABSENT => 'warning',
                        Attendance::MISSED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('responded_at')->label('Vaqti')->dateTime('H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('lat')->label('Joylashuv')
                    ->formatStateUsing(fn ($state) => $state ? 'Xaritada ochish' : '—')
                    ->url(fn (Attendance $record) => $record->lat ? \App\Support\Maps::url($record->lat, $record->lng) : null, true),
                Tables\Columns\TextColumn::make('video_path')->label('Video')
                    ->formatStateUsing(fn ($state, Attendance $record) => $state ? 'Ko‘rish' : ($record->video_file_id ? 'Yuklanmoqda…' : '—'))
                    ->url(fn (Attendance $record) => $record->video_path ? route('admin.attendance-video', ['attendance' => $record, 'v' => $record->updated_at?->timestamp]) : null, true),
                Tables\Columns\TextColumn::make('reason')->label('Sabab')->limit(50)->tooltip(fn (Attendance $record) => $record->reason)->placeholder('—'),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Bugun davomat so‘rovi yuborilmagan')
            ->defaultSort('responded_at', 'desc');
    }
}
