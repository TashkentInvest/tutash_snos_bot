<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\User;
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
                    ->default($current->day->toDateString())->maxDate($current->day->toDateString())
                    ->live()
                    ->helperText(fn ($state) => 'Hisobot davri: '.ReportPeriod::fromFilter($state)->label().' (har kuni 18:00 da yangi kun boshlanadi)'),
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
            Action::make('sendInspectionReport')->label('Buzilish hisobotini yuborish')->icon('heroicon-o-paper-airplane')
                ->modalHeading(fn () => 'Buzilish hisoboti — '.$this->period()->day->format('d.m.Y'))->modalWidth('2xl')->modalSubmitActionLabel('Yuborish')
                ->modalContent(fn () => view('filament.pages.report-preview', [
                    'recipients' => AttendanceReport::recipients(),
                    'texts' => [Script::apply(InspectionReport::caption($this->period()->day))],
                    'image' => route('admin.svod.png', ['day' => $this->period()->day->toDateString(), 't' => now()->timestamp]),
                ]))
                ->visible(fn () => User::currentIsAdmin())
                ->action(fn () => $this->report('inspection', fn (Nutgram $bot) => InspectionReport::send($bot, $this->period()->day))),
            Action::make('sendAttendanceReport')->label('Davomat hisobotini yuborish')->icon('heroicon-o-clipboard-document-list')->color('gray')
                ->modalHeading(fn () => 'Davomat hisoboti — '.self::attendanceDay($this->period())->format('d.m.Y'))->modalWidth('2xl')->modalSubmitActionLabel('Yuborish')
                ->modalContent(fn () => view('filament.pages.report-preview', [
                    'recipients' => AttendanceReport::recipients(),
                    'texts' => array_map([Script::class, 'apply'], AttendanceReport::chunks(self::attendanceDay($this->period())->toMutable())),
                    'image' => null,
                    'empty' => 'Bu kun davomat so‘rovi yuborilmagan (dam olish kuni yoki so‘rov vaqti hali kelmagan) — hisobot bo‘sh, yuboriladigan narsa yo‘q.',
                ]))
                // ma'lumot bo'lmasa — yuborish tugmasi o'chiq
                ->modalSubmitAction(fn ($action) => $action->disabled(AttendanceReport::chunks(self::attendanceDay($this->period())->toMutable()) === []))
                ->visible(fn () => User::currentIsAdmin())
                ->action(fn () => $this->report('attendance', fn (Nutgram $bot) => AttendanceReport::sendToAdmins($bot, self::attendanceDay($this->period())->toMutable()))),
        ];
    }

    private function report(string $type, callable $send): void
    {
        $sent = $send(app(Nutgram::class));
        AuditLog::record("report.manual.{$type}", null, ['by' => auth()->id(), 'sent' => $sent, 'day' => $this->period()->day->toDateString()]);
        Notification::make()->title($sent ? "Yuborildi: {$sent} ta xabar" : 'Yuborilmadi: hisobot bo‘sh yoki oluvchilar botni bloklagan')
            ->{$sent ? 'success' : 'warning'}()->send();
    }
}
