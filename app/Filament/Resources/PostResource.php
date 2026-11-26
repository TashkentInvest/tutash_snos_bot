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
                    ->description(fn (Post $p) => self::MEDIA[$p->media_type] ?? 'Matn'),
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
            ->actions([Tables\Actions\ViewAction::make()->label('Batafsil'), Tables\Actions\DeleteAction::make()->label('O‘chirish')
                ->modalHeading('Post panel ro‘yxatidan o‘chirilsinmi?')->modalDescription('Botda yuborilgan xabarlar o‘chmaydi — faqat statistika va fayl o‘chadi.')])
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
                    ->formatStateUsing(fn ($state) => $state === Post::SENDING ? 'Yuborilmoqda' : 'Yuborildi')->color(fn ($state) => $state === Post::SENDING ? 'warning' : 'success'),
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
