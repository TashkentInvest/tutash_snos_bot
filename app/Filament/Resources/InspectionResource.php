<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InspectionResource\Pages;
use App\Models\Inspection;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Infolists\Infolist;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Tekshiruvlar arxivi: faqat ko'rish (bot orqali yuborilgan ma'lumot o'zgartirilmaydi). */
class InspectionResource extends Resource
{
    protected static ?string $model = Inspection::class;

    // Kuzatuvchi menyuda tekshiruvlar bo'limini ko'rmaydi (natijani obyekt sahifasidan ochadi), tasdiqlash — faqat admin.
    public static function shouldRegisterNavigation(): bool
    {
        return User::currentIsAdmin();
    }

    public static function canDeleteAny(): bool
    {
        return User::currentIsAdmin();
    }

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $modelLabel = 'tekshiruv';

    protected static ?string $pluralModelLabel = 'Tekshiruvlar arxivi';

    protected static ?int $navigationSort = 2;

    private const REVIEW = [
        Inspection::REVIEW_PENDING => 'Tasdiq kutilmoqda',
        Inspection::REVIEW_APPROVED => 'Tasdiqlangan',
        Inspection::REVIEW_REJECTED => 'Rad etilgan',
    ];

    /** Menyudagi belgi: moderator tasdig'ini kutayotgan "buzilgan" natijalar soni. */
    public static function getNavigationBadge(): ?string
    {
        $n = Inspection::where('status', Inspection::SUBMITTED)->where('review_status', Inspection::REVIEW_PENDING)->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Moderator tasdig‘ini kutayotgan tekshiruvlar';
    }

    /** Tasdiqlash / rad etish amallari (ro'yxatda ham, ko'rish sahifasida ham). */
    public static function reviewActions(): array
    {
        return [
            Tables\Actions\Action::make('approve')->label('Tasdiqlash')->icon('heroicon-o-check-circle')->color('success')
                ->modalHeading(fn (Inspection $record) => $record->isCourtCancelled() ? 'Sud qarori bekor qilinganini tasdiqlaysizmi?' : 'Obyekt buzilganini tasdiqlaysizmi?')
                ->modalDescription(fn (Inspection $record) => $record->isCourtCancelled()
                    ? 'Ilova qilingan hujjatni tekshiring. Tasdiqlangach obyekt «Buzilmaydi» holatiga o‘tadi va umumiy sondan chiqariladi.'
                    : 'Tasdiqlangach obyekt «Buzilgan» holatiga o‘tadi va hisoblarga qo‘shiladi.')
                ->form([self::approveNote()])->modalSubmitActionLabel('Tasdiqlash')
                ->visible(fn (Inspection $record) => $record->review_status === Inspection::REVIEW_PENDING && User::currentIsAdmin())
                ->action(function (Inspection $record, array $data) {
                    $ok = \App\Support\InspectionReview::approve($record, auth()->id(), $data['note'] ?? null);
                    Notification::make()->title($ok ? ($record->isCourtCancelled() ? 'Tasdiqlandi: obyekt umumiy hisobdan chiqarildi' : 'Tasdiqlandi: obyekt «Buzilgan» deb belgilandi') : 'Bu natija allaqachon ko‘rib chiqilgan')->{$ok ? 'success' : 'warning'}()->send();
                }),
            Tables\Actions\Action::make('reject')->label('Rad etish')->icon('heroicon-o-x-circle')->color('danger')
                ->modalHeading('Natijani rad etish')
                ->form([Forms\Components\Textarea::make('note')->label('Sabab (xodimga yuboriladi)')->required()->minLength(5)->maxLength(500)])
                ->visible(fn (Inspection $record) => $record->review_status === Inspection::REVIEW_PENDING && User::currentIsAdmin())
                ->action(function (Inspection $record, array $data) {
                    $ok = \App\Support\InspectionReview::reject($record, auth()->id(), $data['note']);
                    Notification::make()->title($ok ? 'Natija rad etildi, obyekt holati o‘zgarmadi' : 'Bu natija allaqachon ko‘rib chiqilgan')->{$ok ? 'success' : 'warning'}()->send();
                }),
            ...self::followupActions(),
        ];
    }

    public static function approveNote(): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make('note')->label('Izoh (ixtiyoriy)')->maxLength(500)
            ->placeholder('Masalan: qolgan devorni ham buzib, oxiriga yetkazing')
            ->helperText('Izoh yozilsa, xodimga yuboriladi va u bajarilgan ishni rasm/video bilan tasdiqlaydi. Bajarilishi «Izoh holati» ustunida kuzatiladi.');
    }

    /** Moderator izohi bajarilishini nazorat qilish: yopish yoki (tasdiqlanganda) qayta izoh berish. */
    public static function followupActions(): array
    {
        return [
            Tables\Actions\Action::make('closeFollowup')->label('Izohni yopish')->icon('heroicon-o-check-badge')->color('success')
                ->requiresConfirmation()->modalHeading('Izoh bajarildi deb yopilsinmi?')
                ->modalDescription('Xodim yuborgan rasm va videolarni tekshirib chiqing.')
                ->visible(fn (Inspection $record) => $record->followup === Inspection::FOLLOWUP_DONE && User::currentIsAdmin())
                ->action(function (Inspection $record) {
                    $ok = \App\Support\InspectionReview::closeFollowup($record, auth()->id());
                    Notification::make()->title($ok ? 'Izoh yopildi' : 'Izoh allaqachon yopilgan')->{$ok ? 'success' : 'warning'}()->send();
                }),
            Tables\Actions\Action::make('reopenFollowup')->label('Bajarilmagan')->icon('heroicon-o-arrow-path')->color('warning')
                ->modalHeading('Izoh to‘liq bajarilmagan')
                ->form([Forms\Components\Textarea::make('note')->label('Yangi izoh (xodimga yuboriladi)')->required()->minLength(5)->maxLength(500)])
                ->visible(fn (Inspection $record) => $record->followup === Inspection::FOLLOWUP_DONE && $record->review_status === Inspection::REVIEW_APPROVED && User::currentIsAdmin())
                ->action(function (Inspection $record, array $data) {
                    $ok = \App\Support\InspectionReview::reopenFollowup($record, auth()->id(), $data['note']);
                    Notification::make()->title($ok ? 'Izoh xodimga qayta yuborildi' : 'Holat o‘zgargan, sahifani yangilang')->{$ok ? 'success' : 'warning'}()->send();
                }),
        ];
    }

    public static function followupColor(?string $state): string
    {
        return match ($state) {
            Inspection::FOLLOWUP_OPEN => 'warning',
            Inspection::FOLLOWUP_DONE => 'danger',
            Inspection::FOLLOWUP_CLOSED => 'success',
            default => 'gray',
        };
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // izoh bo'yicha yuborilgan rasm/videolar asosiy natija sahifasida ko'rsatiladi
        return parent::getEloquentQuery()->where('status', Inspection::SUBMITTED)->whereNull('followup_of');
    }

    public static function result(Inspection $in): string
    {
        return match (true) {
            $in->kind === Inspection::KIND_FOUND => 'Buzilgan (kelganda)',
            (bool) $in->is_demolished => 'Buzilgan',
            $in->isCourtCancelled() => 'Sud qarori bekor',
            $in->exempt_type === Inspection::EXEMPT_OTHER => 'Boshqa muammo',
            (bool) $in->in_progress => 'Jarayonda',
            $in->kind === Inspection::KIND_BEFORE => 'Oldingi holat',
            default => 'Buzilmagan', // eski natijalar
        };
    }

    public static function resultColor(Inspection $in): string
    {
        return $in->is_demolished ? 'success' : ($in->exempt_type ? 'gray' : ($in->in_progress ? 'info' : 'warning'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['site.district', 'staff']))
            ->columns([
                Tables\Columns\TextColumn::make('submitted_at')->label('Sana')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('site.district.name')->label('Tuman'),
                Tables\Columns\TextColumn::make('site.address')->label('Obyekt')->limit(40)->searchable(),
                Tables\Columns\TextColumn::make('staff.full_name')->label('Xodim')->searchable(),
                Tables\Columns\TextColumn::make('result')->label('Natija')->badge()
                    ->state(fn (Inspection $record) => self::result($record))
                    ->color(fn (Inspection $record) => self::resultColor($record)),
                Tables\Columns\TextColumn::make('review_status')->label('Tasdiq')->badge()
                    ->formatStateUsing(fn ($state) => self::REVIEW[$state] ?? '—')
                    ->color(fn ($state) => match ($state) {
                        Inspection::REVIEW_PENDING => 'danger',
                        Inspection::REVIEW_APPROVED => 'success',
                        Inspection::REVIEW_REJECTED => 'gray',
                        default => 'gray',
                    })
                    ->description(fn (Inspection $record) => $record->rework_of ? 'Tuzatib qayta yuborilgan' : null),
                Tables\Columns\TextColumn::make('followup')->label('Izoh holati')->badge()
                    ->formatStateUsing(fn ($state) => Inspection::FOLLOWUPS[$state] ?? '—')
                    ->color(fn ($state) => self::followupColor($state))
                    ->tooltip(fn (Inspection $record) => $record->review_note),
                Tables\Columns\TextColumn::make('distance_m')->label('Masofa (m)')->placeholder('—'),
                Tables\Columns\IconColumn::make('suspicious')->label('Shubhali')->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')->trueColor('danger')->falseIcon('')->falseColor('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('district')->label('Tuman')
                    ->relationship('site.district', 'name'),
                Tables\Filters\SelectFilter::make('result')->label('Natija')
                    ->options(['before' => 'Oldingi holat', 'in_progress' => 'Jarayonda', 'demolished' => 'Buzilgan', 'found' => 'Buzilgan (kelganda)', 'court_cancelled' => 'Sud qarori bekor', 'other' => 'Boshqa muammo (eski)', 'standing' => 'Buzilmagan (eski)'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'before', 'found' => $query->where('kind', $data['value']),
                        'demolished' => $query->where('is_demolished', true),
                        'in_progress' => $query->where('is_demolished', false)->where('in_progress', true),
                        'court_cancelled', 'other' => $query->where('exempt_type', $data['value']),
                        'standing' => $query->whereNull('kind')->where('is_demolished', false)->where('in_progress', false)->whereNull('exempt_type'),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('review_status')->label('Tasdiq')->options(self::REVIEW),
                Tables\Filters\SelectFilter::make('followup')->label('Izoh holati')->options(Inspection::FOLLOWUPS),
                Tables\Filters\TernaryFilter::make('suspicious')->label('Shubhali'),
                Tables\Filters\Filter::make('date')->label('Sana')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Dan'),
                        \Filament\Forms\Components\DatePicker::make('to')->label('Gacha'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($query, $v) => $query->whereDate('submitted_at', '>=', $v))
                        ->when($data['to'] ?? null, fn ($query, $v) => $query->whereDate('submitted_at', '<=', $v))),
            ])
            ->actions([Tables\Actions\ViewAction::make(), ...self::reviewActions()])
            ->defaultSort('submitted_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Tekshiruv')->columns(3)->schema([
                Infolists\Components\TextEntry::make('site.district.name')->label('Tuman'),
                Infolists\Components\TextEntry::make('site.address')->label('Obyekt'),
                Infolists\Components\TextEntry::make('staff.full_name')->label('Xodim'),
                Infolists\Components\TextEntry::make('result')->label('Natija')->badge()
                    ->state(fn (Inspection $record) => self::result($record))
                    ->color(fn (Inspection $record) => self::resultColor($record)),
                Infolists\Components\TextEntry::make('review_status')->label('Tasdiq holati')->badge()
                    ->formatStateUsing(fn ($state) => self::REVIEW[$state] ?? 'Talab etilmaydi')
                    ->color(fn ($state) => $state === Inspection::REVIEW_PENDING ? 'danger' : ($state === Inspection::REVIEW_APPROVED ? 'success' : 'gray')),
                Infolists\Components\TextEntry::make('review_note')->label(fn (Inspection $record) => $record->review_status === Inspection::REVIEW_REJECTED ? 'Rad etish sababi' : 'Moderator izohi')
                    ->visible(fn (Inspection $record) => (bool) $record->review_note),
                Infolists\Components\TextEntry::make('followup')->label('Izoh holati')->badge()
                    ->formatStateUsing(fn ($state) => Inspection::FOLLOWUPS[$state] ?? '—')
                    ->color(fn ($state) => self::followupColor($state))
                    ->visible(fn (Inspection $record) => (bool) $record->followup),
                Infolists\Components\TextEntry::make('rework_of')->label('Tuzatilgan natija')
                    ->formatStateUsing(fn ($state) => "Avval rad etilgan natija #{$state}")
                    ->url(fn (Inspection $record) => $record->rework_of ? self::getUrl('view', ['record' => $record->rework_of]) : null)
                    ->visible(fn (Inspection $record) => (bool) $record->rework_of),
                Infolists\Components\TextEntry::make('reworks')->label('Tuzatib yuborilgan')
                    ->state(fn (Inspection $record) => $record->reworks->map(fn (Inspection $r) => "#{$r->id} — ".$r->submitted_at?->format('d.m.Y H:i'))->join(', ') ?: null)
                    ->url(fn (Inspection $record) => ($r = $record->reworks->last()) ? self::getUrl('view', ['record' => $r->id]) : null)
                    ->visible(fn (Inspection $record) => $record->reworks->isNotEmpty()),
                Infolists\Components\TextEntry::make('submitted_at')->label('Yuborilgan')->dateTime('d.m.Y H:i'),
                Infolists\Components\TextEntry::make('distance_m')->label('Obyektdan masofa (m)')->placeholder('—'),
                Infolists\Components\TextEntry::make('device')->label('Qurilma')->formatStateUsing(fn ($state) => \App\Support\Device::label($state))->columnSpanFull(),
                Infolists\Components\TextEntry::make('reason')->label(fn (Inspection $record) => $record->exempt_type ? 'Izoh' : ($record->in_progress ? 'Buzish jarayoni haqida izoh' : 'Buzilmaganlik sababi'))->columnSpanFull()->visible(fn (Inspection $record) => ! $record->is_demolished),
                Infolists\Components\TextEntry::make('flags')->label('Ogohlantirishlar')->badge()->color('danger')
                    ->formatStateUsing(fn ($state) => \App\Support\Gps::flagLabel($state))->visible(fn (Inspection $record) => $record->suspicious),
            ]),
            Infolists\Components\Section::make('Rasm va videolar')->schema([
                Infolists\Components\ViewEntry::make('media')->hiddenLabel()->view('filament.infolists.inspection-media'),
            ]),
            // izoh bo'yicha xodim yuborgan bajarilgan ish (rasm, video, izoh)
            Infolists\Components\RepeatableEntry::make('followups')->label('Izoh bo‘yicha bajarilgan ish')->columnSpanFull()
                ->visible(fn (Inspection $record) => $record->followups->isNotEmpty())
                ->schema([
                    Infolists\Components\TextEntry::make('submitted_at')->label('Yuborilgan')->dateTime('d.m.Y H:i'),
                    Infolists\Components\TextEntry::make('distance_m')->label('Obyektdan masofa (m)')->placeholder('—'),
                    Infolists\Components\TextEntry::make('reason')->label('Xodim izohi')->columnSpanFull(),
                    Infolists\Components\ViewEntry::make('media')->hiddenLabel()->view('filament.infolists.inspection-media')->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInspections::route('/'),
            'view' => Pages\ViewInspection::route('/{record}'),
        ];
    }
}
