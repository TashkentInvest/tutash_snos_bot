<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SiteResource;
use App\Models\District;
use App\Models\Site;
use Filament\Widgets\Widget;

/**
 * Tumanlar kesimida bajarilish. Oddiy HTML jadval: Filament jadvali 12 qator uchun ~200 KB javob qaytarardi,
 * bu sust aloqada bosh sahifani sekinlashtirardi.
 */
class DistrictProgress extends Widget
{
    protected static ?int $sort = 3;

    protected static string $view = 'filament.widgets.district-progress';

    protected int|string|array $columnSpan = 'full';

    public static function sitesUrl(District $district, ?string $status = null): string
    {
        return SiteResource::getUrl('index', ['tableFilters' => ['district_id' => ['value' => $district->id]] + ($status ? ['status' => ['value' => $status]] : [])]);
    }

    protected function getViewData(): array
    {
        return [
            'districts' => District::query()->orderBy('name')
                ->withCount(['sites' => fn ($q) => $q->countable(), 'sites as demolished_count' => fn ($q) => $q->where('status', Site::DEMOLISHED)])
                ->get(),
        ];
    }
}
