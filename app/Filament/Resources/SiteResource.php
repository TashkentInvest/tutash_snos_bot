<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteResource\Pages;
use App\Filament\Resources\SiteResource\RelationManagers;
use App\Models\Mahalla;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    // Kuzatuvchi obyektlarni ko'radi va yangi obyekt qo'sha oladi; tahrirlash va o'chirish — faqat admin.
    public static function canCreate(): bool
    {
        return User::currentIsAdmin() || (bool) auth()->user()?->is_viewer;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return User::currentIsAdmin();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return User::currentIsAdmin();
    }

    public static function canDeleteAny(): bool
    {
        return User::currentIsAdmin();
    }

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $modelLabel = 'obyekt';

    protected static ?string $pluralModelLabel = 'Obyektlar';

    protected static ?int $navigationSort = 1;

    private const STATUS = [Site::NOT_DEMOLISHED => 'Buzilmagan', Site::IN_PROGRESS => 'Jarayonda', Site::DEMOLISHED => 'Buzilgan', Site::EXEMPT => 'Buzilmaydi (sud qarori bekor)'];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Joylashuv')->columns(2)->schema([
                Forms\Components\Select::make('district_id')->label('Tuman')->relationship('district', 'name')->required()->live()
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('mahalla_id', null)), // boshqa tumanning MFY'si saqlanib qolmasin
                Forms\Components\Select::make('mahalla_id')->label('MFY')
                    ->options(fn (Forms\Get $get) => Mahalla::where('district_id', $get('district_id'))->orderBy('name')->pluck('name', 'id'))
                    ->rule(fn (Forms\Get $get) => \Illuminate\Validation\Rule::exists('mahallas', 'id')->where('district_id', $get('district_id')))
                    ->searchable(),
                Forms\Components\TextInput::make('address')->label('Manzil (ko\'cha)')->required()->columnSpanFull(),
                Forms\Components\ViewField::make('map_picker')->hiddenLabel()->dehydrated(false)
                    ->view('filament.forms.site-map-picker')->columnSpanFull(),
                Forms\Components\TextInput::make('lat')->label('Kenglik (lat)')->numeric()->minValue(-90)->maxValue(90),
                Forms\Components\TextInput::make('lng')->label('Uzunlik (lng)')->numeric()->minValue(-180)->maxValue(180),
            ]),
            // telefon rasmi bir necha MB — yuklashdan oldin brauzerda 1920 px gacha kichraytiriladi (nginx chegarasi 5 MB)
            Forms\Components\Section::make('Avvalgi holat rasmlari')->description('Obyektning buzishdan oldingi holati. Xodimlar tekshiruvda obyektni tanib olishi uchun.')->schema([
                Forms\Components\FileUpload::make('photos')->hiddenLabel()->multiple()->image()->maxFiles(10)->maxSize(5000)
                    ->disk(config('tutash.media_disk'))->directory('sites')->visibility('private')
                    ->imageResizeMode('contain')->imageResizeTargetWidth('1920')->imageResizeTargetHeight('1920')->imageResizeUpscale(false)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                    ->panelLayout('grid')->reorderable()->appendFiles()
                    // yopiq diskdagi rasm ko'rinishi himoyalangan manzil orqali
                    ->getUploadedFileUsing(fn ($component, string $file, $storedFileNames) => [
                        'name' => basename($file), 'size' => 0, 'type' => null,
                        'url' => route('admin.site-photo', ['path' => $file]),
                    ])
                    ->helperText('Ko‘pi bilan 10 ta rasm (JPG, PNG). Katta rasmlar avtomatik kichraytiriladi.'),
            ]),
            Forms\Components\Section::make('Huquqbuzar')->columns(2)->schema([
                Forms\Components\TextInput::make('violator_name')->label('F.I.SH / tashkilot'),
                Forms\Components\TextInput::make('violator_passport')->label('Pasport'),
                Forms\Components\TextInput::make('org_inn')->label('STIR'),
                Forms\Components\TextInput::make('area_m2')->label('Egallangan maydon (m²)')->numeric(),
            ]),
            Forms\Components\Section::make('Sud va holat')->columns(2)->schema([
                Forms\Components\Toggle::make('is_tutash')->label('Tutash'),
                Forms\Components\Select::make('status')->label('Holat')->required()->live()
                    // ko'ruvchi (viewer) faqat buzilmagan/jarayonda obyekt qo'sha oladi — buzilgan holat moderator tasdig'i orqali
                    ->options(fn () => User::currentIsAdmin() ? self::STATUS : array_intersect_key(self::STATUS, array_flip([Site::NOT_DEMOLISHED, Site::IN_PROGRESS])))
                    ->default(Site::NOT_DEMOLISHED)
                    ->afterStateUpdated(fn ($state, Forms\Get $get, Forms\Set $set) => $state === Site::DEMOLISHED && ! $get('demolished_at') && $set('demolished_at', today()->toDateString())),
                Forms\Components\DatePicker::make('court_date')->label('Sud qarori sanasi'),
                Forms\Components\TextInput::make('court_number')->label('Sud qarori raqami'),
                Forms\Components\TextInput::make('court_status')->label('Sud holati'),
                // buzilgan obyektda sana shart: sanasiz bo'lsa o'tgan kunlar svodida har kuni "buzilgan" bo'lib ko'rinadi
                Forms\Components\DatePicker::make('demolished_at')->label('Buzilgan sana')
                    ->required(fn (Forms\Get $get) => $get('status') === Site::DEMOLISHED),
            ]),
        ]);
    }

    /**
     * Obyekt haqida ixcham blok — obyekt sahifasida ham, tekshiruv sahifasida ham bir xil (ma'lumot takrorlanmaydi).
     * $site — yozuvdan obyektni oladi (obyekt sahifasida o'zi, tekshiruvda $record->site). Bo'sh maydonlar ko'rsatilmaydi.
     */
    public static function summary(\Closure $site, bool $withLink = false): Infolists\Components\Section
    {
        $text = fn (string $key, string $label, \Closure $value) => Infolists\Components\TextEntry::make($key)->label($label)
            ->state(fn ($record) => ($s = $site($record)) ? $value($s) : null)
            ->hidden(fn ($record) => blank(($s = $site($record)) ? $value($s) : null));

        return Infolists\Components\Section::make('Obyekt')->columns(4)->compact()
            ->headerActions($withLink ? [
                Infolists\Components\Actions\Action::make('openSite')->label('Obyekt sahifasi')->icon('heroicon-o-arrow-top-right-on-square')->link()
                    ->url(fn ($record) => ($s = $site($record)) ? self::getUrl('view', ['record' => $s]) : null),
            ] : [])
            ->schema([
                $text('o_approval', 'Kadastr qo‘shgan', fn (Site $s) => $s->added_by_staff_id
                    ? trim(($s->addedBy?->full_name ?? '').' · '.match ($s->approval) {
                        Site::APPROVAL_PENDING => 'tasdiq kutilmoqda',
                        Site::APPROVAL_REJECTED => 'rad etilgan: '.$s->approval_note,
                        default => 'tasdiqlangan',
                    }.($s->updated_by_staff_id ? ' · oxirgi tahrir: '.$s->updatedBy?->full_name.' ('.$s->updated_at?->format('d.m.Y H:i').')' : '')
                    .collect([
                        $s->mahalla?->created_by_staff_id ? 'MFY qo‘shgan: '.$s->mahalla->createdBy?->full_name : null,
                        $s->street?->created_by_staff_id ? 'ko‘cha qo‘shgan: '.$s->street->createdBy?->full_name : null,
                    ])->filter()->map(fn ($t) => ' · '.$t)->join('')) : null)->columnSpanFull()->color(fn ($record) => $site($record)?->approval ? 'danger' : null),
                $text('o_place', 'Manzil', fn (Site $s) => collect([$s->district?->name, $s->mahalla?->name, $s->address !== $s->mahalla?->name ? $s->address : null])
                    ->filter(fn ($v) => filled($v) && $v !== '—')->join(' · '))->columnSpan(2),
                Infolists\Components\TextEntry::make('o_status')->label('Holat')->badge()
                    ->state(fn ($record) => ($s = $site($record)) ? ($s->status !== Site::DEMOLISHED && $s->pendingReview ? 'Ko‘rib chiqilmoqda' : (self::STATUS[$s->status] ?? $s->status)) : null)
                    ->color(fn ($record) => ($s = $site($record)) && $s->status === Site::DEMOLISHED ? 'success' : (($s?->pendingReview) ? 'info' : ($s?->status === Site::IN_PROGRESS ? 'primary' : 'warning'))),
                $text('o_area', 'Maydon', fn (Site $s) => $s->area_m2 ? number_format((float) $s->area_m2, 0, ',', ' ').' m²'.($s->is_tutash ? '' : ' · tutash emas') : null),

                // subyekt: pasport va STIR bazada shifrlangan, bu yerda ochiq
                $text('o_violator', 'Huquqbuzar', fn (Site $s) => $s->violator_name)->columnSpan(2),
                $text('o_passport', 'Pasport', fn (Site $s) => $s->violator_passport)->copyable(),
                $text('o_inn', 'STIR', fn (Site $s) => $s->org_inn)->copyable(),

                $text('o_court', 'Sud qarori', fn (Site $s) => collect([$s->court_number, $s->court_date?->format('d.m.Y')])->filter()->join(' · ')),
                $text('o_court_status', 'Sud holati', fn (Site $s) => $s->court_status),
                $text('o_demolished', 'Buzilgan sana', fn (Site $s) => $s->demolished_at?->format('d.m.Y')),
                $text('o_responsible', 'Tuman mas’uli', fn (Site $s) => $s->district ? collect([$s->district->responsible_name, $s->district->phoneFormatted()])->filter()->join(', ') : null),
                Infolists\Components\TextEntry::make('o_map')->label('Xarita')
                    ->state(fn ($record) => ($s = $site($record)) && $s->lat ? 'Xaritada ochish' : null)
                    ->url(fn ($record) => ($s = $site($record)) && $s->lat ? \App\Support\Maps::url($s->lat, $s->lng) : null, true)
                    ->hidden(fn ($record) => ! $site($record)?->lat),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            self::summary(fn (Site $record) => $record),
            Infolists\Components\Section::make('Avvalgi holat rasmlari')->visible(fn (Site $record) => ! empty($record->photos))->schema([
                Infolists\Components\ViewEntry::make('photos')->hiddenLabel()->view('filament.infolists.site-photos'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['district', 'mahalla', 'pendingReview', 'addedBy']))
            ->columns([
                // kadastr xodimi qo'shgan, admin tasdig'ini kutayotgan / rad etilgan obyektlar
                Tables\Columns\TextColumn::make('approval')->label('Tasdiq')->badge()
                    ->formatStateUsing(fn ($state) => $state === Site::APPROVAL_REJECTED ? 'Rad etilgan' : 'Tasdiq kutilmoqda')
                    ->color(fn ($state) => $state === Site::APPROVAL_REJECTED ? 'danger' : 'warning')
                    ->description(fn (Site $record) => $record->addedBy ? 'Kadastr: '.$record->addedBy->full_name : null)
                    ->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('district.name')->label('Tuman')->sortable(),
                Tables\Columns\TextColumn::make('mahalla.name')->label('MFY')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('address')->label('Manzil')->searchable()->limit(40)->tooltip(fn (Site $record) => $record->address),
                Tables\Columns\TextColumn::make('violator_name')->label('Huquqbuzar')->searchable()->limit(30),
                Tables\Columns\TextColumn::make('area_m2')->label('m²')->numeric(decimalPlaces: 0)->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state, Site $record) => $state !== Site::DEMOLISHED && $record->pendingReview ? 'Ko‘rib chiqilmoqda' : (self::STATUS[$state] ?? $state))
                    ->color(fn ($state, Site $record) => $state === Site::DEMOLISHED ? 'success' : ($record->pendingReview ? 'info' : ($state === Site::IN_PROGRESS ? 'primary' : 'warning'))),
                Tables\Columns\TextColumn::make('last_inspected_at')->label('Oxirgi tekshiruv')->dateTime('d.m.Y H:i')->sortable()->placeholder('Tekshirilmagan'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('approval')->label('Kadastr qo‘shgan')
                    ->options([Site::APPROVAL_PENDING => 'Tasdiq kutilmoqda', Site::APPROVAL_REJECTED => 'Rad etilgan', 'approved' => 'Tasdiqlangan'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        Site::APPROVAL_PENDING, Site::APPROVAL_REJECTED => $query->where('approval', $data['value']),
                        'approved' => $query->whereNull('approval')->whereNotNull('added_by_staff_id'),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('district_id')->label('Tuman')->relationship('district', 'name'),
                Tables\Filters\SelectFilter::make('status')->label('Holat')->options(self::STATUS),
                Tables\Filters\Filter::make('in_review')->label('Ko‘rib chiqilmoqda')->toggle()->query(fn ($query) => $query->whereHas('pendingReview')),
                Tables\Filters\TernaryFilter::make('inspected')->label('Tekshirilgan')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('last_inspected_at'),
                        false: fn ($query) => $query->whereNull('last_inspected_at'),
                    ),
            ])
            ->actions([self::approveAction(Tables\Actions\Action::class), self::rejectAction(Tables\Actions\Action::class),
                Tables\Actions\ViewAction::make(), Tables\Actions\EditAction::make()])
            ->defaultSort('id');
    }

    /** Tasdiq kutayotganlar ham admin panelda ko'rinadi (botda, svodda va boshqa sahifalarda — yo'q). */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope('approved');
    }

    public static function getNavigationBadge(): ?string
    {
        return ($n = Site::withoutGlobalScope('approved')->where('approval', Site::APPROVAL_PENDING)->count()) ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Kadastr qo‘shgan, tasdiq kutilmoqda';
    }

    /** Kadastr qo'shgan obyektni tasdiqlash: ro'yxatga, xodimlarga va svodga kiradi; kadastr xodimiga xabar. */
    public static function approveAction(string $class)
    {
        return $class::make('approveSite')->label('Tasdiqlash')->icon('heroicon-o-check-circle')->color('success')
            ->requiresConfirmation()->modalHeading('Obyekt tasdiqlansinmi?')
            ->modalDescription('Obyekt umumiy ro‘yxatga, xodimlarga va svod hisobotiga qo‘shiladi.')
            ->visible(fn (Site $record) => $record->approval === Site::APPROVAL_PENDING && User::currentIsAdmin())
            ->action(function (Site $record) {
                $record->update(['approval' => null, 'approval_note' => null]);
                \App\Models\AuditLog::record('site.cadastre_approved', null, ['site' => $record->id, 'by' => auth()->id()]);
                self::notifyAdder($record, '✅ <b>Siz qo‘shgan obyekt tasdiqlandi</b> va ro‘yxatga kiritildi.');
                \Filament\Notifications\Notification::make()->title('Tasdiqlandi')->success()->send();
            });
    }

    /** Rad etish: sabab bilan; kadastr xodimiga botda sabab va «Tahrirlash» tugmasi boradi. */
    public static function rejectAction(string $class)
    {
        return $class::make('rejectSite')->label('Rad etish')->icon('heroicon-o-x-circle')->color('danger')
            ->form([Forms\Components\Textarea::make('note')->label('Sabab (kadastr xodimiga boradi)')->required()->minLength(5)->maxLength(1000)])
            ->visible(fn (Site $record) => $record->approval === Site::APPROVAL_PENDING && User::currentIsAdmin())
            ->action(function (Site $record, array $data) {
                $record->update(['approval' => Site::APPROVAL_REJECTED, 'approval_note' => trim($data['note'])]);
                \App\Models\AuditLog::record('site.cadastre_rejected', null, ['site' => $record->id, 'by' => auth()->id()]);
                self::notifyAdder($record, "❌ <b>Siz qo‘shgan obyekt rad etildi.</b>\n💬 Sabab: <i>".e($data['note']).'</i>', true);
                \Filament\Notifications\Notification::make()->title('Rad etildi')->success()->send();
            });
    }

    private static function notifyAdder(Site $site, string $html, bool $withEdit = false): void
    {
        $to = $site->addedBy?->telegram_id;
        if (! $to) {
            return;
        }
        try {
            app(\SergiX44\Nutgram\Nutgram::class)->sendMessage(\App\Telegram\Support\Script::apply($html."\n📍 ".e($site->address)), array_filter([
                'chat_id' => $to, 'parse_mode' => \SergiX44\Nutgram\Telegram\Attributes\ParseMode::HTML,
                'reply_markup' => $withEdit ? \App\Telegram\Support\Ui::markup([[\App\Telegram\Handlers\BrowseHandler::siteFormButton('✏️ Tahrirlash', ['site' => $site->id])]]) : null,
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function getRelations(): array
    {
        return [RelationManagers\InspectionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'view' => Pages\ViewSite::route('/{record}'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}
