<?php

namespace App\Support;

use App\Models\District;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Dizayn-kod dashboardidagi ko'chalar (GeoJSON FeatureCollection, API orqali) — «Umumiy xarita»dagi «Dizayn-kod ko‘chalari» qatlami.
 * SNOS bu ma'lumotni saqlamaydi: 10 daqiqa keshlaydi, API ishlamasa — oxirgi muvaffaqiyatli nusxa (1 kun) yoki bo'sh ro'yxat.
 * Token faqat serverda ishlatiladi: brauzer API'ga murojaat qilmaydi, logga ham yozilmaydi.
 */
class DizaynKodStreets
{
    public const CACHE = 'dizayn_kod.streets';

    public const LAST_GOOD = 'dizayn_kod.streets.last_good';

    public static function configured(): bool
    {
        return filled(config('services.dizayn_kod.token')) && filled(config('services.dizayn_kod.url'));
    }

    /** @return list<array> GeoJSON Feature'lar (token bo'lmasa — so'rovsiz bo'sh ro'yxat) */
    public static function all(): array
    {
        if (! self::configured()) {
            return [];
        }

        return Cache::remember(self::CACHE, 600, function () {
            try {
                $r = Http::withToken((string) config('services.dizayn_kod.token'))->timeout(10)->acceptJson()
                    ->get((string) config('services.dizayn_kod.url'));
                $features = $r->successful() ? $r->json('features') : null;
                if (is_array($features)) {
                    $features = array_values(array_filter($features, 'is_array'));
                    Cache::put(self::LAST_GOOD, $features, now()->addDay());

                    return $features;
                }
                Log::warning('Dizayn-kod API: kutilmagan javob', ['status' => $r->status()]);
            } catch (\Throwable $e) {
                Log::warning('Dizayn-kod API: so‘rov bajarilmadi', ['error' => class_basename($e).': '.Str::limit($e->getMessage(), 200)]);
            }

            return Cache::get(self::LAST_GOOD, []);
        });
    }

    /** Tuman bo'yicha (SNOS District id). null — butun shahar. */
    public static function forDistrict(?int $district): array
    {
        $all = self::all();
        if (! $district) {
            return $all;
        }
        $key = self::districtKey(District::whereKey($district)->value('name'));

        return $key === '' ? [] : array_values(array_filter($all, fn ($f) => self::districtKey($f['properties']['district_latin'] ?? null) === $key));
    }

    /** «Mirzo Ulug‘bek tumani» va «Mirzo Ulug'bek tumani» → «mirzo ulugbek»: kichik harf, apostroflarsiz, «tumani»siz. */
    public static function districtKey(?string $name): string
    {
        $s = mb_strtolower((string) $name);
        $s = str_replace(["'", '‘', '’', 'ʻ', 'ʼ', '`', '´'], '', $s);
        $s = preg_replace('/\btumani\b/u', ' ', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }
}
