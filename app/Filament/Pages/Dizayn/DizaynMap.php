<?php

namespace App\Filament\Pages\Dizayn;

use App\Filament\Resources\Dizayn\DesignObjectResource;
use App\Models\Dizayn\DesignObject;
use App\Models\Dizayn\District;
use App\Models\Dizayn\Inspection;
use App\Models\Dizayn\Model as DizaynModel;
use App\Models\User;
use App\Support\Latin;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Dizayn-kod boti → Xarita»: rasmiy ro'yxatdagi ko'chalar (KMZ shakli yoki boshi–oxiri) bajarilish rangida va
 * tuman xodimlari kiritgan obyektlar holati bo'yicha nuqta. Dizayn panelidagi xarita bilan bir xil ma'lumot.
 */
class DizaynMap extends Page
{
    protected static ?string $navigationGroup = 'Dizayn-kod boti';

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Xarita';

    protected static ?string $title = 'Dizayn-kod xaritasi';

    protected static ?string $slug = 'dizayn/xarita';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.dizayn.map';

    public static function canAccess(): bool
    {
        return User::currentIsAdmin() && DizaynModel::available();
    }

    public function getSubheading(): ?string
    {
        return 'Dizayn-kod ko‘chalari va ulardagi obyektlar. Ko‘cha yoki nuqtani bosing — tafsilot.';
    }

    /** Joyi bor har bir ko'cha: chizig'i va bajarilishi. */
    public static function streets(): Collection
    {
        $db = DB::connection('dizayn');
        $counts = $db->table('objects')->where('is_active', true)
            ->selectRaw("street_id, SUM(CASE WHEN status='removed' THEN 1 ELSE 0 END) AS removed, SUM(CASE WHEN status='installed' THEN 1 ELSE 0 END) AS installed")
            ->groupBy('street_id')->get()->keyBy('street_id');

        return $db->table('streets as s')->join('districts as d', 'd.id', '=', 's.district_id')
            ->whereNotNull('s.start_lat')->whereNotNull('s.end_lat')
            ->get(['s.id', 's.name', 's.district_id', 'd.name as district', 's.planned_objects', 's.start_lat', 's.start_lon', 's.end_lat', 's.end_lon', 's.path', 's.length_km', 's.nonres_objects'])
            ->map(function ($s) use ($counts) {
                $total = (int) $s->planned_objects;
                $installed = (int) ($counts[$s->id]->installed ?? 0);
                $path = ($p = json_decode((string) $s->path, true)) && count($p) >= 2 ? $p : null;

                return [
                    'name' => Latin::of($s->name), 'district' => Latin::of($s->district), 'd' => (int) $s->district_id,
                    'line' => $path ?? [[(float) $s->start_lat, (float) $s->start_lon], [(float) $s->end_lat, (float) $s->end_lon]],
                    'shape' => (bool) $path, 'km' => $s->length_km !== null ? round((float) $s->length_km, 2) : null,
                    'nonres' => $s->nonres_objects !== null ? (int) $s->nonres_objects : null,
                    'total' => $total, 'installed' => $installed, 'removed' => (int) ($counts[$s->id]->removed ?? 0),
                    'pct' => $total ? round($installed / $total * 100, 1) : 0,
                ];
            })->values();
    }

    protected function getViewData(): array
    {
        $streets = self::streets();
        $review = Inspection::whereIn('review', Inspection::OPEN)->pluck('object_id')->flip();
        $objects = DesignObject::query()->where('is_active', true)->with('street:id,name,district_id')
            ->where(fn ($q) => $q->whereNotNull('latitude')->orWhereNotNull('found_lat'))->get()
            ->map(fn (DesignObject $o) => [
                'name' => $o->shop_name, 'street' => Latin::of($o->street?->name), 'd' => (int) $o->street?->district_id, 'addr' => $o->address,
                's' => isset($review[$o->id]) ? 'review' : $o->status,
                // tasdiqlangan joy bo'lsa — o'sha, aks holda xodim qo'shgan joy
                'p' => [(float) ($o->latitude ?? $o->found_lat), (float) ($o->longitude ?? $o->found_lon)], 'exact' => $o->latitude !== null,
                'url' => DesignObjectResource::getUrl('view', ['record' => $o]),
            ])->values();
        $planned = $streets->sum('total');

        return [
            'map' => [
                'streets' => $streets, 'objects' => $objects,
                'districts' => District::orderBy('sort_order')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => Latin::of($d->name)])->values(),
                'statuses' => DesignObject::STATUSES + ['review' => 'Tasdiq kutmoqda'],
            ],
            'totals' => [
                'streets' => $streets->count(), 'km' => str_replace('.', ',', (string) round((float) $streets->sum('km'), 1)),
                'planned' => $planned, 'objects' => $objects->count(),
                'pct' => str_replace('.', ',', (string) ($planned ? round($streets->sum('installed') / $planned * 100, 1) : 0)).'%',
            ],
        ];
    }
}
