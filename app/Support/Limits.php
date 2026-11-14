<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Joylashuv cheklovlari — admin panelda («Hisobotlar» sahifasi) boshqariladi: tekshiruv (obyekt), davomat va soatlik qayd,
 * gastronomik ko'chadagi ijara shartnomasi. Har biri o'chirilsa — xodim uzoqdan (masalan ofisdan, onlayn) ham yubora oladi:
 * masofa ham, GPS aniqligi ham tekshirilmaydi (koordinata baribir saqlanadi va admin xaritada ko'radi).
 * Saqlanmagan qiymat — config/tutash.php dagi (.env) qiymat.
 */
class Limits
{
    private const CACHE = 'app-settings:limits';

    /** @return array<string, bool|int> */
    public static function defaults(): array
    {
        return [
            'inspection_check' => (bool) config('tutash.strict_distance'),
            'inspection_radius' => (int) config('tutash.max_distance_m'),
            'attendance_check' => true,
            'attendance_boundary_buffer' => (int) config('tutash.attendance.boundary_buffer_m'),
            'attendance_district_buffer' => (int) config('tutash.attendance.district_buffer_m'),
            'lease_check' => true,
            'lease_radius' => (int) config('tutash.max_distance_m'),
            'gps_accuracy' => (int) config('tutash.max_gps_accuracy_m'),
        ];
    }

    /** @return array<string, bool|int> joriy qiymatlar (saqlangani ustun) */
    public static function all(): array
    {
        $saved = Cache::get(self::CACHE);
        if (! is_array($saved)) {
            try {
                $saved = AppSetting::whereIn('key', array_keys(self::defaults()))->pluck('value', 'key')->all();
                Cache::forever(self::CACHE, $saved);
            } catch (\Throwable) {
                $saved = []; // jadval hali yo'q (migratsiyadan oldin) — keshlanmaydi
            }
        }
        $out = self::defaults();
        foreach ($saved as $k => $v) {
            if ($v !== null && array_key_exists($k, $out)) {
                $out[$k] = is_bool($out[$k]) ? (bool) $v : (int) $v;
            }
        }

        return $out;
    }

    public static function save(array $values): void
    {
        foreach (array_intersect_key($values, self::defaults()) as $k => $v) {
            AppSetting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Cache::forget(self::CACHE);
    }

    public static function inspectionCheck(): bool
    {
        return self::all()['inspection_check'];
    }

    public static function inspectionRadius(): int
    {
        return self::all()['inspection_radius'];
    }

    public static function attendanceCheck(): bool
    {
        return self::all()['attendance_check'];
    }

    public static function boundaryBuffer(bool $real): int
    {
        return self::all()[$real ? 'attendance_boundary_buffer' : 'attendance_district_buffer'];
    }

    public static function leaseCheck(): bool
    {
        return self::all()['lease_check'];
    }

    public static function leaseRadius(): int
    {
        return self::all()['lease_radius'];
    }

    public static function gpsAccuracy(): int
    {
        return self::all()['gps_accuracy'];
    }
}
