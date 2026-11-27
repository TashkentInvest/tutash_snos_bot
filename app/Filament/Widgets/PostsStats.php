<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PostResource;
use App\Models\Post;
use App\Models\User;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/** Bosh sahifa: oxirgi postlar statistikasi (yetkazildi, tanishdi, ko'rgan — taxminan). */
class PostsStats extends BaseWidget
{
    protected static ?int $sort = -9; // «Overview» dan keyin

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return User::currentIsAdmin() && Post::exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Botdagi postlar — statistika')
            ->description('«Ko‘rgan» — «Tanishdim» bosgan yoki post kelgandan keyin botdan foydalanganlar (Telegram shaxsiy chatda ko‘rishlar sonini bermaydi).')
            ->query(fn () => Post::query()->latest('id')->limit(5))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Sana')->dateTime('d.m.Y H:i'),
                Tables\Columns\TextColumn::make('caption')->label('Post')->state(fn (Post $p) => $p->title())->wrap()
                    ->description(fn (Post $p) => PostResource::MEDIA[$p->media_type] ?? 'Matn'),
                Tables\Columns\TextColumn::make('total')->label('Oluvchilar')->alignEnd(),
                Tables\Columns\TextColumn::make('delivered')->label('Yetkazildi')->alignEnd()
                    ->formatStateUsing(fn ($state, Post $p) => $p->status === Post::SENDING ? "{$state} / {$p->total}…" : $state),
                Tables\Columns\TextColumn::make('ack')->label('Tanishdim')->alignEnd()->color('success')
                    ->state(fn (Post $p) => $p->ack_button ? PostResource::share($p->stats()['ack'], $p->delivered) : '—'),
                Tables\Columns\TextColumn::make('seen')->label('Ko‘rgan (taxminan)')->alignEnd()->weight('bold')
                    ->state(fn (Post $p) => PostResource::share($p->stats()['seen'], $p->delivered)),
            ])
            ->recordUrl(fn (Post $p) => PostResource::getUrl('view', ['record' => $p]))
            ->paginated(false)
            ->poll('30s');
    }
}
