<?php

namespace App\Telegram\Support;

/** Lotin/kirill, apostrof va yozuv xatolariga chidamli oddiy qidiruv. */
class Fuzzy
{
    private const CYR = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo', 'ж' => 'j', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'x', 'ц' => 's', 'ч' => 'ch', 'ш' => 'sh', 'ъ' => '', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
        'я' => 'ya', 'ў' => 'o', 'қ' => 'q', 'ғ' => 'g', 'ҳ' => 'h',
    ];

    public static function normalize(?string $s): string
    {
        $s = mb_strtolower((string) $s);
        $s = strtr($s, self::CYR + ['ş' => 'sh', 'ç' => 'ch', 'ö' => 'o', 'ğ' => 'g', 'ü' => 'u', 'ı' => 'i']);
        $s = preg_replace("/[ʻʼ’‘`´']/u", '', $s);   // o'rikzor = orikzor = o`rikzor
        $s = str_replace(['h'], ['x'], $s);             // xusan/husan, hamid/xamid
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);

        return trim($s);
    }

    /** 0 = mos emas; yuqori = yaxshiroq mos. Barcha so'zlar (taxminan) topilishi kerak. */
    public static function score(string $query, string $haystack): float
    {
        $words = array_filter(explode(' ', $haystack));
        $score = 0.0;

        foreach (array_filter(explode(' ', $query)) as $token) {
            if (str_contains($haystack, $token)) {
                $score += 2 + (in_array($token, $words, true) ? 1 : 0);
                continue;
            }

            $max = strlen($token) >= 6 ? 2 : (strlen($token) >= 4 ? 1 : 0);
            $best = null;
            foreach ($words as $w) {
                $d = min(levenshtein($token, $w), levenshtein($token, substr($w, 0, strlen($token) + $max)));
                $best = $best === null ? $d : min($best, $d);
            }

            if ($best === null || $best > $max) {
                return 0.0;
            }
            $score += 1 - $best * 0.3;
        }

        return $score;
    }
}
