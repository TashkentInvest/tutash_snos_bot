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
     * Tuman hududi: haqiqiy chegara (districts.boundary — KMZ / NGIS) bo'lsa o'sha, aks holda obyektlar o'rami.
     * @return array{rings: list<list<array{0: float, 1: float}>>, real: bool}
     */
    public static function districtRings(int $districtId): array
    {
        $boundary = \App\Models\District::whereKey($districtId)->value('boundary');
        $rings = is_string($boundary) ? json_decode($boundary, true) : $boundary;
        if (is_array($rings) && $rings !== []) {
            return ['rings' => $rings, 'real' => true];
        }
        $hull = self::districtArea($districtId);

        return ['rings' => count($hull) >= 3 ? [$hull] : [], 'real' => false];
    }

    /**
     * Chegarasi yo'q tuman uchun zaxira: tuman obyektlarini o'rab turgan qobiq (convex hull), [[lat, lng], ...] —
     * obyektlar butun tumanga tarqalgani uchun ular o'rami tuman hududiga yaqin; chetlariga kattaroq zaxira masofa qo'shiladi.
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

    /** Nuqtadan chiziqqacha (ko'cha o'qi, [[lat, lng], ...]) eng qisqa masofa, metr; chiziq bo'sh bo'lsa null. */
    public static function distanceToLine(float $lat, float $lng, array $line): ?float
    {
        $n = count($line);
        if ($n === 0) {
            return null;
        }
        $kx = cos(deg2rad($lat)) * 111_320;
        $ky = 110_574;
        $xy = array_map(fn ($p) => [($p[1] - $lng) * $kx, ($p[0] - $lat) * $ky], $line);
        if ($n === 1) {
            return hypot($xy[0][0], $xy[0][1]);
        }
        $min = INF;
        for ($i = 0; $i < $n - 1; $i++) {
            [$ax, $ay] = $xy[$i];
            [$bx, $by] = $xy[$i + 1];
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $t = ($dx || $dy) ? max(0, min(1, -($ax * $dx + $ay * $dy) / ($dx * $dx + $dy * $dy))) : 0;
            $min = min($min, hypot($ax + $t * $dx, $ay + $t * $dy));
        }

        return $min;
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
     * ['distance' => tuman chegarasigacha metr, 'districts' => nomlari]. Barcha tumanlarga biriktirilganlar va tumani yo'qlar tekshirilmaydi.
     * Ruxsat: chegaradan zaxira (haqiqiy chegara — 300 m, obyektlar o'rami — 1500 m) + GPS aniqligi (ko'pi bilan 500 m).
     */
    public static function outsideDistricts(Staff $staff, float $lat, float $lng, ?float $accuracy): ?array
    {
        if ($staff->is_global || Staff::isAllDistrictsEmployeePhone($staff->phone)) {
            return null;
        }
        $districts = $staff->districts()->get(['districts.id', 'districts.name']);
        if ($districts->isEmpty()) {
            return null;
        }
        $slack = min(max((float) $accuracy, 0), 500); // GPS aniqligi hisobga olinadi
        $over = []; // har tuman: chegaradan ruxsatdan ortiq necha metr tashqarida
        $dist = [];
        foreach ($districts as $d) {
            ['rings' => $rings, 'real' => $real] = self::districtRings($d->id);
            if ($rings === []) {
                return null; // hududi aniqlanmagan tuman — xato bloklamaslik uchun tekshirilmaydi
            }
            $m = min(array_map(fn ($r) => self::distanceToArea($lat, $lng, $r) ?? INF, $rings));
            $buffer = (int) config($real ? 'tutash.attendance.boundary_buffer_m' : 'tutash.attendance.district_buffer_m');
            $over[] = $m - $buffer - $slack;
            $dist[] = $m;
        }

        return min($over) <= 0 ? null : ['distance' => (int) round(min($dist)), 'districts' => $districts->pluck('name')->join(', ')];
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
