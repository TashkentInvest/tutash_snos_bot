<?php

namespace App\Telegram\Support;

/**
 * Chiquvchi matnlarni imlo jihatdan yagona ko'rinishga keltiradi.
 *
 * classic: oʻ/gʻ uchun to'g'ri tutuq (o‘, g‘), tutuq belgisi uchun ’ (ma’lumot).
 * new:     o‘ → ö, g‘ → ğ, sh → ş, ch → ç (BOT_ALPHABET=new).
 * HTML teglari (havola, <b> va h.k.) o'zgartirilmaydi.
 */
class Script
{
    private const APOS = "['`ʻʼ‘’´]";

    public static function apply(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        $newAlphabet = config('tutash.alphabet') === 'new';

        $parts = preg_split('/(<[^>]*>)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($part === '' || $part[0] === '<') {
                continue;
            }
            $parts[$i] = $newAlphabet ? self::newAlphabet($part) : self::classic($part);
        }

        return implode('', $parts);
    }

    private static function classic(string $s): string
    {
        $s = str_replace(['&#039;', '&#39;', '&apos;'], "'", $s);

        // o', g' (har qanday tutuq belgisi) -> o‘, g‘ ; boshqa harflar orasida -> ’
        $s = preg_replace('/([OoGg])'.self::APOS.'/u', '$1‘', $s);

        return preg_replace('/(?<=\p{L})(?<![OoGg])'.self::APOS.'(?=\p{L})/u', '’', $s);
    }

    private static function newAlphabet(string $s): string
    {
        $s = self::classic($s);

        return preg_replace_callback('/([Oo]‘)|([Gg]‘)|(sh|Sh|SH)|(ch|Ch|CH)/u', function ($m) {
            $src = $m[0];
            $upper = mb_strtoupper(mb_substr($src, 0, 1)) === mb_substr($src, 0, 1);

            $letter = match (true) {
                ! empty($m[1]) => 'ö',
                ! empty($m[2]) => 'ğ',
                ! empty($m[3]) => 'ş',
                default => 'ç',
            };

            return $upper ? mb_strtoupper($letter) : $letter;
        }, $s);
    }
}
