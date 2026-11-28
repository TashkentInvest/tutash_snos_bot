<?php

namespace App\Filament\Resources\Dizayn;

use App\Filament\Resources\Dizayn\PostResource\Pages;
use App\Models\Dizayn\Model as DizaynModel;
use App\Models\Dizayn\Post;
use App\Models\User;
use App\Support\DizaynPost;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dizayn-kod botidagi postlar (bosh sahifadagi «Post yuborish» → «Dizayn-kod boti», yoki eski Dizayn panelidan):
 * oluvchilar, yetkazildi, «Tanishdim». Yuborish va botdan o'chirishni Dizayn botining o'zi bajaradi.
 */
class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $navigationGroup = 'Dizayn-kod boti';

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Postlar';

    protected static ?string $modelLabel = 'post';

    protected static ?string $pluralModelLabel = 'Postlar';

    protected static ?string $slug = 'dizayn/postlar';

    protected static ?int $navigationSort = 7;

    public const MEDIA = ['photo' => 'Rasm', 'video' => 'Video'];

    public static function canViewAny(): bool
    {
        return User::currentIsAdmin() && DizaynModel::available();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function status(Post $p): string
    {
        if ($p->recalled_at) {
            return $p->recall_finished_at ? "Botdan o‘chirildi: {$p->recalled}".($p->recall_failed ? " (o‘chmadi: {$p->recall_failed})" : '') : 'Botdan o‘chirilmoqda…';
        }

        return $p->status === 'sending' ? 'Yuborilmoqda' : 'Yuborildi';
    }

    public static function share(int $n, int $of): string
    {
        return $n.($of ? ' ('.round($n * 100 / $of).'%)' : '');
    }

    private static function title(Post $p): string
    {
        return filled($p->caption) ? \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', strip_tags($p->caption)), 90) : (self::MEDIA[$p->media_type] ?? 'Post');
    }

    private static function groups(Post $p): string
    {
        return collect($p->audience['groups'] ?? [])->map(fn ($g) => str(DizaynPost::GROUPS[$g] ?? $g)->before(' (')->toString())->join(', ') ?: '—';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['deliveries as ack_count' => fn ($query) => $query->whereNotNull('ack_at')]))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Sana')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('caption')->label('Post')->state(fn (Post $p) => self::title($p))->wrap()
                    ->description(fn (Post $p) => (self::MEDIA[$p->media_type] ?? 'Matn').' · '.self::groups($p)
                        .(($p->audience['from'] ?? null) === 'snos' ? ' · shu paneldan' : '')),
                Tables\Columns\TextColumn::make('total')->label('Oluvchilar')->alignEnd(),
                Tables\Columns\TextColumn::make('delivered')->label('Yetkazildi')->alignEnd()
                    ->description(fn (Post $p) => $p->failed ? "yetkazilmadi: {$p->failed}" : null)
                    ->formatStateUsing(fn ($state, Post $p) => $p->status === 'sending' ? "{$state} / {$p->total} — yuborilmoqda" : $state),
                Tables\Columns\TextColumn::make('ack_count')->label('Tanishdim bosdi')->alignEnd()->color('success')
                    ->formatStateUsing(fn ($state, Post $p) => $p->ack_button ? self::share((int) $state, (int) $p->delivered) : '—'),
                Tables\Columns\TextColumn::make('status')->label('Holat')->badge()->state(fn (Post $p) => self::status($p))
                    ->color(fn (Post $p) => $p->recalled_at ? 'danger' : ($p->status === 'sending' ? 'warning' : 'success')),
            ])
            ->actions([
                Tables\Actions\Action::make('recall')->label('Botdan o‘chirish')->icon('heroicon-o-trash')->color('danger')
                    ->visible(fn (Post $record) => $record->status === 'sent' && ! $record->recalled_at && $record->delivered > 0)
                    ->requiresConfirmation()
                    ->modalHeading('Post Dizayn-kod botidagi barcha chatlardan o‘chirilsinmi?')
                    ->modalDescription(fn (Post $record) => "Botda {$record->delivered} ta oluvchidan o‘chiriladi. "
                        .($record->created_at?->lt(now()->subHours(48)) ? 'Diqqat: 48 soatdan eski — Telegram ko‘pchiligini o‘chirmasligi mumkin.' : 'Telegram faqat 48 soat ichida o‘chirishga ruxsat beradi.'))
                    ->modalSubmitActionLabel('Ha, botdan o‘chirish')
                    ->action(function (Post $record) {
                        Post::whereKey($record->getKey())->whereNull('recalled_at')->update(['recalled_at' => now()->utc()->format('Y-m-d H:i:s')]);
                        \App\Models\AuditLog::record('dizayn.post.recalled', null, ['post' => $record->id, 'by' => auth()->id()]);
                        Notification::make()->title('Post botdan o‘chirilmoqda — Dizayn-kod boti bir necha soniyada bajaradi')->success()->send();
                    }),
            ])
            ->poll('15s')
            ->emptyStateHeading('Dizayn-kod botida hali post yo‘q')->emptyStateDescription('Bosh sahifa → «Post yuborish» → «Dizayn-kod boti».')
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPosts::route('/')];
    }
}
