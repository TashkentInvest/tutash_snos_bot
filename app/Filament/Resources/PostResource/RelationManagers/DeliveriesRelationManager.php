<?php

namespace App\Filament\Resources\PostResource\RelationManagers;

use App\Filament\Resources\StaffResource;
use App\Models\PostDelivery;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Post oluvchilari: yetkazildimi, «Tanishdim» bosgan vaqti, postdan keyin botni ochganmi. */
class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Oluvchilar';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $after = fn (Builder $q) => $q->whereHas('staff', fn ($s) => $s->whereColumn('staff.last_seen_at', '>', 'post_deliveries.sent_at'));

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('staff'))
            ->columns([
                Tables\Columns\TextColumn::make('staff.full_name')->label('Kim')->searchable()->placeholder('—')
                    ->description(fn (PostDelivery $d) => \App\Support\Phone::format($d->staff?->phone)),
                Tables\Columns\TextColumn::make('staff.role')->label('Bo‘lim')->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => StaffResource::ROLES[$state] ?? $state),
                Tables\Columns\TextColumn::make('status')->label('Yetkazildi')->badge()
                    ->formatStateUsing(fn ($state) => [PostDelivery::SENT => 'Ha', PostDelivery::FAILED => 'Yo‘q', PostDelivery::PENDING => 'Navbatda'][$state] ?? $state)
                    ->color(fn ($state) => [PostDelivery::SENT => 'success', PostDelivery::FAILED => 'danger'][$state] ?? 'warning')
                    ->tooltip(fn (PostDelivery $d) => $d->error),
                Tables\Columns\TextColumn::make('ack_at')->label('Tanishdim')->dateTime('d.m H:i')->placeholder('—')->color('success')->sortable(),
                Tables\Columns\TextColumn::make('staff.last_seen_at')->label('Oxirgi faollik')->since()->placeholder('—')
                    ->color(fn (PostDelivery $d) => $d->sent_at && $d->staff?->last_seen_at?->greaterThan($d->sent_at) ? 'success' : null)
                    ->tooltip('Yashil — post kelgandan keyin botdan foydalangan'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('view')->label('Holat')->options([
                    'ack' => 'Tanishdim bosgan', 'seen' => 'Ko‘rgan (taxminan)', 'unseen' => 'Ko‘rmagan bo‘lishi mumkin', 'failed' => 'Yetkazilmagan',
                ])->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'ack' => $query->whereNotNull('ack_at'),
                    'seen' => $query->where('status', PostDelivery::SENT)->where(fn ($q) => $q->whereNotNull('ack_at')->orWhere($after)),
                    'unseen' => $query->where('status', PostDelivery::SENT)->whereNull('ack_at')->whereNot($after),
                    'failed' => $query->where('status', PostDelivery::FAILED),
                    default => $query,
                }),
            ])
            ->poll('15s')
            ->defaultSort('ack_at', 'desc');
    }
}
