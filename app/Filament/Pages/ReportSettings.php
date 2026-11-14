<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\ReportSetting;
use App\Models\Staff;
use App\Models\User;
use App\Support\Limits;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Sozlamalar → Hisobotlar: barcha avtomatik hisobotlar bir joyda — har biri yoqiladi/o'chiriladi va
 * Telegram'ga kimlarga borishi belgilanadi (avval xodim formasidagi bitta «Hisobot oladi» belgisi edi).
 */
class ReportSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Sozlamalar';

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationLabel = 'Hisobotlar';

    protected static ?string $title = 'Hisobotlar va joylashuv cheklovlari';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'report-settings';

    protected static string $view = 'filament.pages.report-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return User::currentIsAdmin();
    }

    public function mount(): void
    {
        $this->form->fill(collect(ReportSetting::catalog())->mapWithKeys(fn ($v, $key) => [$key => [
            'enabled' => ReportSetting::for($key)->enabled,
            'recipients' => array_map('strval', ReportSetting::for($key)->recipients ?? []),
        ]])->all() + ['limits' => Limits::all()]);
    }

    /** Oluvchi bo'la oladiganlar: botda faol xodimlar (aholi emas). */
    public static function people(): array
    {
        return Staff::where('status', Staff::ACTIVE)->whereNotNull('telegram_id')->orderBy('full_name')->get()
            ->mapWithKeys(fn (Staff $s) => [(string) $s->id => trim($s->full_name.' · '.($s->isContract() ? 'shartnoma' : ($s->isCadastre() ? 'kadastr' : ($s->is_global ? 'admin' : 'xodim'))))])->all();
    }

    public function form(Form $form): Form
    {
        $people = self::people();
        $preview = [
            ReportSetting::CHECKIN_HOURLY => fn () => route('admin.checkin-report.png', ['type' => 'hourly']),
            ReportSetting::CHECKIN_DAILY => fn () => route('admin.checkin-report.png', ['type' => 'daily']),
            ReportSetting::SVOD => fn () => route('admin.svod.png'),
            ReportSetting::ATTENDANCE => fn () => route('admin.attendance-report.png'),
        ];

        return $form->statePath('data')->schema([self::limitsSection(), ...collect(ReportSetting::catalog())->map(fn ($meta, $key) => Forms\Components\Section::make($meta[0])
            ->description($meta[1])->compact()->collapsible()
            ->schema([
                Forms\Components\Toggle::make("{$key}.enabled")->label('Yoqilgan (avtomatik yuboriladi)')->inline(false),
                Forms\Components\CheckboxList::make("{$key}.recipients")->label('Kimlarga (Telegram)')
                    ->options($people)->columns(3)->searchable()->bulkToggleable()->gridDirection('row'),
                Forms\Components\Placeholder::make("{$key}_preview")->hiddenLabel()
                    ->content(fn () => isset($preview[$key]) ? new \Illuminate\Support\HtmlString(
                        '<a href="'.e($preview[$key]()).'" target="_blank" style="color:rgb(37,99,235);">🖼 Namuna rasmni ochish (bugungi ma’lumot bilan)</a>') : null)
                    ->visible(isset($preview[$key])),
            ]))->values()->all()]);
    }

    /**
     * Joylashuv cheklovlari: o'chirilsa — xodim uzoqdan (onlayn) ham yubora oladi; koordinatasi baribir saqlanadi.
     */
    private static function limitsSection(): Forms\Components\Section
    {
        $m = fn (string $k, string $label, string $help, int $max = 50000) => Forms\Components\TextInput::make("limits.{$k}")->label($label)
            ->numeric()->integer()->minValue(10)->maxValue($max)->suffix('m')->required()->helperText($help);
        $on = fn (string $k) => fn (Forms\Get $get) => (bool) $get("limits.{$k}");

        return Forms\Components\Section::make('📍 Joylashuv cheklovlari')
            ->description('Xodim qayerdan yubora olishi. Cheklov o‘chirilsa — xodim uzoqdan (masalan ofisdan, onlayn) ham yuboradi; masofa va GPS aniqligi tekshirilmaydi, koordinatasi esa saqlanadi va xaritada ko‘rinadi.')
            ->compact()->collapsible()->columns(3)
            ->schema([
                Forms\Components\Fieldset::make('Obyekt tekshiruvi')->columns(1)->columnSpan(1)->schema([
                    Forms\Components\Toggle::make('limits.inspection_check')->label('Faqat obyekt yonida')->live()
                        ->helperText('O‘chirilsa — tekshiruv istalgan joydan; uzoqdan yuborilgani «Obyektdan uzoqda» belgisi bilan ko‘rinadi.'),
                    $m('inspection_radius', 'Obyektdan ruxsat etilgan masofa', 'Obyekt nuqtasidan radius.')->visible($on('inspection_check')),
                ]),
                Forms\Components\Fieldset::make('Davomat va soatlik qayd')->columns(1)->columnSpan(1)->schema([
                    Forms\Components\Toggle::make('limits.attendance_check')->label('Faqat o‘z tumani hududidan')->live()
                        ->helperText('O‘chirilsa — davomat va soatlik qayd istalgan joydan qabul qilinadi, hisobotda «Hududdan tashqarida» chiqmaydi.'),
                    $m('attendance_boundary_buffer', 'Tuman chegarasidan zaxira', 'Haqiqiy tuman chegarasi (KMZ / NGIS) tashqarisiga ruxsat; GPS aniqligi qo‘shiladi.')->visible($on('attendance_check')),
                    $m('attendance_district_buffer', 'Chegarasiz tumanda zaxira', 'Chegara kiritilmagan tuman: obyektlar o‘ramidan ruxsat.')->visible($on('attendance_check')),
                ]),
                Forms\Components\Fieldset::make('Gastronomik ko‘cha (ijara shartnomasi)')->columns(1)->columnSpan(1)->schema([
                    Forms\Components\Toggle::make('limits.lease_check')->label('Faqat ko‘cha yonida')->live()
                        ->helperText('O‘chirilsa — shartnoma uzoqdan ham to‘ldiriladi, hudud ko‘chadan istalgan masofada chizilishi mumkin.'),
                    $m('lease_radius', 'Ko‘chadan ruxsat etilgan masofa', 'Xodim turgan joy va chizilgan hudud burchaklari uchun.')->visible($on('lease_check')),
                ]),
                $m('gps_accuracy', 'GPS aniqligi talabi (tekshiruv va shartnoma)', 'Aniqligi shundan yomon joylashuv rad etiladi (cheklov yoqilgan bo‘lsa). Davomatda rad etilmaydi — faqat belgi.', 5000)->columnSpan(1),
            ]);
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Saqlash')->submit('save')];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $before = Limits::all();
        Limits::save($state['limits'] ?? []);
        ($after = Limits::all()) !== $before && AuditLog::record('limits.saved', null, ['by' => auth()->id(), 'before' => $before, 'after' => $after]);
        foreach (array_keys(ReportSetting::catalog()) as $key) {
            ReportSetting::for($key)->update([
                'enabled' => (bool) ($state[$key]['enabled'] ?? false),
                'recipients' => array_values(array_map('intval', $state[$key]['recipients'] ?? [])),
            ]);
        }
        AuditLog::record('report.settings_saved', null, ['by' => auth()->id()]);
        Notification::make()->title('Saqlandi')->success()->send();
    }
}
