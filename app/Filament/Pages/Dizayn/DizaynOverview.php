<?php

namespace App\Filament\Pages\Dizayn;

use App\Models\Dizayn\Model as DizaynModel;
use App\Models\User;
use App\Support\DizaynStats;
use Filament\Pages\Page;

/**
 * Dizayn-kod boti — umumiy ko'rinish: foydalanuvchilar, yuklab olishlar, murojaatlar, dizayn-kod obyektlari,
 * 14 kunlik faollik, eng ko'p yuklangan ko'chalar va tumanlar. Bosh sahifa uslubida.
 */
class DizaynOverview extends Page
{
    protected static ?string $navigationGroup = 'Dizayn-kod boti';

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Umumiy ko‘rinish';

    protected static ?string $title = 'Dizayn-kod boti';

    protected static ?string $slug = 'dizayn';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.dizayn.overview';

    public static function canAccess(): bool
    {
        return User::currentIsAdmin() && DizaynModel::available();
    }

    public function getSubheading(): ?string
    {
        return '@DizaynKodBot — ko‘chalar bo‘yicha dizayn-kod konsepsiyalari, fuqarolar murojaatlari va tuman xodimlarining ishi.';
    }

    protected function getViewData(): array
    {
        $days = DizaynStats::perDay('downloads', 14);

        return [
            't' => DizaynStats::totals(),
            'downloads' => $days,
            'users' => DizaynStats::perDay('users', 14),
            'top' => DizaynStats::topStreets(8),
            'districts' => DizaynStats::byDistrict(),
            'urls' => [
                'users' => \App\Filament\Resources\Dizayn\BotUserResource::getUrl(),
                'registered' => \App\Filament\Resources\Dizayn\BotUserResource::getUrl('index', ['tableFilters' => ['registered' => ['isActive' => true]]]),
                'downloads' => \App\Filament\Resources\Dizayn\DownloadResource::getUrl(),
                'feedback' => \App\Filament\Resources\Dizayn\FeedbackResource::getUrl(),
                'feedback_open' => \App\Filament\Resources\Dizayn\FeedbackResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'new']]]),
            ],
        ];
    }
}
