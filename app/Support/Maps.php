<?php

namespace App\Support;

/** Barcha xarita havolalari Yandex Xaritalarda ochiladi (pt = uzunlik,kenglik). */
class Maps
{
    public static function url(float|string $lat, float|string $lng): string
    {
        return 'https://yandex.com/maps/?pt='.$lng.','.$lat.'&z=17&l=map';
    }
}
