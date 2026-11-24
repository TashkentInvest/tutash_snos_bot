<?php

namespace App\Support;

use App\Filament\Resources\InspectionResource;
use App\Filament\Resources\LeaseContractResource;
use App\Filament\Resources\LocationRejectionResource;
use App\Filament\Resources\SiteResource;
use App\Models\Attendance;
use App\Models\Checkin;
use App\Models\District;
use App\Models\GastroStreet;
use App\Models\Inspection;
use App\Models\LeaseContract;
use App\Models\LocationRejection;
use App\Models\Site;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Umumiy xarita» qatlamlari: har biri alohida (faqat yoqilganda) yuklanadi. Filtr: tuman, kun, xodim.
 * Har bir nuqta: p — [lat, lng], c — holat kaliti (rang), t — sarlavha, s — qisqa izoh, u — ochiladigan sahifa.
 */
class MapLayers
{
    /** key => [nomi, rangli holatlar: kalit => [rang, izoh]] — sahifadagi qatlamlar paneli va legenda */
    public static function catalog(): array
    {
        return [
            'gastro' => ['Gastronomik ko‘chalar va ijara shartnomalari', [
                'street' => ['#dc2626', 'gastronomik ko‘cha'], 'legal' => ['#2563eb', 'yuridik shaxs'],
                'individual' => ['#16a34a', 'jismoniy shaxs'], 'remote' => ['#f59e0b', 'uzoqdan tuzilgan']]],
            'designcode' => ['Dizayn-kod ko‘chalari', [
                'new' => ['#64748b', 'boshlanmagan'], 'progress' => ['#f59e0b', 'jarayonda'], 'done' => ['#16a34a', 'to‘liq bajarilgan']]],
            'sites' => ['Obyektlar (holati bo‘yicha)', [
                Site::NOT_DEMOLISHED => ['#64748b', 'buzilmagan'], Site::IN_PROGRESS => ['#f59e0b', 'jarayonda'],
                Site::DEMOLISHED => ['#16a34a', 'buzilgan'], Site::EXEMPT => ['#2563eb', 'sud / buzilmaydi']]],
            'attendance' => ['Ertalabki davomat', [
                'in' => ['#16a34a', 'o‘z hududida'], 'out' => ['#dc2626', 'hududdan tashqarida']]],
            'checkins' => ['Soatlik qayd (oxirgi joylashuv)', [
                'in' => ['#0891b2', 'o‘z hududida'], 'out' => ['#dc2626', 'hududdan tashqarida']]],
            'inspections' => ['Tekshiruvlar (xodim turgan joy)', [
                'demolished' => ['#16a34a', 'buzilgan'], 'other' => ['#7c3aed', 'boshqa natija'], 'suspicious' => ['#dc2626', 'shubhali']]],
            'rejections' => ['Xatolikka uchraganlar', [
                'rejected' => ['#e11d48', 'rad etilgan joylashuv']]],
        ];
    }

    public static function get(string $layer, ?int $district, CarbonInterface $day, ?int $staff): array
    {
        return match ($layer) {
            'gastro' => self::gastro($district, $staff),
            'designcode' => self::designcode($district),
            'sites' => self::sites($district),
            'attendance' => self::attendance($district, $day, $staff),
            'checkins' => self::checkins($district, $day, $staff),
            'inspections' => self::inspections($district, $day, $staff),
            'rejections' => self::rejections($district, $day, $staff),
            default => abort(404),
        };
    }

    /** Xodim tuman bo'yicha: biriktirilgan tumanlari orqali. */
    private static function staffInDistrict(Builder $q, ?int $district): Builder
    {
        return $district ? $q->whereHas('staff.districts', fn ($d) => $d->whereKey($district)) : $q;
    }

    private static function gastro(?int $district, ?int $staff): array
    {
        $radius = Limits::leaseRadius();
        $lines = GastroStreet::query()->when($district, fn ($q) => $q->where('district_id', $district))->get(['id', 'name', 'line', 'district_id'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'line' => $s->line])->values();
        $points = LeaseContract::with('staff:id,full_name')->whereNotNull('docx_path')
            ->when($district, fn ($q) => $q->where('district_id', $district))->when($staff, fn ($q) => $q->where('staff_id', $staff))
            ->get(['id', 'party', 'tenant_name', 'mfy', 'house', 'area_m2', 'tariff', 'lat', 'lng', 'polygon', 'street_distance_m', 'staff_id', 'created_at'])
            ->map(fn (LeaseContract $c) => [
                'p' => [$c->lat, $c->lng], 'poly' => $c->polygon ?? [],
                'c' => (int) $c->street_distance_m > $radius ? 'remote' : $c->party,
                't' => '№'.$c->id.' · '.$c->tenant_name,
                's' => trim($c->mfy.(filled($c->house) ? ', '.$c->house : '')).' · '.LeaseContractDocument::area((float) $c->area_m2).' m²'
                    .($c->tariff ? ' · oyiga '.LeaseContractDocument::money(round($c->area_m2 * $c->tariff, 2)).' so‘m' : '')
                    .' · '.$c->staff?->full_name,
                'u' => LeaseContractResource::getUrl('view', ['record' => $c->id]),
            ])->values();

        return ['lines' => $lines, 'points' => $points];
    }

    /**
     * Dizayn-kod ko'chalari (boshqa tizimdan, API orqali; faqat tuman filtri). Har chiziq: line — [[lat, lng], ...] (MultiLineString'da
     * qismlar ro'yxati), c — bajarilish holati, shape — KMZ'dagi haqiqiy shakl (yo'q bo'lsa boshi → oxiri, uzuq chiziq), t/rows/tip — popup va tooltip.
     */
    private static function designcode(?int $district): array
    {
        if (! DizaynKodStreets::configured()) {
            return ['lines' => [], 'count' => 0, 'note' => 'Dizayn-kod API sozlanmagan (DIZAYN_KOD_TOKEN bo‘sh).'];
        }
        $num = fn ($v) => $v === null || $v === '' ? null : str_replace('.', ',', (string) round((float) $v, 2));
        $buffer = DesignCodeSvod::DEFAULT_BUFFER;
        $svod = DesignCodeSvod::streets($buffer); // ko'cha bo'yidagi SNOS obyektlari
        $districtIds = District::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [DizaynKodStreets::districtKey($n) => $id]);

        $lines = collect(DizaynKodStreets::forDistrict($district))->map(function (array $f) use ($num, $svod, $buffer, $districtIds) {
            $parts = DizaynKodStreets::parts($f);
            if (! $parts) {
                return null;
            }
            $line = ($f['geometry']['type'] ?? null) === 'MultiLineString' ? $parts : $parts[0];
            $p = $f['properties'] ?? [];
            $snos = DesignCodeSvod::siteCounts($svod[$p['id'] ?? ($f['id'] ?? null)]['sites'] ?? []);
            $did = $districtIds[DizaynKodStreets::districtKey($p['district_latin'] ?? null)] ?? null;
            $pct = (float) ($p['pct'] ?? 0);
            $name = ($p['name_latin'] ?? null) ?: ($p['name'] ?? '—');
            $len = $num($p['length_km'] ?? null);
            $drawn = $num($p['drawn_km'] ?? null);

            return [
                'id' => $p['id'] ?? ($f['id'] ?? null), 'name' => $name, 'line' => $line,
                'c' => $pct >= 100 ? 'done' : ((int) ($p['objects_installed'] ?? 0) === 0 && (int) ($p['objects_removed'] ?? 0) === 0 ? 'new' : 'progress'),
                'shape' => (bool) ($p['has_shape'] ?? false),
                't' => $name,
                'rows' => array_values(array_filter([
                    ($p['district_latin'] ?? null) ?: ($p['district'] ?? null),
                    'Uzunligi: '.($len !== null ? $len.' km' : '—'),
                    'Obyektlar: '.(int) ($p['objects_total'] ?? 0).' · dizayn-kodga keltirildi: '.(int) ($p['objects_installed'] ?? 0)
                        .' · jarayonda: '.(int) ($p['objects_removed'] ?? 0),
                    'Bajarilish: '.$num($pct).'%',
                    "Noqonuniy obyektlar (SNOS, {$buffer} m ichida): ".$snos['total'].' · buzilgan: '.$snos['demolished'].' · qolgan: '.$snos['left'],
                ])),
                'small' => $drawn !== null ? 'xaritada chizilgani: '.$drawn.' km' : null,
                'tip' => $name.(($len ?? $drawn) !== null ? ' · '.($len ?? $drawn).' km' : '').' · '.$snos['total'].' ta noqonuniy obyekt',
                'u' => \App\Filament\Pages\DesignCodeSvodPage::getUrl(array_filter(['district' => $did, 'street' => $p['id'] ?? null])),
            ];
        })->filter()->values();

        return ['lines' => $lines, 'count' => $lines->count()];
    }

    private static function sites(?int $district): array
    {
        $points = Site::query()->with('district:id,name')->whereNotNull('lat')->whereNotNull('lng')
            ->when($district, fn ($q) => $q->where('district_id', $district))
            ->get(['id', 'district_id', 'address', 'lat', 'lng', 'status', 'area_m2', 'violator_name'])
            ->map(fn (Site $s) => [
                'p' => [(float) $s->lat, (float) $s->lng], 'c' => $s->status,
                't' => '№'.$s->id.' · '.str($s->address)->limit(70),
                's' => collect([$s->district?->name, $s->area_m2 ? number_format((float) $s->area_m2, 0, ',', ' ').' m²' : null, $s->violator_name])->filter()->join(' · '),
                'u' => SiteResource::getUrl('view', ['record' => $s->id]),
            ])->values();

        return ['points' => $points];
    }

    private static function attendance(?int $district, CarbonInterface $day, ?int $staff): array
    {
        $q = Attendance::with('staff')->whereDate('date', $day)->whereNotNull('lat')->when($staff, fn ($q) => $q->where('staff_id', $staff));
        $points = self::staffInDistrict($q, $district)->get()->map(function (Attendance $a) {
            $out = $a->staff ? Gps::outsideDistricts($a->staff, (float) $a->lat, (float) $a->lng, null) : null;

            return [
                'p' => [(float) $a->lat, (float) $a->lng], 'c' => $out ? 'out' : 'in',
                't' => ($a->staff?->full_name ?? '—').' · davomat',
                's' => ($a->responded_at?->format('H:i') ?? '').($out ? ' · '.$out['districts'].' chegarasidan ~'.LocationRejectionResource::meters($out['distance']) : ''),
                'u' => \App\Filament\Resources\AttendanceResource::getUrl('index'),
            ];
        })->values();

        return ['points' => $points];
    }

    private static function checkins(?int $district, CarbonInterface $day, ?int $staff): array
    {
        $q = Checkin::with('staff')->whereDate('date', $day)->whereNotNull('lat')->when($staff, fn ($q) => $q->where('staff_id', $staff));
        // har xodimning oxirgi qaydi
        $points = self::staffInDistrict($q, $district)->orderBy('slot')->get()->groupBy('staff_id')->map->last()
            ->map(function (Checkin $c) {
                $out = $c->staff ? Gps::outsideDistricts($c->staff, (float) $c->lat, (float) $c->lng, null) : null;

                return [
                    'p' => [(float) $c->lat, (float) $c->lng], 'c' => $out ? 'out' : 'in',
                    't' => ($c->staff?->full_name ?? '—').' · soat '.$c->slot,
                    's' => ($c->responded_at?->format('H:i') ?? '').($out ? ' · '.$out['districts'].' chegarasidan ~'.LocationRejectionResource::meters($out['distance']) : ''),
                    'u' => \App\Filament\Resources\CheckinResource::getUrl('index'),
                ];
            })->values();

        return ['points' => $points];
    }

    private static function inspections(?int $district, CarbonInterface $day, ?int $staff): array
    {
        $points = Inspection::with(['staff:id,full_name', 'site:id,address,district_id'])->whereNotNull('submitted_at')->whereNotNull('staff_lat')
            ->whereDate('submitted_at', $day)
            ->when($staff, fn ($q) => $q->where('staff_id', $staff))
            ->when($district, fn ($q) => $q->whereHas('site', fn ($s) => $s->where('district_id', $district)))
            ->get(['id', 'site_id', 'staff_id', 'staff_lat', 'staff_lng', 'is_demolished', 'suspicious', 'distance_m', 'submitted_at'])
            ->map(fn (Inspection $i) => [
                'p' => [(float) $i->staff_lat, (float) $i->staff_lng],
                'c' => $i->suspicious ? 'suspicious' : ($i->is_demolished ? 'demolished' : 'other'),
                't' => ($i->staff?->full_name ?? '—').' · '.$i->submitted_at?->format('H:i'),
                's' => str($i->site?->address)->limit(60).($i->distance_m !== null ? ' · obyektdan '.$i->distance_m.' m' : ''),
                'u' => InspectionResource::getUrl('view', ['record' => $i->id]),
            ])->values();

        return ['points' => $points];
    }

    private static function rejections(?int $district, CarbonInterface $day, ?int $staff): array
    {
        $q = LocationRejection::with('staff')->whereDate('created_at', $day)->when($staff, fn ($q) => $q->where('staff_id', $staff));
        $points = self::staffInDistrict($q, $district)->get()->map(fn (LocationRejection $r) => [
            'p' => [(float) $r->lat, (float) $r->lng], 'c' => 'rejected',
            't' => ($r->staff?->full_name ?? '—').' · '.(LocationRejection::SOURCES[$r->source] ?? $r->source),
            's' => (LocationRejection::REASONS[$r->reason] ?? $r->reason).' · '.$r->created_at?->format('H:i')
                .($r->distance_m !== null ? ' · '.LocationRejectionResource::meters($r->distance_m) : ''),
            'u' => LocationRejectionResource::getUrl('view', ['record' => $r->id]),
        ])->values();

        return ['points' => $points];
    }
}
