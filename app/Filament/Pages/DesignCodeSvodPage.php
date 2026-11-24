<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\DesignCodeSvod;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Obyektlar nazorati → «Dizayn-kod svodi»: Dizayn-kod ko'chalari (KMZ chizmasi, API) va SNOS noqonuniy obyektlari birgalikda —
 * har ko'cha chizig'idan N metr ichidagi obyektlar; tumanlar kesimi, tuman tanlansa — ko'chalar kesimi.
 */
class DesignCodeSvodPage extends Page
{
    protected static ?string $navigationGroup = 'Obyektlar nazorati';

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Dizayn-kod svodi';

    protected static ?string $title = 'Dizayn-kod ko‘chalari va noqonuniy obyektlar';

    protected static ?string $slug = 'designcode-svod';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.designcode-svod';

    #[Url]
    public ?int $district = null;

    #[Url]
    public ?int $street = null;

    #[Url]
    public int $buffer = DesignCodeSvod::DEFAULT_BUFFER;

    public static function canAccess(): bool
    {
        return User::currentIsAdmin();
    }

    public function pick(?int $district): void
    {
        $this->district = $this->district === $district ? null : $district;
        $this->street = null;
    }

    protected function getViewData(): array
    {
        $this->buffer = DesignCodeSvod::buffer($this->buffer);

        return ['d' => DesignCodeSvod::build($this->buffer, $this->district), 'buffers' => DesignCodeSvod::BUFFERS];
    }
}
