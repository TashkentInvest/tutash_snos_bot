<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\ReportSetting;
use App\Models\User;
use App\Support\FollowupReminder;
use App\Support\ReportPeriod;
use App\Telegram\Support\AttendanceReport;
use App\Telegram\Support\InspectionReport;
use App\Telegram\Support\Script;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use SergiX44\Nutgram\Nutgram;

/**
 * Bosh sahifa: kun tanlanadi (hisobot davri — oldingi kun 18:00 dan shu kun 18:00 gacha), vidjetlar va hisobot tugmalari
 * shu kun bo'yicha ishlaydi. Hisobotlarni istalgan payt adminlarga qo'lda yuborish mumkin (avval namuna ko'rsatiladi).
 */
class Dashboard extends BaseDashboard
{
    /** «Faqat soatlik qayd» foydalanuvchisi bosh sahifa o'rniga to'g'ri o'z jadvaliga o'tadi. */
    public function mount(): void
    {
        // cheklangan foydalanuvchi bosh sahifa o'rniga to'g'ri o'z bo'limiga
        match (true) {
            User::currentIsCheckinsOnly() => $this->redirect(CheckinBoard::getUrl()),
            User::currentIsAttendanceOnly() => $this->redirect(\App\Filament\Resources\AttendanceResource::getUrl('index')),
            User::currentIsLeasesOnly() => $this->redirect(\App\Filament\Resources\LeaseContractResource::getUrl('index')),
            default => null,
        };
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! User::currentIsLimited();
    }

    public function getWidgets(): array
    {
        return User::currentIsLimited() ? [] : parent::getWidgets();
    }

    use HasFiltersForm;

    // sahifa har ochilganda joriy davr ko'rsatiladi (eski tanlov sessiyada saqlanib qolmaydi)
    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    public function filtersForm(Form $form): Form
    {
        $current = ReportPeriod::current();

        return $form->schema([
            Section::make()->schema([
                DatePicker::make('day')->label('Kun')->native(false)->displayFormat('d.m.Y')
                    // bugungi sana 00:00 gacha; 18:00 dan keyin ertangi davrni (yangi natijalar) ham tanlash mumkin
                    ->default($current->day->toDateString())->maxDate(ReportPeriod::latest()->day->toDateString())
                    ->live()
                    ->helperText(fn ($state) => 'Hisobot davri: '.ReportPeriod::fromFilter($state)->label()
                        .(ReportPeriod::latest()->day->isTomorrow() ? ' · 18:00 dan keyingi natijalar ertangi sanada' : ' (har kuni 18:00 da yopiladi)')),
            ])->columns(3),
        ]);
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::fromFilter($this->filters['day'] ?? null);
    }

    /** Davomat — kalendar kuni bo'yicha (18:00 dan keyin ertangi davr tanlangan bo'lsa ham, bugungi davomat). */
    public static function attendanceDay(ReportPeriod $period): CarbonImmutable
    {
        return $period->day->greaterThan(today()) ? CarbonImmutable::today() : $period->day;
    }

    protected function getHeaderActions(): array
    {
        return [
            \App\Filament\Actions\PostAction::make(),
            self::broadcastAction(),
            // buzilish hisoboti: rasmlar va oluvchilar shu yerda tanlanadi (oluvchilar — Sozlamalar → Hisobotlar'dagilar oldindan belgilangan)
            Action::make('sendInspectionReport')->label('Buzilish hisobotini yuborish')->icon('heroicon-o-paper-airplane')
                ->modalHeading(fn () => 'Buzilish hisoboti — '.$this->period()->day->format('d.m.Y'))->modalWidth('3xl')->modalSubmitActionLabel('Yuborish')
                ->visible(fn () => User::currentIsAdmin())
                ->form(fn () => [
                    // gastronomik svod — alohida xabar (o'z izohi bilan) buzilish hisobotidan keyin
                    \Filament\Forms\Components\CheckboxList::make('images')->label('Rasmlar')
                        ->options(InspectionReport::IMAGES + ['gastro' => 'Gastronomik ko‘chalar — ijara svodi', 'designcode' => 'Dizayn-kod ko‘chalari svodi'])
                        ->default(array_keys(InspectionReport::IMAGES))->columns(2)->live()->required()
                        ->validationMessages(['required' => 'Kamida bitta rasmni belgilang.']),
                    \Filament\Forms\Components\Select::make('buffer')->label('Dizayn-kod: ko‘chadan masofa')->native(false)->live()
                        ->options(collect(\App\Support\DesignCodeSvod::BUFFERS)->mapWithKeys(fn ($m) => [$m => $m.' m ichida'])->all())
                        ->default(\App\Support\DesignCodeSvod::DEFAULT_BUFFER)->selectablePlaceholder(false)
                        ->visible(fn (\Filament\Forms\Get $get) => in_array('designcode', $get('images') ?? [], true)),
                    // tanlangan tumanlar — har biri alohida rasm: tuman ko'chalari kesimida
                    \Filament\Forms\Components\CheckboxList::make('dk_districts')->label('Dizayn-kod: tuman ko‘chalari kesimida ham yuborish')
                        ->helperText('Belgilangan har bir tuman uchun alohida rasm — svod sahifasidagi ko‘chalar jadvali kabi.')
                        ->options(fn (\Filament\Forms\Get $get) => \App\Telegram\Support\DesignCodeReport::districtOptions(\App\Support\DesignCodeSvod::buffer($get('buffer'))))
                        ->columns(3)->gridDirection('row')->bulkToggleable()->live()
                        ->visible(fn (\Filament\Forms\Get $get) => in_array('designcode', $get('images') ?? [], true)),
                    self::recipientsField(ReportSetting::SVOD),
                    \Filament\Forms\Components\Placeholder::make('preview')->label('Botda shunday ko‘rinadi')
                        ->content(function (\Filament\Forms\Get $get) {
                            $images = $get('images') ?? [];
                            $day = $this->period()->day;
                            $inspection = array_values(array_intersect(array_keys(InspectionReport::IMAGES), $images));
                            $html = $inspection ? self::previewHtml(array_map(fn ($img) => route('admin.svod.png', array_filter(['day' => $day->toDateString(),
                                'type' => $img === 'bajarilish' ? 'progress' : null])), $inspection), Script::apply(InspectionReport::caption($day)))->toHtml() : '';
                            $onsite = ReportSetting::gastroOptions()['onsite'];
                            in_array('gastro', $images, true) && $html .= '<div style="height:.75rem"></div>'.self::previewHtml(
                                [route('admin.lease-svod.png', ['day' => $day->toDateString(), 'onsite' => $onsite ? 1 : 0])],
                                Script::apply(\App\Telegram\Support\LeaseReport::caption($day, $onsite)))->toHtml();
                            $buffer = \App\Support\DesignCodeSvod::buffer($get('buffer'));
                            in_array('designcode', $images, true) && $html .= '<div style="height:.75rem"></div>'.self::previewHtml(
                                [route('admin.designcode-svod.png', ['buffer' => $buffer]),
                                    ...array_map(fn ($id) => route('admin.designcode-svod.png', ['buffer' => $buffer, 'district' => (int) $id]), $get('dk_districts') ?? [])],
                                Script::apply(\App\Telegram\Support\DesignCodeReport::caption($buffer, now())))->toHtml();

                            return new \Illuminate\Support\HtmlString($html);
                        }),
                ])
                ->action(fn (array $data) => $this->report('inspection', function (Nutgram $bot) use ($data) {
                    $images = $data['images'] ?? [];
                    $to = self::people($data['recipients'] ?? []);
                    $inspection = array_values(array_intersect(array_keys(InspectionReport::IMAGES), $images));
                    $sent = $inspection ? InspectionReport::send($bot, $this->period()->day, $to, $inspection) : 0;

                    $sent += in_array('gastro', $images, true) ? \App\Telegram\Support\LeaseReport::send($bot, $this->period()->day, $to) : 0;

                    return $sent + (in_array('designcode', $images, true)
                        ? \App\Telegram\Support\DesignCodeReport::send($bot, $to, now(), \App\Support\DesignCodeSvod::buffer($data['buffer'] ?? null), $data['dk_districts'] ?? []) : 0);
                })),

            // davomat hisobotlari: ertalabki davomat, soatlik qayd (soat tanlanadi) yoki kun yakuni — hammasi rasm
            Action::make('sendAttendanceReport')->label('Davomat hisobotini yuborish')->icon('heroicon-o-clipboard-document-list')->color('gray')
                ->modalHeading(fn () => 'Davomat hisoboti — '.self::attendanceDay($this->period())->format('d.m.Y'))->modalWidth('3xl')->modalSubmitActionLabel('Yuborish')
                ->visible(fn () => User::currentIsAdmin())
                ->form(fn () => [
                    \Filament\Forms\Components\Radio::make('type')->label('Qaysi hisobot')->inline()->required()->live()
                        ->options([ReportSetting::ATTENDANCE => 'Ertalabki davomat', ReportSetting::CHECKIN_HOURLY => 'Soatlik qayd', ReportSetting::CHECKIN_DAILY => 'Soatlik qayd — kun yakuni'])
                        ->default(ReportSetting::ATTENDANCE)
                        // tur o'zgarsa — shu hisobot sozlamasidagi oluvchilar
                        ->afterStateUpdated(fn ($state, \Filament\Forms\Set $set) => $set('recipients', array_map('strval', ReportSetting::for($state)->recipients ?? []))),
                    \Filament\Forms\Components\Select::make('slot')->label('Soat')->native(false)->live()
                        ->options(fn () => array_combine(\App\Models\Checkin::slots(), \App\Models\Checkin::slots()))
                        ->default(fn () => collect(\App\Models\Checkin::slots())->last(fn ($s) => today()->setTimeFromTimeString($s)->lessThanOrEqualTo(now())) ?? (\App\Models\Checkin::slots()[0] ?? null))
                        ->visible(fn (\Filament\Forms\Get $get) => $get('type') === ReportSetting::CHECKIN_HOURLY),
                    self::recipientsField(ReportSetting::ATTENDANCE),
                    \Filament\Forms\Components\Placeholder::make('preview')->label('Botda shunday ko‘rinadi')
                        ->content(function (\Filament\Forms\Get $get) {
                            $r = $this->attendanceReport($get('type') ?? ReportSetting::ATTENDANCE, $get('slot'));
                            $day = self::attendanceDay($this->period())->toDateString();

                            return $r['total'] ? self::previewHtml([match ($get('type')) {
                                ReportSetting::CHECKIN_HOURLY => route('admin.checkin-report.png', ['type' => 'hourly', 'slot' => $get('slot'), 'day' => $day]),
                                ReportSetting::CHECKIN_DAILY => route('admin.checkin-report.png', ['type' => 'daily', 'day' => $day]),
                                default => route('admin.attendance-report.png', ['day' => $day]),
                            }], Script::apply($r['caption'])) : new \Illuminate\Support\HtmlString('<span style="color:#d97706;">Bu kun uchun ma’lumot yo‘q — yuboriladigan narsa yo‘q.</span>');
                        }),
                ])
                ->action(function (array $data) {
                    $type = $data['type'] ?? ReportSetting::ATTENDANCE;
                    $this->report('attendance', fn (Nutgram $bot) => \App\Telegram\Support\CheckinReport::send(
                        $bot, $type, $this->attendanceReport($type, $data['slot'] ?? null), self::people($data['recipients'] ?? [])));
                }),
        ];
    }

    /**
     * Xabarnoma: bo'limlar (obyekt xodimlari, kadastr, shartnoma, gastro mas'ullar) va tumanlar tanlanadi —
     * ro'yxatda shular chiqadi (hammasi belgilangan), keraksizini olib tashlab yuboriladi.
     */
    public static function broadcastAction(): Action
    {
        return Action::make('broadcast')->label('Xabar yuborish')->icon('heroicon-o-megaphone')->color('gray')
            ->modalHeading('Xabarnoma')->modalDescription('Xabar botda yuboriladi. Bo‘lim va tumanni tanlang — ro‘yxatda shular chiqadi; keraksizini olib tashlang.')
            ->modalWidth('3xl')->modalSubmitActionLabel('Yuborish')
            ->visible(fn () => User::currentIsAdmin())
            ->form([
                ...\App\Filament\Actions\PostAction::audience(false),
                \Filament\Forms\Components\Textarea::make('text')->label('Xabar matni')->required()->minLength(3)->maxLength(3500)->rows(6),
            ])
            ->action(function (array $data) {
                $r = \App\Support\Broadcast::send($data['text'], array_map('intval', $data['people'] ?? []), auth()->id(),
                    ['groups' => $data['groups'] ?? [], 'districts' => array_map('intval', $data['districts'] ?? [])]);
                Notification::make()->title("Yuborildi: {$r['sent']} ta".($r['failed'] ? ", yetkazilmadi: {$r['failed']} (botni bloklagan bo‘lishi mumkin)" : ''))
                    ->{$r['sent'] ? 'success' : 'warning'}()->send();
            });
    }

    /** Barcha ochiq (bajarilmagan) moderator izohlari bo'yicha xodimlarga eslatma — bosh sahifa va tekshiruvlar ro'yxatida. */
    public static function remindAllAction(): Action
    {
        return Action::make('remindFollowups')->label(fn () => 'Izohlarga eslatish ('.FollowupReminder::open()->count().')')
            ->icon('heroicon-o-bell-alert')->color('warning')
            ->modalHeading('Bajarilmagan izohlar bo‘yicha eslatma')->modalWidth('4xl')
            ->modalContent(fn () => view('filament.pages.followup-reminders', [
                'items' => FollowupReminder::open()->orderBy('staff_id')->orderBy('reviewed_at')->get()->values(),
            ]))
            ->modalSubmitActionLabel('Eslatma yuborish')
            ->visible(fn () => User::currentIsAdmin())
            ->disabled(fn () => FollowupReminder::open()->doesntExist())
            ->action(function () {
                $sent = FollowupReminder::sendAll(auth()->id());
                Notification::make()->title($sent ? "Eslatma yuborildi: {$sent} ta" : 'Yuborilmadi')->{$sent ? 'success' : 'warning'}()->send();
            });
    }

    /** Oluvchilar maydoni: botdagi faol xodimlar, sozlamadagilar oldindan belgilangan. */
    private static function recipientsField(string $key): \Filament\Forms\Components\CheckboxList
    {
        return \Filament\Forms\Components\CheckboxList::make('recipients')->label('Kimlarga (oldindan — Sozlamalar → Hisobotlar)')
            ->options(fn () => \App\Filament\Pages\ReportSettings::people())
            ->default(fn () => array_map('strval', ReportSetting::for($key)->recipients ?? []))
            ->required()->searchable()->bulkToggleable()->columns(2)
            ->validationMessages(['required' => 'Kamida bitta oluvchini belgilang.']);
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\Staff> */
    private static function people(array $ids): \Illuminate\Support\Collection
    {
        return \App\Models\Staff::whereIn('id', array_map('intval', $ids))->where('status', \App\Models\Staff::ACTIVE)->whereNotNull('telegram_id')->get();
    }

    private static function previewHtml(array $images, string $caption): \Illuminate\Support\HtmlString
    {
        $img = collect($images)->map(fn ($src) => '<img src="'.e($src).'&t='.now()->timestamp.'" loading="lazy" style="width:100%;border-radius:.5rem;margin-bottom:.75rem;background:#fff;">')->join('');

        return new \Illuminate\Support\HtmlString('<div style="border:1px solid rgba(128,128,128,.3);border-radius:.75rem;padding:.75rem;background:rgba(128,128,128,.06);">'
            .$img.'<div style="line-height:1.5;font-size:.875rem;">'.nl2br($caption).'</div></div>');
    }

    /** Tanlangan davomat hisoboti (rasm + izoh) — dashboard tanlagan kun bo'yicha. */
    private function attendanceReport(string $type, ?string $slot): array
    {
        $day = self::attendanceDay($this->period())->toMutable();
        // o'tgan kun uchun kun yakuni — kun oxirigacha; bugun — hozirgacha tugagan soatlar
        $until = $day->isToday() ? now() : $day->copy()->endOfDay();

        return match ($type) {
            ReportSetting::CHECKIN_HOURLY => \App\Telegram\Support\CheckinReport::hourly($day, $slot ?: (\App\Models\Checkin::slots()[0] ?? '10:00')),
            ReportSetting::CHECKIN_DAILY => \App\Telegram\Support\CheckinReport::daily($day, $until),
            default => AttendanceReport::image($day),
        };
    }

    private function report(string $type, callable $send): void
    {
        $sent = $send(app(Nutgram::class));
        AuditLog::record("report.manual.{$type}", null, ['by' => auth()->id(), 'sent' => $sent, 'day' => $this->period()->day->toDateString()]);
        Notification::make()->title($sent ? "Yuborildi: {$sent} ta xabar" : 'Yuborilmadi: hisobot bo‘sh yoki oluvchilar botni bloklagan')
            ->{$sent ? 'success' : 'warning'}()->send();
    }
}
