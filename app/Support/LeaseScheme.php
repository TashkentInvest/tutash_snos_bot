<?php

namespace App\Support;

use App\Models\LeaseContract;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Tutash hudud sxemasi (shartnomaning 1.5-bandi — «ilova qilinadigan sxema»): fonda sun'iy yo'ldosh surati (Esri, ko'chalar bilan),
 * ustida xodim chizgan poligon, tomonlari uzunligi, burchak raqamlari, maydoni, gastronomik ko'cha o'qi, shimol va masshtab.
 * Xarita Web Mercator plitkalaridan yig'iladi (keshlanadi); xizmat javob bermasa — oq fonda chiziladi. JPEG (GD).
 */
class LeaseScheme
{
    private const W = 1400;
    private const H = 1000;
    private const TOP = 90;      // sarlavha (oq)
    private const BOTTOM = 150;  // masshtab va izoh (oq)
    private const SIDE = 40;
    private const MAX_ZOOM = 19;
    private const TILE = 256;

    /** fon qatlamlari: sun'iy yo'ldosh + yo'llar (shaffof) */
    private const LAYERS = ['World_Imagery', 'Reference/World_Transportation'];

    public static function png(LeaseContract $c): string
    {
        return self::render($c);
    }

    public static function render(LeaseContract $c): string
    {
        $ring = $c->polygon ?? [];
        [$W, $H, $top, $bottom] = [self::W, self::H, self::TOP, self::BOTTOM];
        $img = imagecreatetruecolor($W, $H);
        // antialias yoqilsa GD chiziq qalinligini e'tiborsiz qoldiradi; matn baribir silliq
        imageantialias($img, false);
        imagealphablending($img, true);
        $white = imagecolorallocate($img, 255, 255, 255);
        $ink = imagecolorallocate($img, 30, 41, 59);
        $grey = imagecolorallocate($img, 110, 110, 110);
        $road = imagecolorallocatealpha($img, 245, 158, 11, 35);
        $edge = imagecolorallocate($img, 37, 99, 235);
        $red = imagecolorallocate($img, 220, 38, 38);
        imagefill($img, 0, 0, $white);
        $font = resource_path('fonts/DejaVuSans.ttf');
        $bold = resource_path('fonts/DejaVuSans-Bold.ttf');
        $t = fn (string $s) => LeaseContractDocument::script($s);

        imagettftext($img, 22, 0, self::SIDE, 50, $ink, $bold, $t('Tutash hudud sxemasi — '.($c->street?->name ?? '').', '.($c->district?->name ?? '')));
        if (count($ring) < 3) {
            imagettftext($img, 20, 0, self::SIDE, 120, $red, $font, 'Hudud chizilmagan');

            return self::out($img);
        }

        // chizma maydoni (piksel)
        [$ax, $ay, $aw, $ah] = [self::SIDE, $top, $W - 2 * self::SIDE, $H - $top - $bottom];

        // atrofidagi ko'cha ham ko'rinsin: poligon o'lchamiga qarab zaxira (metr)
        $n = count($ring);
        $lats = array_column($ring, 0);
        $lngs = array_column($ring, 1);
        $lat0 = array_sum($lats) / $n;
        $kx = cos(deg2rad($lat0)) * 111_320;
        $ky = 110_574;
        $wM = max((max($lngs) - min($lngs)) * $kx, 1.0);
        $hM = max((max($lats) - min($lats)) * $ky, 1.0);
        $pad = max($wM, $hM) * 0.6 + 8; // atrof: ko'cha, binolar
        [$s, $n0, $e0, $w0] = [min($lats) - $pad / $ky, max($lats) + $pad / $ky, max($lngs) + $pad / $kx, min($lngs) - $pad / $kx];

        // masshtab: chizma maydoniga sig'adigan eng katta zoom (plitkalar 1–2 baravar kattalashtiriladi)
        $sw = self::world($s, $w0, self::MAX_ZOOM);
        $ne = self::world($n0, $e0, self::MAX_ZOOM);
        $s19 = min($aw / max($ne[0] - $sw[0], 1e-6), $ah / max($sw[1] - $ne[1], 1e-6));
        $z = (int) max(10, min(self::MAX_ZOOM, floor(self::MAX_ZOOM + log($s19, 2))));
        $scale = $s19 * 2 ** (self::MAX_ZOOM - $z);
        $center = self::world(($s + $n0) / 2, ($w0 + $e0) / 2, $z);
        $vw = $aw / $scale;
        $vh = $ah / $scale;
        $vx0 = $center[0] - $vw / 2;
        $vy0 = $center[1] - $vh / 2;
        $px = function (array $p) use ($z, $vx0, $vy0, $scale, $ax, $ay) {
            $w = self::world($p[0], $p[1], $z);

            return [(int) round($ax + ($w[0] - $vx0) * $scale), (int) round($ay + ($w[1] - $vy0) * $scale)];
        };

        $map = self::basemap($img, $z, $vx0, $vy0, $vw, $vh, $ax, $ay, $aw, $ah);
        $map || imagerectangle($img, $ax, $ay, $ax + $aw - 1, $ay + $ah - 1, imagecolorallocate($img, 220, 220, 220));

        imagesetclip($img, $ax, $ay, $ax + $aw - 1, $ay + $ah - 1); // chizma maydonidan chiqmasin

        // gastronomik ko'cha o'qi (yarim shaffof — ostidagi yo'l ko'rinib tursin)
        $line = array_map($px, $c->street?->line ?? []);
        imagesetthickness($img, 12);
        for ($i = 0; $i < count($line) - 1; $i++) {
            imageline($img, $line[$i][0], $line[$i][1], $line[$i + 1][0], $line[$i + 1][1], $road);
        }

        // hudud: oq hoshiya + ko'k chiziq — suratda ham aniq ko'rinadi
        $pp = array_map($px, $ring);
        $flat = array_merge(...$pp);
        // yarim shaffof bo'yoq: nusxaga to'liq rangda chiziladi va 35% bilan qo'shiladi (ostidagi surat ko'rinib turadi)
        $layer = imagecreatetruecolor($W, $H);
        imagecopy($layer, $img, 0, 0, 0, 0, $W, $H);
        imagefilledpolygon($layer, $flat, imagecolorallocate($layer, 59, 130, 246));
        imagecopymerge($img, $layer, $ax, $ay, $ax, $ay, $aw, $ah, 35);
        imagedestroy($layer);
        imagesetthickness($img, 8);
        imagepolygon($img, $flat, $white);
        imagesetthickness($img, 4);
        imagepolygon($img, $flat, $edge);
        imagesetthickness($img, 1);

        // tomonlar uzunligi (metr) va burchak raqamlari — markazdan tashqariga suriladi
        $cx = array_sum(array_column($pp, 0)) / $n;
        $cy = array_sum(array_column($pp, 1)) / $n;
        for ($i = 0; $i < $n; $i++) {
            $a = $ring[$i];
            $b = $ring[($i + 1) % $n];
            $len = hypot(($b[1] - $a[1]) * $kx, ($b[0] - $a[0]) * $ky);
            $mx = ($pp[$i][0] + $pp[($i + 1) % $n][0]) / 2;
            $my = ($pp[$i][1] + $pp[($i + 1) % $n][1]) / 2;
            $d = max(hypot($mx - $cx, $my - $cy), 1);
            self::text($img, 15, (int) ($mx + ($mx - $cx) / $d * 30) - 30, (int) ($my + ($my - $cy) / $d * 30) + 6, $ink, $bold, number_format($len, 1, ',', '').' m');

            [$vx, $vy] = $pp[$i];
            imagefilledellipse($img, $vx, $vy, 18, 18, $white);
            imagefilledellipse($img, $vx, $vy, 13, 13, $red);
            $vd = max(hypot($vx - $cx, $vy - $cy), 1);
            self::text($img, 17, (int) ($vx + ($vx - $cx) / $vd * 24) - 6, (int) ($vy + ($vy - $cy) / $vd * 24) + 8, $red, $bold, (string) ($i + 1));
        }

        // shimol (oq yostiqcha ustida)
        imagefilledrectangle($img, $W - 90, $top + 14, $W - 50, $top + 104, imagecolorallocatealpha($img, 255, 255, 255, 25));
        imagettftext($img, 20, 0, $W - 79, $top + 44, $ink, $bold, 'Ş');
        imagesetthickness($img, 3);
        imageline($img, $W - 70, $top + 95, $W - 70, $top + 55, $ink);
        imageline($img, $W - 70, $top + 55, $W - 79, $top + 71, $ink);
        imageline($img, $W - 70, $top + 55, $W - 61, $top + 71, $ink);
        imagesetthickness($img, 1);
        imagesetclip($img, 0, 0, $W - 1, $H - 1);

        // masshtab chizig'i (oq maydonda)
        $mpp = 156543.03392 * cos(deg2rad($lat0)) / 2 ** $z / $scale; // bir chiqish pikseli necha metr
        $nice = collect([1, 2, 5, 10, 20, 50, 100, 200, 500])->first(fn ($v) => $v / $mpp >= 120) ?? 500;
        $bar = (int) ($nice / $mpp);
        $by = $H - $bottom + 40;
        imagesetthickness($img, 3);
        imageline($img, self::SIDE, $by, self::SIDE + $bar, $by, $ink);
        imageline($img, self::SIDE, $by - 8, self::SIDE, $by + 8, $ink);
        imageline($img, self::SIDE + $bar, $by - 8, self::SIDE + $bar, $by + 8, $ink);
        imagesetthickness($img, 1);
        imagettftext($img, 15, 0, self::SIDE + $bar + 12, $by + 6, $ink, $font, $nice.' m');
        $map && imagettftext($img, 13, 0, $W - 420, $by + 6, $grey, $font, $t('Fon: sun’iy yo‘ldosh surati © Esri'));

        imagettftext($img, 20, 0, self::SIDE, $H - 50, $ink, $bold, $t('Maydoni: '.number_format(Gps::polygonArea($ring), 1, ',', ' ').' m²').'   ·   '.$n.' '.$t('burchak'));
        imagettftext($img, 14, 0, self::SIDE, $H - 20, $grey, $font, $t('To‘q sariq — gastronomik ko‘cha o‘qi; ko‘k — ijaraga beriladigan tutash hudud; qizil raqamlar — burchak nuqtalari.')
);

        return self::out($img);
    }

    /** Web Mercator: [lat, lng] → zoom darajasidagi dunyo pikseli [x, y] */
    private static function world(float $lat, float $lng, int $z): array
    {
        $size = self::TILE * 2 ** $z;
        $r = deg2rad($lat);

        return [($lng + 180) / 360 * $size, (1 - log(tan($r) + 1 / cos($r)) / M_PI) / 2 * $size];
    }

    /** Fon: kerakli plitkalarni yig'ib, chizma maydoniga joylaydi. Hech biri olinmasa — false (oq fon qoladi). */
    private static function basemap(\GdImage $img, int $z, float $vx0, float $vy0, float $vw, float $vh, int $ax, int $ay, int $aw, int $ah): bool
    {
        $tx0 = (int) floor($vx0 / self::TILE);
        $ty0 = (int) floor($vy0 / self::TILE);
        $tx1 = (int) floor(($vx0 + $vw) / self::TILE);
        $ty1 = (int) floor(($vy0 + $vh) / self::TILE);
        if (($tx1 - $tx0 + 1) * ($ty1 - $ty0 + 1) > 40) {
            return false; // kutilmagan katta hudud — tarmoqni band qilmaslik uchun
        }
        $canvas = imagecreatetruecolor(($tx1 - $tx0 + 1) * self::TILE, ($ty1 - $ty0 + 1) * self::TILE);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        $got = false;
        foreach (self::LAYERS as $i => $layer) {
            foreach (self::tiles($layer, $z, range($tx0, $tx1), range($ty0, $ty1)) as [$x, $y, $bin]) {
                $tile = @imagecreatefromstring($bin);
                if (! $tile) {
                    continue;
                }
                imagecopy($canvas, $tile, ($x - $tx0) * self::TILE, ($y - $ty0) * self::TILE, 0, 0, self::TILE, self::TILE);
                imagedestroy($tile);
                $i === 0 && $got = true; // asosiy (sun'iy yo'ldosh) qatlam bo'lsa — fon bor
            }
        }
        if ($got) {
            imagecopyresampled($img, $canvas, $ax, $ay, (int) round($vx0 - $tx0 * self::TILE), (int) round($vy0 - $ty0 * self::TILE),
                $aw, $ah, (int) round($vw), (int) round($vh));
        }
        imagedestroy($canvas);

        return $got;
    }

    /** @return list<array{0: int, 1: int, 2: string}> plitkalar (diskda keshlanadi) */
    private static function tiles(string $layer, int $z, array $xs, array $ys): array
    {
        $disk = Storage::disk('local');
        $out = [];
        $missing = [];
        foreach ($xs as $x) {
            foreach ($ys as $y) {
                $path = 'tiles/'.str_replace('/', '-', $layer)."/{$z}/{$x}/{$y}";
                $disk->exists($path) ? $out[] = [$x, $y, $disk->get($path)] : $missing[] = [$x, $y, $path];
            }
        }
        if ($missing === []) {
            return $out;
        }
        try {
            $responses = Http::pool(fn ($pool) => array_map(fn ($m) => $pool->timeout(8)->connectTimeout(4)
                ->get("https://server.arcgisonline.com/ArcGIS/rest/services/{$layer}/MapServer/tile/{$z}/{$m[1]}/{$m[0]}"), $missing));
        } catch (\Throwable $e) {
            report($e);

            return $out;
        }
        foreach ($missing as $i => [$x, $y, $path]) {
            $r = $responses[$i] ?? null;
            if ($r instanceof Response && $r->successful() && strlen($r->body()) > 100) {
                $disk->put($path, $r->body());
                $out[] = [$x, $y, $r->body()];
            }
        }

        return $out;
    }

    /** Suratda ham o'qiladigan yozuv: oq hoshiya bilan. */
    private static function text(\GdImage $img, int $size, int $x, int $y, int $color, string $font, string $s): void
    {
        $white = imagecolorallocate($img, 255, 255, 255);
        foreach ([[-2, 0], [2, 0], [0, -2], [0, 2], [-2, -2], [2, 2], [-2, 2], [2, -2]] as [$dx, $dy]) {
            imagettftext($img, $size, 0, $x + $dx, $y + $dy, $white, $font, $s);
        }
        imagettftext($img, $size, 0, $x, $y, $color, $font, $s);
    }

    /** JPEG — sun'iy yo'ldosh surati PNG da juda katta bo'lardi (Word fayl yengil qolsin). */
    private static function out(\GdImage $img): string
    {
        ob_start();
        imagejpeg($img, null, 88);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
