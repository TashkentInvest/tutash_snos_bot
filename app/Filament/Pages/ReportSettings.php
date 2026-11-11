<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\ReportSetting;
use App\Models\Staff;
use App\Models\User;
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

    protected static ?string $title = 'Hisobotlar — kimlarga va qachon';

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
        ]])->all());
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

        return $form->statePath('data')->schema(collect(ReportSetting::catalog())->map(fn ($meta, $key) => Forms\Components\Section::make($meta[0])
            ->description($meta[1])->compact()->collapsible()
            ->schema([
                Forms\Components\Toggle::make("{$key}.enabled")->label('Yoqilgan (avtomatik yuboriladi)')->inline(false),
                Forms\Components\CheckboxList::make("{$key}.recipients")->label('Kimlarga (Telegram)')
                    ->options($people)->columns(3)->searchable()->bulkToggleable()->gridDirection('row'),
                Forms\Components\Placeholder::make("{$key}_preview")->hiddenLabel()
                    ->content(fn () => isset($preview[$key]) ? new \Illuminate\Support\HtmlString(
                        '<a href="'.e($preview[$key]()).'" target="_blank" style="color:rgb(37,99,235);">🖼 Namuna rasmni ochish (bugungi ma’lumot bilan)</a>') : null)
                    ->visible(isset($preview[$key])),
            ]))->values()->all());
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Saqlash')->submit('save')];
    }

    public function save(): void
    {
        $state = $this->form->getState();
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
