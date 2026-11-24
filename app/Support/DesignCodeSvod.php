<?php

namespace App\Support;

use App\Models\District;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;

/**
 * Dizayn-kod ko'chalari × SNOS obyektlari: har bir ko'cha chizig'idan (KMZ) $buffer metr ichidagi noqonuniy obyektlar.
 * Ko'cha bo'yicha va tuman bo'yicha (tuman — Dizayn-kod API'dagi ko'cha tumani; chorrahadagi obyekt tuman/shahar jamida bir marta sanaladi).
 */
class DesignCodeSvod
{
    public const BUFFERS = [25, 50, 100, 200];

    public const DEFAULT_BUFFER = 50;

    public static function buffer(mixed $m): int
    {
        return in_array((int) $m, self::BUFFERS, true) ? (int) $m : self::DEFAULT_BUFFER;
    }

    /**
     * @return array<int|string, array> ko'cha id => [id, name, district, key, length_km, drawn_km, dk_total, dk_installed, dk_removed, pct, sites: [site_id => status]]
     */
    public static function streets(int $buffer = self::DEFAULT_BUFFER): array
    {
        $features = DizaynKodStreets::all();
        if (! $features) {
            return [];
        }
        $sites = Site::query()->whereNotNull('lat')->whereNotNull('lng');
        $stamp = md5(json_encode([$buffer, md5(serialize($features)), (clone $sites)->count(), (clone $sites)->max('updated_at')]));

        return Cache::remember('dizayn_kod.svod.'.$stamp, 600, function () use ($features, $sites, $buffer) {
            $points = $sites->get(['id', 'lat', 'lng', 'status'])->map(fn (Site $s) => [$s->id, (float) $s->lat, (float) $s->lng, $s->status])->all();
            $dLat = $buffer / 110_574;
            $dLng = $buffer / (111_320 * cos(deg2rad(41.3)));
            $out = [];
            foreach ($features as $f) {
                $p = $f['properties'] ?? [];
                $id = $p['id'] ?? ($f['id'] ?? null);
                if ($id === null) {
                    continue;
                }
                $parts = DizaynKodStreets::parts($f);
                $near = [];
                if ($parts) {
                    $all = array_merge(...$parts);
                    $lats = array_column($all, 0);
                    $lngs = array_column($all, 1);
                    [$s, $n, $w, $e] = [min($lats) - $dLat, max($lats) + $dLat, min($lngs) - $dLng, max($lngs) + $dLng];
                    foreach ($points as [$sid, $lat, $lng, $status]) {
                        if ($lat < $s || $lat > $n || $lng < $w || $lng > $e) {
                            continue;
                        }
                        foreach ($parts as $part) {
                            if (Gps::distanceToLine($lat, $lng, $part) <= $buffer) {
                                $near[$sid] = $status;
                                break;
                            }
                        }
                    }
                }
                $out[$id] = [
                    'id' => $id, 'name' => ($p['name_latin'] ?? null) ?: ($p['name'] ?? '—'),
                    'district' => ($p['district_latin'] ?? null) ?: ($p['district'] ?? ''),
                    'key' => DizaynKodStreets::districtKey($p['district_latin'] ?? null),
                    'length_km' => isset($p['length_km']) ? (float) $p['length_km'] : null,
                    'drawn_km' => isset($p['drawn_km']) ? (float) $p['drawn_km'] : null,
                    'dk_total' => (int) ($p['objects_total'] ?? 0), 'dk_installed' => (int) ($p['objects_installed'] ?? 0),
                    'dk_removed' => (int) ($p['objects_removed'] ?? 0), 'pct' => (float) ($p['pct'] ?? 0), 'shape' => (bool) ($p['has_shape'] ?? false),
                    'sites' => $near,
                ];
            }

            return $out;
        });
    }

    /** Obyektlar holati bo'yicha sonlar (jami — sud/buzilmaydiganlarsiz, boshqa hisobotlardagi kabi). */
    public static function siteCounts(array $sites): array
    {
        $by = array_count_values($sites);
        $c = [
            'not_demolished' => $by[Site::NOT_DEMOLISHED] ?? 0, 'in_progress' => $by[Site::IN_PROGRESS] ?? 0,
            'demolished' => $by[Site::DEMOLISHED] ?? 0, 'exempt' => $by[Site::EXEMPT] ?? 0,
        ];
        $c['total'] = $c['not_demolished'] + $c['in_progress'] + $c['demolished'];
        $c['left'] = $c['total'] - $c['demolished'];
        $c['pct'] = $c['total'] ? round($c['demolished'] * 100 / $c['total'], 1) : 0;

        return $c;
    }

    /** Bir nechta ko'cha jami (obyekt bir marta sanaladi). */
    public static function sum(array $streets): array
    {
        $sites = [];
        foreach ($streets as $s) {
            $sites += $s['sites'];
        }
        $dkTotal = array_sum(array_column($streets, 'dk_total'));
        $dkInstalled = array_sum(array_column($streets, 'dk_installed'));

        return [
            'streets' => count($streets),
            'km' => round(array_sum(array_map(fn ($s) => $s['length_km'] ?? $s['drawn_km'] ?? 0, $streets)), 1),
            'dk_total' => $dkTotal, 'dk_installed' => $dkInstalled, 'dk_removed' => array_sum(array_column($streets, 'dk_removed')),
            'dk_pct' => $dkTotal ? round($dkInstalled * 100 / $dkTotal, 1) : 0,
            'snos' => self::siteCounts($sites),
        ];
    }

    /**
     * Svod: shahar jami, tumanlar (SNOS tumanlari tartibida) va tanlangan tuman ko'chalari.
     * @return array{configured: bool, total: array, districts: list<array>, streets: list<array>, buffer: int}
     */
    public static function build(int $buffer, ?int $district = null): array
    {
        $streets = self::streets($buffer);
        $rows = District::orderBy('name')->get(['id', 'name'])->map(function (District $d) use ($streets) {
            $key = DizaynKodStreets::districtKey($d->name);
            $own = array_filter($streets, fn ($s) => $s['key'] === $key);

            return ['id' => $d->id, 'name' => $d->name] + self::sum($own);
        })->filter(fn ($r) => $r['streets'] > 0)->values()->all();

        $list = [];
        if ($district) {
            $key = DizaynKodStreets::districtKey(District::whereKey($district)->value('name'));
            $list = collect($streets)->filter(fn ($s) => $s['key'] === $key)
                ->map(fn ($s) => $s + ['snos' => self::siteCounts($s['sites']), 'dk_pct' => $s['dk_total'] ? round($s['dk_installed'] * 100 / $s['dk_total'], 1) : 0])
                ->sortByDesc(fn ($s) => $s['snos']['total'])->values()->all();
        }

        return ['configured' => DizaynKodStreets::configured(), 'total' => self::sum($streets), 'districts' => $rows, 'streets' => $list, 'buffer' => $buffer];
    }
}
