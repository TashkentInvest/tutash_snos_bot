<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
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
 * Sozlamalar → Joylashuv cheklovlari: xodim qayerdan yubora olishi (tekshiruv, davomat, ijara shartnomasi).
 * O'chirilsa — uzoqdan (masalan ofisdan) ham yuboriladi; koordinata baribir saqlanadi.
 */
class LocationLimits extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Sozlamalar';

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Joylashuv cheklovlari';

    protected static ?string $title = 'Joylashuv cheklovlari';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'location-limits';

    protected static string $view = 'filament.pages.report-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return User::currentIsAdmin();
    }

    public function mount(): void
    {
        $this->form->fill(['limits' => Limits::all()]);
    }

    public function form(Form $form): Form
    {
        $m = fn (string $k, string $label, string $help, int $max = 50000) => Forms\Components\TextInput::make("limits.{$k}")->label($label)
            ->numeric()->integer()->minValue(10)->maxValue($max)->suffix('m')->required()->helperText($help);
        $on = fn (string $k) => fn (Forms\Get $get) => (bool) $get("limits.{$k}");

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Xodim qayerdan yubora oladi')
                ->description('Cheklov o‘chirilsa — xodim uzoqdan (masalan ofisdan) ham yuboradi: masofa va GPS aniqligi tekshirilmaydi, koordinatasi esa saqlanadi va xaritada ko‘rinadi. O‘zgarish xodim oynani qayta ochganda kuchga kiradi.')
                ->columns(3)
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
                ]),
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
        Notification::make()->title('Saqlandi')->success()->send();
    }
}
