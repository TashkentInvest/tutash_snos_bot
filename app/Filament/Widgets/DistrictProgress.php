<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SiteResource;
use App\Models\District;
use App\Models\Site;
use App\Support\ReportPeriod;
use App\Support\Svod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Tumanlar kesimida bajarilish (kunlik hisobotdagi ikkinchi rasm bilan bir xil sonlar). Tanlangan kun bo'yicha.
 * Oddiy HTML jadval: Filament jadvali 12 qator uchun ~200 KB javob qaytarardi.
 */
class DistrictProgress extends Widget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected static string $view = 'filament.widgets.district-progress';

    protected int|string|array $columnSpan = 'full';

    public static function sitesUrl(?int $districtId, ?string $status = null): string
    {
        return SiteResource::getUrl('index', ['tableFilters' => ($districtId ? ['district_id' => ['value' => $districtId]] : []) + ($status ? ['status' => ['value' => $status]] : [])]);
    }

    protected function getViewData(): array
    {
        $day = ReportPeriod::fromFilter($this->filters['day'] ?? null)->day;
        $data = Svod::data($day);
        $ids = District::query()->orderBy('name')->pluck('id')->all(); // Svod qatorlari bilan bir xil tartib

        return [
            'day' => $day,
            'total' => $data['total'],
            'rows' => collect($data['rows'])->map(fn ($r, $i) => $r + ['id' => $ids[$i] ?? null])->all(),
            'demolished' => Site::DEMOLISHED,
            'notDemolished' => Site::NOT_DEMOLISHED,
        ];
    }
}
