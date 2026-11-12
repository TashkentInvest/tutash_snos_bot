<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffResource\Pages;
use App\Models\Staff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StaffResource extends Resource
{
    protected static ?string $model = Staff::class;

    // Kuzatuvchi (is_viewer) bu bo'limni ko'rmaydi — faqat admin.
    public static function canViewAny(): bool
    {
        return User::currentIsAdmin();
    }

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Sozlamalar';

    protected static ?string $modelLabel = 'xodim';

    protected static ?string $pluralModelLabel = 'Xodimlar';

    private const STATUSES = [
        Staff::ACTIVE => 'Faol',
        Staff::REGISTERING => 'Ro\'yxatdan o\'tmoqda',
        Staff::AWAITING_PHONE => 'Telefon kutilmoqda',
        Staff::REJECTED => 'Rad etilgan',
        Staff::BLOCKED => 'Bloklangan',
        Staff::CITIZEN => 'Aholi',
    ];

    public const ROLES = [
        Staff::ROLE_EMPLOYEE => 'Xodim',
        Staff::ROLE_CONTRACT => 'Shartnoma',
        Staff::ROLE_CADASTRE => 'Kadastr',
        Staff::ROLE_CITIZEN => 'Aholi',
    ];

    /** Xodim — obyektlar bilan ishlaydi; Shartnoma — faqat davomat va har soatlik qayd. */
    public const ROLE_HINT = 'Xodim — obyektlarni tekshiradi. Shartnoma — faqat davomat va soatlik qayd.';

    public const CHECKIN_HINT = 'Har soatda joylashuv va qisqa video yuboradi.';

    public static function form(Form $form): Form
    {
        $isEmployee = fn (Forms\Get $get) => $get('role') === Staff::ROLE_EMPLOYEE;
        // tumanga biriktiriladiganlar: xodim va kadastr
        $hasDistricts = fn (Forms\Get $get) => in_array($get('role'), Staff::DISTRICT_ROLES, true);
        $isWorker = fn (Forms\Get $get) => in_array($get('role'), Staff::WORK_ROLES, true);

        return $form->schema([
            Forms\Components\Section::make('Xodim')->columns(3)->schema([
                Forms\Components\TextInput::make('full_name')->label('F.I.SH')->required(),
                Forms\Components\TextInput::make('phone')->label('Telefon')->disabled(),
                Forms\Components\Select::make('status')->label('Holat')->options(self::STATUSES)->required()->native(false),
            ]),

            Forms\Components\Section::make('Vazifasi')->columns(2)->schema([
                Forms\Components\Radio::make('role')->label('Kim')->live()->columnSpanFull()->inline()->inlineLabel(false)
                    ->options(self::ROLES)
                    ->descriptions([
                        Staff::ROLE_EMPLOYEE => 'Obyektlarni tekshiradi',
                        Staff::ROLE_CONTRACT => 'Faqat davomat va soatlik qayd',
                        Staff::ROLE_CADASTRE => 'O‘z tumanida tekshiradi va yangi obyekt qo‘shadi (admin tasdiqlaydi)',
                        Staff::ROLE_CITIZEN => 'Faqat ko‘radi',
                    ]),
                Forms\Components\Toggle::make('attendance_required')->label('Ertalabki davomat')
                    ->helperText(config('tutash.attendance.send_at').'–'.config('tutash.attendance.deadline').', ish kunlari')
                    ->visible($isWorker),
                // shartnoma xodimida soatlik qayd doim bor (rol bo'yicha) — belgi yoqilgan va o'zgarmas ko'rinadi
                Forms\Components\Toggle::make('hourly_checkin')->label('Soatlik qayd')
                    ->helperText(fn (Forms\Get $get) => $get('role') === Staff::ROLE_CONTRACT ? 'Shartnoma xodimida doim yoqilgan' : self::CHECKIN_HINT)
                    ->formatStateUsing(fn ($state, ?Staff $record) => $record?->isContract() ? true : (bool) $state)
                    ->disabled(fn (Forms\Get $get) => $get('role') === Staff::ROLE_CONTRACT)
                    ->visible($isWorker),
                // hisobot oluvchilar endi bitta joyda: Sozlamalar → Hisobotlar (har hisobot alohida)
                Forms\Components\Placeholder::make('reports_hint')->label('Hisobotlar')
                    ->content(new \Illuminate\Support\HtmlString('Kimga qaysi hisobot borishi — <a href="'.\App\Filament\Pages\ReportSettings::getUrl().'" style="color:rgb(37,99,235);">Sozlamalar → Hisobotlar</a>')),
            ]),

            Forms\Components\Section::make('Hududlar')->schema([
                Forms\Components\Toggle::make('is_global')->label('Barcha tumanlar (admin)')->live()->visible($isEmployee),
                // telefon orqali biriktiriladi (Staff::assignDistricts): tanlov tumanlarning «Qo'shimcha xodimlar» ro'yxatiga yoziladi
                Forms\Components\Select::make('district_ids')->label('Tumanlar')->multiple()
                    ->options(fn () => \App\Models\District::orderBy('name')->pluck('name', 'id'))
                    ->afterStateHydrated(fn ($component, ?Staff $record) => $component->state($record?->districts()->pluck('districts.id')->all() ?? []))
                    ->visible(fn (Forms\Get $get) => $hasDistricts($get) && ! ($get('role') === Staff::ROLE_EMPLOYEE && $get('is_global')))
                    ->disabled(fn (?Staff $record) => ! $record?->phone)
                    ->helperText(fn (?Staff $record) => $record?->phone ? null : 'Avval botda ro‘yxatdan o‘tishi kerak.'),
            ])->visible($hasDistricts),

            // faqat bloklangan bo'lsa ko'rinadi: 0 qilinsa xodim qayta urina oladi
            Forms\Components\TextInput::make('failed_attempts')->label('Noto‘g‘ri urinishlar (0 — qayta ochish)')->numeric()->minValue(0)
                ->default(0)->dehydrateStateUsing(fn ($state) => (int) ($state ?? 0))
                ->visible(fn (?Staff $record) => (int) $record?->failed_attempts > 0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('F.I.SH')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('Telefon')->searchable(),
                Tables\Columns\TextColumn::make('role')->label('Rol')->badge()->formatStateUsing(fn ($state) => self::ROLES[$state] ?? '—')
                    ->color(fn ($state) => match ($state) { Staff::ROLE_CONTRACT => 'warning', Staff::ROLE_CADASTRE => 'info', Staff::ROLE_CITIZEN => 'gray', default => 'primary' }),
                // barcha tumanlarni ko'radigan adminda 12 ta nishon o'rniga bitta yozuv; boshqalarda 3 tadan keyin "yana N ta"
                Tables\Columns\TextColumn::make('districts.name')->label('Tumanlar')->badge()
                    ->state(fn (Staff $record) => $record->is_global ? ['Barcha tumanlar'] : $record->districts->pluck('name')->all())
                    ->color(fn (string $state) => $state === 'Barcha tumanlar' ? 'success' : 'primary')
                    ->limitList(3)->expandableLimitedList(),
                Tables\Columns\TextColumn::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        Staff::ACTIVE => 'success',
                        Staff::BLOCKED, Staff::REJECTED => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\IconColumn::make('hourly_checkin')->label('Soatlik qayd')->boolean()
                    ->state(fn (Staff $record) => $record->doesHourlyCheckin())
                    ->tooltip(fn (Staff $record) => match (true) {
                        $record->isContract() => 'Shartnoma xodimi — soatlik qayd doim yoqilgan',
                        $record->role === Staff::ROLE_EMPLOYEE => 'Bosing: soatlik qaydni yoqish / o‘chirish',
                        default => null,
                    })
                    ->action(fn (Staff $record) => $record->role === Staff::ROLE_EMPLOYEE && $record->update(['hourly_checkin' => ! $record->hourly_checkin])),
                Tables\Columns\TextColumn::make('inspections_count')->label('Tekshiruvlar')->counts('inspections'),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Oxirgi faollik')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('waiting')->label('Tasdiq kutayotganlar (raqami ro‘yxatda yo‘q)')
                    ->query(fn ($query) => $query->whereIn('status', [Staff::REGISTERING, Staff::REJECTED])->whereNotNull('phone')->whereNotNull('telegram_id')),
                Tables\Filters\SelectFilter::make('status')->label('Holat')->options(self::STATUSES),
                Tables\Filters\SelectFilter::make('role')->label('Rol')->options(self::ROLES),
                Tables\Filters\Filter::make('hourly')->label('Soatlik qayd yuritiladi')
                    ->query(fn ($query) => $query->hourlyCheckin()),
                // o'chirilganlar (arxiv): natijalari, davomati va videolari saqlangan
                Tables\Filters\TrashedFilter::make()->label('O‘chirilganlar')
                    ->placeholder('O‘chirilmaganlar')->trueLabel('Hammasi')->falseLabel('Faqat o‘chirilganlar'),
            ])
            ->actions([
                Tables\Actions\RestoreAction::make()->label('Tiklash')
                    ->modalDescription('Xodim ro‘yxatga qaytariladi (holati va tumanlari o‘zgarmaydi).'),
                Tables\Actions\Action::make('notes')->icon('heroicon-o-bell-alert')->color('warning')
                    ->label(fn (Staff $record) => 'Izohlar ('.\App\Support\FollowupReminder::open()->where('staff_id', $record->id)->count().')')
                    ->visible(fn (Staff $record) => \App\Models\User::currentIsAdmin() && ! $record->trashed()
                        && \App\Support\FollowupReminder::open()->where('staff_id', $record->id)->exists())
                    ->modalHeading(fn (Staff $record) => 'Moderator izohlari — '.$record->full_name)->modalWidth('4xl')
                    ->modalContent(fn (Staff $record) => view('filament.pages.followup-reminders', [
                        'items' => \App\Support\FollowupReminder::open()->where('staff_id', $record->id)->orderBy('reviewed_at')->get()->values(),
                    ]))
                    ->modalSubmitActionLabel('Shu xodimga eslatma yuborish')
                    ->action(function (Staff $record) {
                        $sent = \App\Support\FollowupReminder::open()->where('staff_id', $record->id)->get()
                            ->filter(fn ($in) => \App\Support\FollowupReminder::send($in, auth()->id()))->count();
                        \Filament\Notifications\Notification::make()->title($sent ? "Eslatma yuborildi: {$sent} ta" : 'Yuborilmadi')->{$sent ? 'success' : 'warning'}()->send();
                    }),
                Tables\Actions\EditAction::make()->hidden(fn (Staff $record) => $record->trashed())->using(function (Staff $record, array $data) {
                    $ids = $data['district_ids'] ?? null;
                    unset($data['district_ids']);
                    // «soatlik qayd» belgisi faqat oddiy xodimda; rol o'zgarsa eski belgi yashirin qolib ketmasin
                    ($data['role'] ?? $record->role) === Staff::ROLE_EMPLOYEE || $data['hourly_checkin'] = false;
                    // «Barcha tumanlar» faqat xodimda ko'rinadi; kadastrda yashirin qolib, tanlangan tumanlarni bekor qilmasin
                    ($data['role'] ?? $record->role) === Staff::ROLE_CADASTRE && $data['is_global'] = false;
                    $record->update($data);
                    $ids !== null && ! $record->is_global && $record->assignDistricts($ids);

                    return $record;
                }),
                // raqami ro'yxatda topilmagan, botda kutib turgan odam — rol va tuman berib tasdiqlash (odamga botda xabar boradi)
                Tables\Actions\Action::make('approveAccess')->label('Tasdiqlash')->color('success')->icon('heroicon-o-check-badge')->button()
                    ->visible(fn (Staff $record) => in_array($record->status, [Staff::REGISTERING, Staff::REJECTED], true) && $record->phone && $record->telegram_id && ! $record->trashed())
                    ->modalHeading(fn (Staff $record) => 'Tasdiqlash — '.$record->full_name.' (+998 '.$record->phone.')')
                    ->modalDescription('Raqami ruxsat etilgan ro‘yxatda topilmagani uchun kutib turibdi. Rol va tumanni tanlang — unga botda xabar boradi.')
                    ->modalSubmitActionLabel('Tasdiqlash')
                    ->form([
                        Forms\Components\Radio::make('role')->label('Rol')->required()->live()->default(Staff::ROLE_EMPLOYEE)
                            ->options([Staff::ROLE_EMPLOYEE => 'Xodim — obyektlarni tekshiradi', Staff::ROLE_CONTRACT => 'Shartnoma — davomat va soatlik qayd',
                                Staff::ROLE_CADASTRE => 'Kadastr — o‘z tumanida tekshiradi va obyekt qo‘shadi']),
                        Forms\Components\Select::make('district_ids')->label('Tumanlar')->multiple()->required()
                            ->options(fn () => \App\Models\District::orderBy('name')->pluck('name', 'id')),
                    ])
                    ->action(function (Staff $record, array $data) {
                        \App\Support\AccessRequest::approve($record, $data['role'], array_map('intval', $data['district_ids']), auth()->id());
                        Notification::make()->title('Tasdiqlandi — botda xabar yuborildi')->success()->send();
                    }),
                Tables\Actions\Action::make('block')->label('Bloklash')->color('danger')->icon('heroicon-o-no-symbol')
                    ->requiresConfirmation()->visible(fn (Staff $record) => $record->status !== Staff::BLOCKED && ! $record->trashed())
                    ->action(fn (Staff $record) => $record->update(['status' => Staff::BLOCKED])),
                Tables\Actions\DeleteAction::make()->label('O‘chirish')
                    ->modalDescription('Xodim ro‘yxatdan olib tashlanadi va botdan foydalana olmaydi. Natijalari, davomati va videolari arxivda saqlanadi — «O‘chirilganlar» filtri orqali topiladi va tiklanadi. Botga qayta kirsa, yangidan ro‘yxatdan o‘tadi.')
                    ->successNotificationTitle('Xodim o‘chirildi (arxivga o‘tkazildi)')
                    ->using(fn (Staff $record) => self::deleteStaff($record)),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    /** O'chirish — arxivga o'tkazish (soft delete): faqat tugallanmagan qoralamalar o'chadi, tarix saqlanadi. */
    public static function deleteStaff(Staff $staff): bool
    {
        DB::transaction(function () use ($staff) {
            $staff->inspections()->where('status', \App\Models\Inspection::DRAFT)->get()->each->delete(); // qoralamalar fayllari bilan
            $staff->delete();
        });

        return true;
    }

    /** O'chirilganlar (arxiv) ham filtr orqali ko'rinishi uchun. */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([\Illuminate\Database\Eloquent\SoftDeletingScope::class]);
    }

    public static function canCreate(): bool
    {
        return false; // xodimlar botda o'zi ro'yxatdan o'tadi
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageStaff::route('/'), 'cadastre' => Pages\ManageCadastreStaff::route('/cadastre')];
    }

    /** Menyuda ikkita band: «Xodimlar» (hammasi) va «Kadastr xodimlari» (faqat kadastr, tasdiq kutayotgan obyektlar soni bilan). */
    public static function getNavigationItems(): array
    {
        $items = parent::getNavigationItems();
        $items[0]->isActiveWhen(fn () => request()->routeIs(static::getRouteBaseName().'.index'));
        // raqami ro'yxatda topilmay botda kutib turganlar — ko'zga tashlansin
        ($waiting = \App\Support\AccessRequest::waiting()->count()) && $items[0]->badge((string) $waiting, 'danger')
            ->badgeTooltip('Tasdiq kutayotganlar: raqami ro‘yxatda topilmagan')
            ->url(static::getUrl('index', ['tableFilters' => ['waiting' => ['isActive' => true]]]));
        $pending = \App\Models\Site::withoutGlobalScope('approved')->where('approval', \App\Models\Site::APPROVAL_PENDING)->count();

        return [...$items, \Filament\Navigation\NavigationItem::make('Kadastr xodimlari')
            ->group(static::getNavigationGroup())->icon('heroicon-o-map')->sort(static::getNavigationSort())
            ->isActiveWhen(fn () => request()->routeIs(static::getRouteBaseName().'.cadastre'))
            ->badge($pending ?: null, 'warning')->badgeTooltip('Kadastr qo‘shgan, tasdiq kutayotgan obyektlar')
            ->url(static::getUrl('cadastre'))];
    }
}
