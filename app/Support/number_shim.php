<?php

/*
 * VAQTINCHALIK: PHP'da ext-intl yo'q bo'lgan dev muhit uchun Illuminate\Support\Number o'rnini bosadi.
 * intl o'rnatilgach (extension_loaded('intl')) bu fayl hech narsa qilmaydi va fayl butunlay o'chirilishi mumkin.
 */

namespace Illuminate\Support {
    if (! extension_loaded('intl') && ! class_exists(Number::class, false)) {
        class Number
        {
            public static function format(int|float $number, ?int $precision = null, ?int $maxPrecision = null, ?string $locale = null): string
            {
                $dec = $precision ?? ($maxPrecision !== null ? $maxPrecision : 0);
                $out = number_format($number, $dec, '.', ' ');
                if ($precision === null && $maxPrecision !== null && str_contains($out, '.')) {
                    $out = rtrim(rtrim($out, '0'), '.');
                }

                return $out;
            }

            public static function percentage(int|float $number, int $precision = 0, ?int $maxPrecision = null, ?string $locale = null): string
            {
                return static::format($number, $precision, $maxPrecision).'%';
            }

            public static function currency(int|float $number, string $in = 'USD', ?string $locale = null): string
            {
                return static::format($number, 2).' '.$in;
            }

            public static function fileSize(int|float $bytes, int $precision = 0, ?int $maxPrecision = null): string
            {
                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                for ($i = 0; ($bytes / 1024) > 0.9 && ($i < count($units) - 1); $i++) {
                    $bytes /= 1024;
                }

                return sprintf('%s %s', static::format($bytes, $precision, $maxPrecision), $units[$i]);
            }

            public static function abbreviate(int|float $number, int $precision = 0, ?int $maxPrecision = null): string
            {
                return static::forHumans($number, $precision, $maxPrecision, true);
            }

            public static function forHumans(int|float $number, int $precision = 0, ?int $maxPrecision = null, bool $abbreviate = false): string
            {
                $units = ['' => 1, 'K' => 1e3, 'M' => 1e6, 'B' => 1e9, 'T' => 1e12];
                $unit = '';
                foreach ($units as $u => $v) {
                    if (abs($number) >= $v) {
                        $unit = $u;
                    }
                }

                return static::format($number / $units[$unit], $precision, $maxPrecision).$unit;
            }

            public static function useLocale(string $locale): void {}

            public static function useCurrency(string $currency): void {}

            public static function withLocale(string $locale, callable $callback) { return $callback(); }
        }
    }
}
