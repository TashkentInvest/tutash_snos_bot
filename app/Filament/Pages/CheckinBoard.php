<?php

namespace App\Filament\Pages;

use App\Models\Attendance;
use App\Models\Checkin;
use App\Models\Staff;
use App\Models\User;
use Carbon\Carbon;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Shartnoma xodimlari — kunlik jadval: qatorda xodim, ustunda ertalabki davomat va har bir soat (qayd vaqti, xarita, video).
 */
class CheckinBoard extends Page
{
    protected static ?string $navigationGroup = 'Shartnoma';

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Kunlik jadval';

    protected static ?string $title = 'Shartnoma xodimlari — kunlik jadval';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.checkin-board';

    #[Url]
    public ?string $day = null;

    public static function canAccess(): bool
    {
        return User::currentIsAdmin();
    }

    public function mount(): void
    {
        $this->day ??= today()->toDateString();
    }

    protected function getViewData(): array
    {
        try {
            $day = Carbon::parse($this->day)->startOfDay();
        } catch (\Throwable) {
            $day = today();
        }
        $staff = Staff::where('is_contract', true)->where('status', Staff::ACTIVE)->orderBy('full_name')->get();
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
