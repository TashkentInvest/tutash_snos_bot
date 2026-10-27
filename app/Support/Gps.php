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
