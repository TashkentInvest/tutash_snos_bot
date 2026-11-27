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
    protected static ?string $navigationGroup = 'Davomat';

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Soatlik qayd';

    protected static ?string $title = 'Soatlik qayd — kunlik jadval';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.checkin-board';

    #[Url]
    public ?string $day = null;

    /** Kimlar ko'rsatiladi: all — hammasi, contract — shartnoma xodimlari, employee — soatlik qaydga qo'shilgan oddiy xodimlar. */
    #[Url]
    public string $who = 'all';

    /** day — kunlik jadval, month — oylik statistika (har kun: javob / so'rov, davomat; oy jami va foiz). */
    #[Url]
    public string $mode = 'day';

    /** Oylik ko'rinishda oy: YYYY-MM. */
    #[Url]
    public ?string $month = null;

    public static function canAccess(): bool
    {
        return User::currentIsAdmin() || User::currentIsCheckinsOnly();
    }

    public function mount(): void
    {
        $this->day ??= today()->toDateString();
        $this->month ??= today()->format('Y-m');
    }

    public function getTitle(): string
    {
        return $this->mode === 'month' ? 'Soatlik qayd — oylik statistika' : 'Soatlik qayd — kunlik jadval';
    }

    /** Oylik statistika: qatorda xodim, ustunda oy kunlari (javob / so'rov va ertalabki davomat), oxirida oy jami. */
    private function monthData($staff): array
    {
        try {
            $from = Carbon::createFromFormat('Y-m-d', ($this->month ?: today()->format('Y-m')).'-01')->startOfDay();
        } catch (\Throwable) {
            $from = today()->startOfMonth();
        }
        $to = $from->copy()->endOfMonth()->min(today()->endOfDay());
        $days = $from->greaterThan(today()) ? [] : collect(range(1, $to->day))->map(fn ($d) => $from->copy()->day($d))->all();
        $ids = $staff->pluck('id');

        $checkins = Checkin::whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->whereIn('staff_id', $ids)
            ->get(['staff_id', 'date', 'status'])->groupBy(fn ($c) => $c->staff_id.'|'.Carbon::parse($c->date)->toDateString());
        $attendance = Attendance::whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->whereIn('staff_id', $ids)
            ->get(['staff_id', 'date', 'status'])->keyBy(fn ($a) => $a->staff_id.'|'.Carbon::parse($a->date)->toDateString());

        $dayTotals = [];
        $rows = $staff->map(function (Staff $s) use ($days, $checkins, $attendance, &$dayTotals) {
            $cells = [];
            $sum = ['present' => 0, 'total' => 0, 'missed' => 0, 'att_present' => 0, 'att_absent' => 0, 'att_missed' => 0, 'att_total' => 0];
            foreach ($days as $d) {
                $key = $s->id.'|'.$d->toDateString();
                $list = $checkins[$key] ?? collect();
                $present = $list->where('status', Checkin::PRESENT)->count();
                $missed = $list->where('status', Checkin::MISSED)->count();
                $att = ($attendance[$key] ?? null)?->status;
                $cells[$d->day] = ['present' => $present, 'total' => $list->count(), 'att' => $att];
                $sum['present'] += $present;
                $sum['total'] += $list->count();
                $sum['missed'] += $missed;
                if ($att) {
                    $sum['att_total']++;
                    $att === Attendance::PRESENT && $sum['att_present']++;
                    $att === Attendance::ABSENT && $sum['att_absent']++;
                    $att === Attendance::MISSED && $sum['att_missed']++;
                }
                $dayTotals[$d->day]['present'] = ($dayTotals[$d->day]['present'] ?? 0) + $present;
                $dayTotals[$d->day]['total'] = ($dayTotals[$d->day]['total'] ?? 0) + $list->count();
            }
            $sum['pct'] = $sum['total'] ? round($sum['present'] * 100 / $sum['total'], 1) : null;

            return ['staff' => $s, 'cells' => $cells, 'sum' => $sum];
        });

        $grand = ['present' => $rows->sum('sum.present'), 'total' => $rows->sum('sum.total'), 'missed' => $rows->sum('sum.missed'),
            'att_present' => $rows->sum('sum.att_present'), 'att_total' => $rows->sum('sum.att_total')];
        $grand['pct'] = $grand['total'] ? round($grand['present'] * 100 / $grand['total'], 1) : null;

        return ['from' => $from, 'days' => $days, 'rows' => $rows->sortBy(fn ($r) => $r['sum']['pct'] ?? 101)->values(), 'dayTotals' => $dayTotals, 'grand' => $grand];
    }

    /** Barchaga birdan eslatma: hozirgi so'rovga javob bermaganlar (yuborish tugmasi bilan) yoki barcha shartnoma xodimlari. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('list')->label('Barcha qaydlar')->icon('heroicon-o-list-bullet')->color('gray')
                ->url(fn () => \App\Filament\Resources\CheckinResource::getUrl()),
            Action::make('remind')->label(fn () => 'Eslatma yuborish ('.CheckinReminder::pending()->count().' ta javob kutilmoqda)')
                ->visible(fn () => User::currentIsAdmin())
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
                        ->helperText('Javob kutilayotgan xodimga xabar «Joylashuv va video yuborish» tugmasi bilan boradi.'),
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
        if ($this->mode === 'month') {
            return ['mode' => 'month', 'slots' => Checkin::slots(), 'm' => $this->monthData($staff), 'date' => today()];
        }
        $checkins =Checkin::whereDate('date', $day)->whereIn('staff_id', $staff->pluck('id'))->get()->groupBy('staff_id');
        $attendance = Attendance::whereDate('date', $day)->whereIn('staff_id', $staff->pluck('id'))->get()->keyBy('staff_id');

        return [
            'mode' => 'day',
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
