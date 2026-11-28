<?php

namespace App\Support;

/**
 * O'zbek kirill → lotin (panelda bir xil yozuv bo'lsin: Dizayn-kod botida tuman va ko'cha nomlari kirillcha saqlanadi).
 * «Бектемир тумани» → «Bektemir tumani», «Мирзо Улуғбек» → «Mirzo Ulug‘bek». Lotin matn o'zgarmaydi.
 */
class Latin
{
    private const MAP = [
        'Ё' => 'Yo', 'ё' => 'yo', 'Ю' => 'Yu', 'ю' => 'yu', 'Я' => 'Ya', 'я' => 'ya', 'Ч' => 'Ch', 'ч' => 'ch', 'Ш' => 'Sh', 'ш' => 'sh',
        'Щ' => 'Sh', 'щ' => 'sh', 'Ц' => 'Ts', 'ц' => 'ts', 'Ғ' => 'G‘', 'ғ' => 'g‘', 'Ў' => 'O‘', 'ў' => 'o‘', 'Қ' => 'Q', 'қ' => 'q',
        'Ҳ' => 'H', 'ҳ' => 'h', 'Ж' => 'J', 'ж' => 'j', 'А' => 'A', 'а' => 'a', 'Б' => 'B', 'б' => 'b', 'В' => 'V', 'в' => 'v',
        'Г' => 'G', 'г' => 'g', 'Д' => 'D', 'д' => 'd', 'З' => 'Z', 'з' => 'z', 'И' => 'I', 'и' => 'i', 'Й' => 'Y', 'й' => 'y',
        'К' => 'K', 'к' => 'k', 'Л' => 'L', 'л' => 'l', 'М' => 'M', 'м' => 'm', 'Н' => 'N', 'н' => 'n', 'О' => 'O', 'о' => 'o',
        'П' => 'P', 'п' => 'p', 'Р' => 'R', 'р' => 'r', 'С' => 'S', 'с' => 's', 'Т' => 'T', 'т' => 't', 'У' => 'U', 'у' => 'u',
        'Ф' => 'F', 'ф' => 'f', 'Х' => 'X', 'х' => 'x', 'Ы' => 'I', 'ы' => 'i', 'Э' => 'E', 'э' => 'e', 'Ъ' => '’', 'ъ' => '’', 'Ь' => '', 'ь' => '',
    ];

    public static function of(?string $s): string
    {
        $s = (string) $s;
        if (! preg_match('/[\x{0400}-\x{04FF}]/u', $s)) {
            return $s;
        }
        // «Е» so'z boshida va unlidan keyin — «Ye»
        $s = preg_replace_callback('/(^|[\s\-«"(АаЕеЁёИиОоУуЎўЭэЮюЯя])([Ее])/u',
            fn ($m) => $m[1].($m[2] === 'Е' ? 'Ye' : 'ye'), $s);

        return strtr(str_replace(['Е', 'е'], ['E', 'e'], $s), self::MAP);
    }
}
