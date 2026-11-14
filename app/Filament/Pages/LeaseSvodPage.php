<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\LeaseSvod;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/** Gastronomik ko'chalar → «Shartnomalar svodi»: tumanlar, ko'chalar va xodimlar kesimida; Excel yuklab olish. */
class LeaseSvodPage extends Page
{
    protected static ?string $navigationGroup = 'Gastronomik ko‘chalar';

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Shartnomalar svodi';

    protected static ?string $title = 'Ijara shartnomalari — svod';

    protected static ?string $slug = 'lease-svod';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.lease-svod';

    public ?string $from = null;

    public ?string $to = null;

    public bool $unique = true;   // takroriy (qayta tuzilgan) shartnomalarsiz

    public bool $onsite = false;  // faqat joyida (ko'cha yonida) tuzilganlar

    public static function canAccess(): bool
    {
        return User::currentIsAdmin();
    }

    /** Tez tanlash: bugun / shu oy / shu yil / hammasi */
    public function preset(string $p): void
    {
        [$this->from, $this->to] = match ($p) {
            'today' => [now()->toDateString(), now()->toDateString()],
            'month' => [now()->startOfMonth()->toDateString(), null],
            'year' => [now()->startOfYear()->toDateString(), null],
            default => [null, null],
        };
    }

    private function date(?string $v): ?Carbon
    {
        try {
            return filled($v) ? Carbon::parse($v) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function getViewData(): array
    {
        return [
            'd' => LeaseSvod::data($this->date($this->from), $this->date($this->to), $this->unique, $this->onsite),
            'xlsx' => route('admin.lease-svod', array_filter(['from' => $this->from, 'to' => $this->to,
                'unique' => $this->unique ? 1 : 0, 'onsite' => $this->onsite ? 1 : 0], fn ($v) => $v !== null)),
        ];
    }
}
