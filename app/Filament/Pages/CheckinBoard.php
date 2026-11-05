<?php

namespace App\Filament\Pages;

use App\Models\Attendance;
use App\Models\Checkin;
use App\Models\Staff;
use App\Models\User;
use App\Support\CheckinReminder;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Soatlik qayd yuritiladigan xodimlar (shartnoma + «Soatlik qayd» belgili xodimlar) — kunlik jadval: qatorda xodim, ustunda ertalabki davomat va har bir soat (qayd vaqti, xarita, video).
 */
class CheckinBoard extends Page
{
    protected static ?string $navigationGroup = 'Soatlik qayd';

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Kunlik jadval';

    protected static ?string $title = 'Soatlik qayd — kunlik jadval';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.checkin-board';

    #[Url]
    public ?string $day = null;

    /** Kimlar ko'rsatiladi: all — hammasi, contract — shartnoma xodimlari, employee — soatlik qaydga qo'shilgan oddiy xodimlar. */
    #[Url]
    public string $who = 'all';

    public static function canAccess(): bool
    {
        return User::currentIsAdmin();
    }

    public function mount(): void
    {
        $this->day ??= today()->toDateString();
    }

    /** Barchaga birdan eslatma: hozirgi so'rovga javob bermaganlar (yuborish tugmasi bilan) yoki barcha shartnoma xodimlari. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('remind')->label(fn () => 'Eslatma yuborish ('.CheckinReminder::pending()->count().' ta javob kutilmoqda)')
                ->icon('heroicon-o-bell-alert')->color('warning')
                ->modalHeading('Soatlik qayd xodimlariga eslatma')->modalSubmitActionLabel('Yuborish')
                ->form([
                    Forms\Components\Radio::make('scope')->label('Kimlarga')->required()->live()
                        ->options(fn () => [
                            CheckinReminder::PENDING => 'Hozirgi so‘rovga javob bermaganlar ('.CheckinReminder::pending()->count().' ta)',
                            CheckinReminder::ALL => 'Soatlik qayd yuritiladigan barcha xodimlar ('.CheckinReminder::recipients(CheckinReminder::ALL)->count().' ta)',
                        ])
                        ->default(CheckinReminder::PENDING)
                        ->helperText(fn (Forms\Get $get) => 'Oluvchilar: '.(CheckinReminder::recipients($get('scope') ?? CheckinReminder::PENDING)->pluck('full_name')->join(', ') ?: 'yo‘q')),
                    Forms\Components\Textarea::make('text')->label('Xabar matni')->required()->rows(4)->maxLength(1000)
                        ->default(CheckinReminder::DEFAULT_TEXT)
                        ->helperText('Javob kutilayotgan xodimga xabar «📍 Joylashuv va video yuborish» tugmasi bilan boradi.'),
                ])
                ->action(function (array $data) {
                    $r = CheckinReminder::send($data['scope'], $data['text'], auth()->id());
                    Notification::make()->title($r['sent'] ? "Eslatma yuborildi: {$r['sent']} ta xodimga".($r['failed'] ? ", yetkazilmadi: {$r['failed']}" : '') : 'Yuborilmadi: oluvchi yo‘q')
                        ->{$r['sent'] ? 'success' : 'warning'}()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        try {
            $day = Carbon::parse($this->day)->startOfDay();
        } catch (\Throwable) {
            $day = today();
        }
        $staff = Staff::hourlyCheckin()->where('status', Staff::ACTIVE)
            ->when(in_array($this->who, Staff::WORK_ROLES, true), fn ($q) => $q->where('role', $this->who))
            ->orderBy('full_name')->get();
        $checkins = Checkin::whereDate('date', $day)->whereIn('staff_id', $staff->pluck('id'))->get()->groupBy('staff_id');
        $attendance = Attendance::whereDate('date', $day)->whereIn('staff_id', $staff->pluck('id'))->get()->keyBy('staff_id');

        return [
            'date' => $day,
            'slots' => Checkin::slots(),
            'rows' => $staff->map(fn (Staff $s) => [
                'staff' => $s,
                'attendance' => $attendance[$s->id] ?? null,
                'cells' => ($checkins[$s->id] ?? collect())->keyBy('slot'),
                'present' => ($checkins[$s->id] ?? collect())->where('status', Checkin::PRESENT)->count(),
            ]),
        ];
    }
}
