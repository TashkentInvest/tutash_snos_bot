<?php

namespace App\Filament\Resources\Dizayn;

use App\Filament\Resources\Dizayn\BotUserResource\Pages;
use App\Models\Dizayn\BotUser;
use App\Models\Dizayn\Model as DizaynModel;
use App\Models\User;
use App\Support\Latin;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Dizayn-kod botidan foydalanganlar: ro'yxatdan o'tish, joylashuv, yuklab olishlar, murojaatlar. Faqat ko'rish. */
class BotUserResource extends Resource
{
    protected static ?string $model = BotUser::class;

    protected static ?string $navigationGroup = 'Dizayn-kod boti';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Bot foydalanuvchilari';

    protected static ?string $modelLabel = 'foydalanuvchi';

    protected static ?string $pluralModelLabel = 'Bot foydalanuvchilari';

    protected static ?string $slug = 'dizayn/foydalanuvchilar';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return User::currentIsAdmin() && DizaynModel::available();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['downloads', 'feedback']))
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('F.I.Sh')->state(fn (BotUser $r) => $r->displayName())
                    ->description(fn (BotUser $r) => collect([\App\Support\Phone::format($r->phone), $r->username ? '@'.$r->username : null])->filter()->join(' · ') ?: null)
                    ->searchable(query: function (Builder $query, string $search) {
                        $s = '%'.mb_strtolower($search).'%'; // katta-kichik harfga qaramay (PostgreSQL ham, sqlite ham)

                        return $query->where(fn ($q) => $q->whereRaw('LOWER(full_name) LIKE ?', [$s])->orWhereRaw('LOWER(first_name) LIKE ?', [$s])
                            ->orWhere('phone', 'like', '%'.preg_replace('/\D+/', '', $search).'%')->orWhereRaw('LOWER(username) LIKE ?', [$s]));
                    }),
                Tables\Columns\TextColumn::make('registered_at')->label('Ro‘yxatdan o‘tgan')->dateTime('d.m.Y H:i')->sortable()->placeholder('o‘tmagan'),
                Tables\Columns\TextColumn::make('lang')->label('Til')->badge()->color('gray')->formatStateUsing(fn ($state) => BotUser::LANGS[$state] ?? $state)->placeholder('—'),
                Tables\Columns\TextColumn::make('downloads_count')->label('Yuklab olgan')->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('feedback_count')->label('Murojaat')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('latitude')->label('Joylashuv')->formatStateUsing(fn ($state) => $state ? 'Xaritada' : '—')
                    ->url(fn (BotUser $r) => $r->latitude ? \App\Support\Maps::url($r->latitude, $r->longitude) : null, true)->color('primary')->toggleable(),
                Tables\Columns\TextColumn::make('first_seen')->label('Birinchi kirgan')->dateTime('d.m.Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_seen')->label('Oxirgi faollik')->since()->sortable()
                    ->description(fn (BotUser $r) => $r->blocked_at ? 'botni bloklagan' : null),
            ])
            ->filters([
                Tables\Filters\Filter::make('registered')->label('Ro‘yxatdan o‘tganlar')->query(fn (Builder $query) => $query->whereNotNull('registered_at')),
                Tables\Filters\Filter::make('downloaded')->label('Fayl yuklab olganlar')->query(fn (Builder $query) => $query->has('downloads')),
                Tables\Filters\Filter::make('blocked')->label('Botni bloklaganlar')->query(fn (Builder $query) => $query->whereNotNull('blocked_at')),
                Tables\Filters\SelectFilter::make('lang')->label('Til')->options(BotUser::LANGS),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Batafsil')])
            ->defaultSort('last_seen', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('name')->label('F.I.Sh')->state(fn (BotUser $r) => $r->displayName()),
                Infolists\Components\TextEntry::make('phone')->label('Telefon')->formatStateUsing(fn ($state) => \App\Support\Phone::format($state))->placeholder('—'),
                Infolists\Components\TextEntry::make('username')->label('Telegram')->formatStateUsing(fn ($state) => '@'.$state)->placeholder('—'),
                Infolists\Components\TextEntry::make('lang')->label('Til')->formatStateUsing(fn ($state) => BotUser::LANGS[$state] ?? $state)->placeholder('—'),
                Infolists\Components\TextEntry::make('registered_at')->label('Ro‘yxatdan o‘tgan')->dateTime('d.m.Y H:i')->placeholder('o‘tmagan'),
                Infolists\Components\TextEntry::make('first_seen')->label('Birinchi kirgan')->dateTime('d.m.Y H:i'),
                Infolists\Components\TextEntry::make('last_seen')->label('Oxirgi faollik')->since(),
                Infolists\Components\TextEntry::make('latitude')->label('Joylashuv')->formatStateUsing(fn ($state, BotUser $r) => $state ? round($r->latitude, 6).', '.round($r->longitude, 6) : '—')
                    ->url(fn (BotUser $r) => $r->latitude ? \App\Support\Maps::url($r->latitude, $r->longitude) : null, true)->color('primary'),
                Infolists\Components\TextEntry::make('blocked_at')->label('Botni bloklagan')->dateTime('d.m.Y H:i')->color('danger')->visible(fn (BotUser $r) => (bool) $r->blocked_at),
            ]),
            Infolists\Components\Section::make('Obyekt rasmlari')->visible(fn (BotUser $r) => $r->photos()->exists())->schema([
                Infolists\Components\ViewEntry::make('photos')->hiddenLabel()->view('filament.dizayn.user-photos'),
            ]),
            Infolists\Components\Section::make('Yuklab olgan konsepsiyalar')->visible(fn (BotUser $r) => $r->downloads()->exists())->schema([
                Infolists\Components\RepeatableEntry::make('downloads')->hiddenLabel()->columns(4)
                    ->state(fn (BotUser $r) => $r->downloads()->latest('sent_at')->limit(50)->get())
                    ->schema([
                        Infolists\Components\TextEntry::make('sent_at')->label('Vaqt')->dateTime('d.m.Y H:i'),
                        Infolists\Components\TextEntry::make('district')->label('Tuman')->formatStateUsing(fn ($state) => Latin::of($state)),
                        Infolists\Components\TextEntry::make('street')->label('Ko‘cha')->formatStateUsing(fn ($state) => Latin::of($state)),
                        Infolists\Components\TextEntry::make('file_name')->label('Fayl'),
                    ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBotUsers::route('/'),
            'view' => Pages\ViewBotUser::route('/{record}'),
        ];
    }
}
