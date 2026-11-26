<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PostResource\Pages;
use App\Filament\Resources\PostResource\RelationManagers\DeliveriesRelationManager;
use App\Models\Post;
use App\Models\User;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** «Postlar»: botda yuborilgan postlar va statistikasi (yetkazildi, tanishdi, postdan keyin botni ochgan). */
class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Postlar';

    protected static ?string $modelLabel = 'post';

    protected static ?string $pluralModelLabel = 'Postlar';

    protected static ?string $navigationGroup = 'Foydalanuvchilar';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return User::currentIsAdmin();
    }

    public static function canCreate(): bool
    {
        return false; // bosh sahifadagi «Post yuborish» tugmasi orqali
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return User::currentIsAdmin();
    }

    public const MEDIA = ['photo' => 'Rasm', 'video' => 'Video'];

    public static function statusLabel(Post $p): string
    {
        if ($p->recalled_at) {
            $done = $p->recalled + $p->recall_failed;

            return $done ? "Botdan o‘chirildi: {$p->recalled}".($p->recall_failed ? " (o‘chmadi: {$p->recall_failed})" : '') : 'Botdan o‘chirilmoqda…';
        }

        return $p->status === Post::SENDING ? 'Yuborilmoqda' : 'Yuborildi';
    }

    /** «N (x%)» — yetkazilganlarga nisbatan. */
    public static function share(int $n, int $of): string
    {
        return $n.($of ? ' ('.round($n * 100 / $of).'%)' : '');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Sana')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('caption')->label('Post')->state(fn (Post $p) => $p->title())->wrap()
                    ->description(fn (Post $p) => (self::MEDIA[$p->media_type] ?? 'Matn').($p->recalled_at ? ' · '.self::statusLabel($p) : '')),
                Tables\Columns\TextColumn::make('total')->label('Oluvchilar')->alignEnd(),
                Tables\Columns\TextColumn::make('delivered')->label('Yetkazildi')->alignEnd()
                    ->description(fn (Post $p) => $p->failed ? "yetkazilmadi: {$p->failed}" : null)
                    ->formatStateUsing(fn ($state, Post $p) => $p->status === Post::SENDING ? "{$state} / {$p->total} — yuborilmoqda" : $state),
                Tables\Columns\TextColumn::make('ack')->label('Tanishdim bosdi')->alignEnd()->color('success')
                    ->state(fn (Post $p) => $p->ack_button ? self::share($p->stats()['ack'], $p->delivered) : '—'),
                Tables\Columns\TextColumn::make('seen')->label('Ko‘rgan (taxminan)')->alignEnd()
                    ->state(fn (Post $p) => self::share($p->stats()['seen'], $p->delivered))
                    ->tooltip('«Tanishdim» bosgan yoki post kelgandan keyin botdan foydalangan'),
                Tables\Columns\TextColumn::make('author.name')->label('Kim yubordi')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Batafsil'), self::recall(Tables\Actions\Action::class),
                Tables\Actions\DeleteAction::make()->label('Ro‘yxatdan o‘chirish')
                ->modalHeading('Post panel ro‘yxatidan o‘chirilsinmi?')->modalDescription('Botdagi xabarlar o‘chmaydi — buning uchun avval «Botdan o‘chirish». Bu yerda faqat statistika va fayl o‘chadi.')])
            ->recordUrl(fn (Post $p) => self::getUrl('view', ['record' => $p]))
            ->poll('15s')
            ->emptyStateHeading('Hali post yuborilmagan')->emptyStateDescription('Bosh sahifadagi «Post yuborish» tugmasi orqali.')
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('created_at')->label('Yuborilgan')->dateTime('d.m.Y H:i'),
                Infolists\Components\TextEntry::make('author.name')->label('Kim yubordi')->placeholder('—'),
                Infolists\Components\TextEntry::make('media_type')->label('Turi')->formatStateUsing(fn ($state) => self::MEDIA[$state] ?? 'Matn')->placeholder('Matn')
                    ->url(fn (Post $p) => $p->media_path ? route('admin.post-media', $p) : null, true),
                Infolists\Components\TextEntry::make('status')->label('Holat')->badge()
                    ->state(fn (Post $p) => self::statusLabel($p))->color(fn (Post $p) => $p->recalled_at ? 'danger' : ($p->status === Post::SENDING ? 'warning' : 'success')),
                Infolists\Components\TextEntry::make('caption')->label('Matn')->columnSpanFull()->placeholder('—')->extraAttributes(['style' => 'white-space:pre-line']),
            ]),
            Infolists\Components\Section::make('Statistika')->columns(5)
                ->description('Telegram shaxsiy chatda kim ko‘rganini botga bermaydi. «Ko‘rgan» — «Tanishdim» bosgan yoki post kelgandan keyin botdan foydalanganlar (taxminiy, kamida shuncha).')
                ->schema(fn (Post $p) => collect([
                    'Oluvchilar' => $p->total,
                    'Yetkazildi' => $p->delivered.($p->failed ? " (yetkazilmadi: {$p->failed})" : ''),
                    'Tanishdim bosdi' => $p->ack_button ? self::share($p->stats()['ack'], $p->delivered) : 'tugma yo‘q',
                    'Postdan keyin botni ochgan' => self::share($p->stats()['active'], $p->delivered),
                    'Ko‘rgan (taxminan)' => self::share($p->stats()['seen'], $p->delivered),
                ])->map(fn ($v, $k) => Infolists\Components\TextEntry::make('s'.md5($k))->label($k)->state($v)->size(Infolists\Components\TextEntry\TextEntrySize::Large)->weight('bold'))->values()->all()),
        ]);
    }

    /**
     * «Botdan o'chirish»: post oluvchilar chatidan o'chiriladi (navbatda). Telegram 48 soatdan eski xabarni o'chirmaydi.
     * @param  class-string  $class  jadval yoki sahifa tugmasi
     */
    public static function recall(string $class): \Filament\Actions\Action|Tables\Actions\Action
    {
        return $class::make('recall')->label('Botdan o‘chirish')->icon('heroicon-o-trash')->color('danger')
            ->visible(fn (Post $record) => User::currentIsAdmin() && $record->status === Post::SENT && ! $record->recalled_at && $record->delivered > 0)
            ->requiresConfirmation()
            ->modalHeading('Post barcha oluvchilar chatidan o‘chirilsinmi?')
            ->modalDescription(fn (Post $record) => "Botda {$record->delivered} ta oluvchidan o‘chiriladi. Statistika panelda qoladi. "
                .($record->created_at->lt(now()->subHours(48))
                    ? 'Diqqat: post 48 soatdan oldin yuborilgan — Telegram eski xabarlarni o‘chirishga ruxsat bermaydi, ko‘pchiligi o‘chmasligi mumkin.'
                    : 'Telegram faqat 48 soat ichida o‘chirishga ruxsat beradi — '.now()->diffInHours($record->created_at->copy()->addHours(48)).' soat qoldi.'))
            ->modalSubmitActionLabel('Ha, botdan o‘chirish')
            ->action(function (Post $record) {
                $record->update(['recalled_at' => now()]);
                \App\Models\AuditLog::record('post.recalled', null, ['post' => $record->id, 'by' => auth()->id()]);
                \App\Jobs\RecallPost::dispatch($record->id);
                \Filament\Notifications\Notification::make()->title('Post botdan o‘chirilmoqda')->body('Natija shu sahifada ko‘rinadi.')->success()->send();
            });
    }

    public static function getRelations(): array
    {
        return [DeliveriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'view' => Pages\ViewPost::route('/{record}'),
        ];
    }
}
