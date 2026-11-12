<?php

namespace App\Support;

use App\Models\Site;
use App\Models\Staff;

/**
 * Mini App'dan kelgan joylashuvni baholash. Koordinatani mijoz yuboradi, shuning uchun uni to'liq ishonchli deb bo'lmaydi:
 * past aniqlik rad etiladi, soxta GPS belgilari (aniqlik yo'q yoki aynan 0) esa "shubhali" sifatida belgilanadi.
 */
class Gps
{
    /** Aniqlik juda past bo'lsa — xodimga ko'rsatiladigan xabar, aks holda null. */
    public static function accuracyError(?float $accuracy): ?string
    {
        $max = (int) config('tutash.max_gps_accuracy_m');
        if ($accuracy === null || $accuracy <= $max) {
            return null;
        }

        return 'GPS aniqligi past (±'.(int) round($accuracy)." m). Ochiq joyga chiqing, telefon joylashuvini «yuqori aniqlik» rejimiga o‘tkazing va qayta urinib ko‘ring (talab: ±{$max} m).";
    }

    /** @return string[] soxta GPS'ga xos belgilar */
    public static function flags(?float $accuracy): array
    {
        return match (true) {
            $accuracy === null => ['no_gps_accuracy'],
            $accuracy <= 0.0 => ['gps_mock_suspected'], // haqiqiy GPS hech qachon aynan 0 m aniqlik bermaydi
            default => [],
        };
    }

    /** Admin panelda ko'rsatiladigan belgi nomi (tekshiruv va davomat uchun umumiy). */
    public static function flagLabel(?string $flag): ?string
    {
        return match ($flag) {
            'far_from_site' => 'Obyektdan uzoqda yuborilgan',
            'far_from_district' => 'Biriktirilgan hududdan uzoqda',
            'reused_media' => 'Eski fayl qayta yuborilishga urinilgan',
            'no_gps_accuracy' => 'GPS aniqligi yo‘q (qo‘lda tanlangan bo‘lishi mumkin)',
            'gps_mock_suspected' => 'Soxta GPS gumoni (aniqlik 0 m)',
            'low_gps_accuracy' => 'GPS aniqligi past',
            'undeliverable' => 'Xabar yetkazilmadi',
            default => $flag,
        };
    }

    /**
     * Tuman hududi: tuman obyektlarini o'rab turgan qobiq (convex hull), [[lat, lng], ...]. Chegaralar bazada yo'q —
     * obyektlar butun tumanga tarqalgani uchun ular o'rami tuman hududiga yaqin; chetlariga zaxira masofa qo'shiladi.
     */
    public static function districtArea(int $districtId): array
    {
        return \Illuminate\Support\Facades\Cache::remember("district-area:{$districtId}", 3600, function () use ($districtId) {
            $pts = Site::where('district_id', $districtId)->whereNotNull('lat')->whereNotNull('lng')->get(['lat', 'lng'])
                ->map(fn (Site $s) => [round((float) $s->lat, 6), round((float) $s->lng, 6)])
                ->unique(fn ($p) => $p[0].','.$p[1])->sortBy(fn ($p) => [$p[1], $p[0]])->values()->all();
            if (count($pts) < 3) {
                return $pts;
            }
            // monotone chain (x = lng, y = lat)
            $cross = fn ($o, $a, $b) => ($a[1] - $o[1]) * ($b[0] - $o[0]) - ($a[0] - $o[0]) * ($b[1] - $o[1]);
            $half = function (array $points) use ($cross) {
                $h = [];
                foreach ($points as $p) {
                    while (count($h) >= 2 && $cross($h[count($h) - 2], $h[count($h) - 1], $p) <= 0) {
                        array_pop($h);
                    }
                    $h[] = $p;
                }
                array_pop($h);

                return $h;
            };

            return array_merge($half($pts), $half(array_reverse($pts)));
        });
    }

    /** Nuqtadan hududgacha masofa (metr): ichida bo'lsa 0; hudud bo'sh bo'lsa null. */
    public static function distanceToArea(float $lat, float $lng, array $area): ?float
    {
        if ($area === []) {
            return null;
        }
        // nuqta atrofida tekis koordinatalar (metr): shahar miqyosida xatolik sezilmaydi
        $kx = cos(deg2rad($lat)) * 111_320;
        $ky = 110_574;
        $xy = array_map(fn ($p) => [($p[1] - $lng) * $kx, ($p[0] - $lat) * $ky], $area);
        $n = count($xy);

        if ($n >= 3) { // nuqta ko'pburchak ichidami (ray casting)
            $inside = false;
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                [$xi, $yi] = $xy[$i];
                [$xj, $yj] = $xy[$j];
                if ((($yi > 0) !== ($yj > 0)) && (0 < ($xj - $xi) * (0 - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                    $inside = ! $inside;
                }
            }
            if ($inside) {
                return 0.0;
            }
        }

        $min = INF;
        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = $xy[$i];
            [$bx, $by] = $xy[($i + 1) % $n];
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $t = ($dx || $dy) ? max(0, min(1, -($ax * $dx + $ay * $dy) / ($dx * $dx + $dy * $dy))) : 0;
            $min = min($min, hypot($ax + $t * $dx, $ay + $t * $dy));
        }

        return $min;
    }

    /**
     * Davomat / soatlik qayd: xodim biriktirilgan tuman(lar)i hududidan tashqaridami. Ichida bo'lsa — null, aks holda
     * ['distance' => tuman hududigacha metr, 'districts' => nomlari]. Barcha tumanlarga biriktirilganlar va tumani yo'qlar tekshirilmaydi.
     * Ruxsat: tuman chetidan zaxira masofa + GPS aniqligi (ko'pi bilan 500 m) — bino ichida GPS adashsa ham bloklanmasin.
     */
    public static function outsideDistricts(Staff $staff, float $lat, float $lng, ?float $accuracy): ?array
    {
        if ($staff->is_global || Staff::isAllDistrictsEmployeePhone($staff->phone)) {
            return null;
        }
        $districts = $staff->districts()->get(['districts.id', 'districts.name']);
        // hududni aniqlash uchun kamida 3 ta har xil nuqta kerak; obyekti kam tuman tekshirilmaydi (xato bloklamaslik uchun)
        $areas = $districts->map(fn ($d) => self::districtArea($d->id));
        if ($areas->contains(fn ($a) => count($a) < 3)) {
            return null;
        }
        $distances = $areas->map(fn ($a) => self::distanceToArea($lat, $lng, $a));
        if ($distances->isEmpty()) {
            return null;
        }
        $allowed = (int) config('tutash.attendance.district_buffer_m') + min(max((float) $accuracy, 0), 500);
        $distance = $distances->min();

        return $distance <= $allowed ? null : ['distance' => (int) round($distance), 'districts' => $districts->pluck('name')->join(', ')];
    }

    /** Xodimga biriktirilgan tumanlardagi eng yaqin obyekt (xaritada ko'rsatish uchun). */
    public static function nearestSite(Staff $staff, float $lat, float $lng): ?Site
    {
        $query = Site::query()->whereNotNull('lat')->whereNotNull('lng');
        $staff->is_global || $query->whereIn('district_id', $staff->districts()->pluck('districts.id'));

        return $query->get(['id', 'lat', 'lng'])->sortBy(fn (Site $s) => Site::distanceMeters($lat, $lng, $s->lat, $s->lng))->first();
    }

    /** Xodimga biriktirilgan tumanlardagi eng yaqin obyektgacha masofa (metr); koordinatali obyekt bo'lmasa — null. */
    public static function nearestSiteDistance(Staff $staff, float $lat, float $lng): ?int
    {
        $query = Site::query()->whereNotNull('lat')->whereNotNull('lng');
        if (! $staff->is_global) {
            $query->whereIn('district_id', $staff->districts()->pluck('districts.id'));
        }

        // Avval taxminiy kvadrat ichidan qidiriladi (tez), topilmasa — barcha obyektlar.
        $radius = (int) config('tutash.attendance.max_distance_m');
        $dLat = $radius / 111_000;
        $dLng = $radius / (111_000 * max(cos(deg2rad($lat)), 0.01));
        $near = (clone $query)->whereBetween('lat', [$lat - $dLat, $lat + $dLat])->whereBetween('lng', [$lng - $dLng, $lng + $dLng])->get(['lat', 'lng']);
        $sites = $near->isNotEmpty() ? $near : $query->get(['lat', 'lng']);

        return $sites->map(fn (Site $s) => Site::distanceMeters($lat, $lng, $s->lat, $s->lng))->min();
    }
}
