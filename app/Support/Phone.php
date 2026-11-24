<?php

namespace App\Support;

/** Telefon raqamini panelda bir xil ko'rinishda chiqarish: 901234567 → +998 90 123 45 67. */
class Phone
{
    public static function format(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($d) === 12 && str_starts_with($d, '998')) {
            $d = substr($d, 3);
        }
        if (strlen($d) !== 9) {
            return filled($phone) ? $phone : null;
        }

        return '+998 '.substr($d, 0, 2).' '.substr($d, 2, 3).' '.substr($d, 5, 2).' '.substr($d, 7, 2);
    }
}
