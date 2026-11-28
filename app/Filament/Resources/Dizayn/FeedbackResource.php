<?php

namespace App\Filament\Resources\Dizayn;

use App\Filament\Resources\Dizayn\FeedbackResource\Pages;
use App\Models\Dizayn\Feedback;
use App\Models\Dizayn\Model as DizaynModel;
use App\Models\User;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fuqarolar murojaatlari (Dizayn-kod boti): e'tiroz, ehtiyoj, taklif. Javob shu yerda yoziladi — bot uni fuqaroga
 * Telegramda yuboradi (yetkazildi / yetkazilmadi holati ham botdan keladi).
 */
class FeedbackResource extends Resource
{
    protected static ?string $model = Feedback::class;

    protected static ?string $navigationGroup = 'Dizayn-kod boti';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Murojaatlar';

    protected static ?string $modelLabel = 'murojaat';

    protected static ?string $pluralModelLabel = 'Murojaatlar';

    protected static ?string $slug = 'dizayn/murojaatlar';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return User::currentIsAdmin() && DizaynModel::available();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            return ($n = Feedback::whereNull('answer')->count()) ? (string) $n : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Javob kutayotgan murojaatlar';
    }

    public static function table(Table $table): Table
    {
        $details = fn (Feedback $r) => [
            Forms\Components\Placeholder::make('who')->label('Fuqaro')
                ->content(collect([$r->user?->displayName(), \App\Support\Phone::format($r->user?->phone), $r->user?->username ? '@'.$r->user->username : null,
                    \App\Models\Dizayn\BotUser::LANGS[$r->user?->lang] ?? null])->filter()->join(' · ')),
            Forms\Components\Placeholder::make('level')->label('Daraja')->content(Feedback::LEVELS[$r->level] ?? $r->level),
            Forms\Components\Placeholder::make('text')->label('Murojaat')->content($r->text),
            Forms\Components\Placeholder::make('answer_view')->label('Javob')->content($r->answer)->visible(filled($r->answer)),
        ];

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('№')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Vaqt')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('user.full_name')->label('Fuqaro')->state(fn (Feedback $r) => $r->user?->displayName() ?? '—')
                    ->description(fn (Feedback $r) => \App\Support\Phone::format($r->user?->phone)),
                Tables\Columns\TextColumn::make('user.lang')->label('Til')->badge()->color('gray')->placeholder('—')
                    ->formatStateUsing(fn ($state) => \App\Models\Dizayn\BotUser::LANGS[$state] ?? $state)
                    ->tooltip('Javobni fuqaro tanlagan tilda yozing'),
                Tables\Columns\TextColumn::make('level')->label('Daraja')->badge()
                    ->formatStateUsing(fn ($state) => Feedback::LEVELS[$state] ?? $state)->color(fn ($state) => Feedback::LEVEL_COLORS[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('text')->label('Murojaat')->limit(80)->wrap()->searchable()
                    ->tooltip(fn (Feedback $r) => mb_strlen($r->text) > 80 ? $r->text : null),
                Tables\Columns\TextColumn::make('status')->label('Holat')->badge()->state(fn (Feedback $r) => $r->status())
                    ->formatStateUsing(fn ($state) => Feedback::STATUSES[$state])->color(fn ($state) => Feedback::STATUS_COLORS[$state])
                    ->tooltip(fn (Feedback $r) => $r->answer_error),
                Tables\Columns\TextColumn::make('answered_at')->label('Javob vaqti')->dateTime('d.m.Y H:i')->placeholder('—')
                    ->description(fn (Feedback $r) => $r->answered_by)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Holat')->options(Feedback::STATUSES)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'new' => $query->whereNull('answer'),
                        'answered' => $query->whereNotNull('answer')->whereNull('answer_sent_at'),
                        'delivered' => $query->whereNotNull('answer_sent_at')->whereNull('answer_error'),
                        'failed' => $query->whereNotNull('answer_error'),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('level')->label('Daraja')->options(Feedback::LEVELS),
            ])
            ->actions([
                Tables\Actions\Action::make('view')->label('Ko‘rish')->icon('heroicon-o-eye')->color('gray')
                    ->modalHeading(fn (Feedback $r) => "Murojaat №{$r->id}")->modalSubmitAction(false)->modalCancelActionLabel('Yopish')
                    ->form($details),
                Tables\Actions\Action::make('answer')->label('Javob berish')->icon('heroicon-o-paper-airplane')->color('primary')
                    ->visible(fn (Feedback $r) => $r->answer === null)
                    ->modalHeading(fn (Feedback $r) => "Murojaat №{$r->id} ga javob")
                    ->modalDescription('Javob fuqaroga Dizayn-kod boti orqali Telegramda yuboriladi. Yuborilgandan keyin uni o‘zgartirib bo‘lmaydi.')
                    ->modalSubmitActionLabel('Yuborish')
                    ->form(fn (Feedback $r) => [...$details($r),
                        Forms\Components\Textarea::make('answer')->label('Javob matni')->required()->rows(6)->minLength(2)->maxLength(Feedback::ANSWER_MAX)])
                    ->action(function (Feedback $r, array $data) {
                        // faqat javobsiz bo'lsa: ikki admin bir vaqtda javob bersa — birinchisiniki qoladi
                        $n = Feedback::whereKey($r->getKey())->whereNull('answer')->update([
                            'answer' => trim($data['answer']), 'answered_at' => now()->utc()->format('Y-m-d H:i:s'), 'answered_by' => auth()->user()?->email,
                        ]);
                        \App\Models\AuditLog::record('dizayn.feedback.answered', null, ['feedback' => $r->id, 'by' => auth()->id(), 'saved' => (bool) $n]);
                        $n ? Notification::make()->title('Javob saqlandi — bot uni fuqaroga yuboradi')->success()->send()
                            : Notification::make()->title('Bu murojaatga boshqa admin allaqachon javob bergan')->warning()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFeedback::route('/')];
    }
}
