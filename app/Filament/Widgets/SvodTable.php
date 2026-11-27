<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Support\Svod;
use Filament\Widgets\Widget;

/** Tumanlar kesimida svod jadval (kunlik hisobotdagi rasm bilan bir xil) — faqat adminlarga, Excel yuklab olish bilan. */
class SvodTable extends Widget
{
    // sahifa bilan birga chiziladi: alohida so'rov (sekin tarmoqda har biri ~0,3–1 s) bo'lmaydi
    protected static bool $isLazy = false;

    use \Filament\Widgets\Concerns\InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected static string $view = 'filament.widgets.svod-table';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return User::currentIsAdmin();
    }

    protected function getViewData(): array
    {
        $day = \App\Support\ReportPeriod::fromFilter($this->filters['day'] ?? null)->day;

        return Svod::data($day) + ['day' => $day, 'excel' => route('admin.svod', ['day' => $day->toDateString()])];
    }
}
