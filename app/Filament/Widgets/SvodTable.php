<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Support\Svod;
use Filament\Widgets\Widget;

/** Tumanlar kesimida svod jadval (kunlik hisobotdagi rasm bilan bir xil) — faqat adminlarga, Excel yuklab olish bilan. */
class SvodTable extends Widget
{
    protected static ?int $sort = 2;

    protected static string $view = 'filament.widgets.svod-table';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return User::currentIsAdmin();
    }

    protected function getViewData(): array
    {
        return Svod::data(today()) + ['day' => today(), 'excel' => route('admin.svod')];
    }
}
