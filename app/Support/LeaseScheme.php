<?php

namespace App\Support;

use App\Models\LeaseContract;

/**
 * Tutash hudud sxemasi (shartnomaning 1.5-bandi — «ilova qilinadigan sxema»): xodim chizgan poligon, tomonlari uzunligi,
 * burchak raqamlari, maydoni, gastronomik ko'cha o'qi, shimol yo'nalishi va masshtab. PNG (GD).
 */
class LeaseScheme
{
    public static function png(LeaseContract $c): string
    {
        $ring = $c->polygon ?? [];
        $W = 1400;
        $H = 1000;
        $img = imagecreatetruecolor($W, $H);
        // antialias yoqilsa GD chiziq qalinligini (imagesetthickness) e'tiborsiz qoldiradi; matn baribir silliq chiziladi
        imageantialias($img, false);
        $white = imagecolorallocate($img, 255, 255, 255);
        $ink = imagecolorallocate($img, 30, 41, 59);
        $grey = imagecolorallocate($img, 120, 120, 120);
        $road = imagecolorallocate($img, 245, 158, 11);
        $edge = imagecolorallocate($img, 29, 78, 216);
        $fill = imagecolorallocatealpha($img, 59, 130, 246, 95);
        $red = imagecolorallocate($img, 220, 38, 38);
        imagefill($img, 0, 0, $white);
        $font = resource_path('fonts/DejaVuSans.ttf');
        $bold = resource_path('fonts/DejaVuSans-Bold.ttf');
        $t = fn (string $s) => LeaseContractDocument::script($s);

        $title = $t('Tutash hudud sxemasi — '.($c->street?->name ?? '').', '.($c->district?->name ?? ''));
        imagettftext($img, 22, 0, 40, 50, $ink, $bold, $title);

        if (count($ring) < 3) {
            imagettftext($img, 20, 0, 40, 120, $red, $font, 'Hudud chizilmagan');
            return self::out($img);
        }

        // mahalliy tekis koordinatalar (metr), markaz — poligon o'rtasi
        $n = count($ring);
        $lat0 = array_sum(array_column($ring, 0)) / $n;
        $lng0 = array_sum(array_column($ring, 1)) / $n;
        $kx = cos(deg2rad($lat0)) * 111_320;
        $ky = 110_574;
        $toM = fn ($p) => [($p[1] - $lng0) * $kx, ($p[0] - $lat0) * $ky];
        $pts = array_map($toM, $ring);
        $xs = array_column($pts, 0);
        $ys = array_column($pts, 1);
        $w = max(max($xs) - min($xs), 1.0);
        $h = max(max($ys) - min($ys), 1.0);
        $pad = max($w, $h) * 0.35 + 3; // atrofidagi ko'cha ham ko'rinsin
        [$minX, $maxX, $minY, $maxY] = [min($xs) - $pad, max($xs) + $pad, min($ys) - $pad, max($ys) + $pad];
        $top = 90;
        $bottom = 150;
        $scale = min(($W - 120) / ($maxX - $minX), ($H - $top - $bottom) / ($maxY - $minY));
        $ox = (int) (($W - ($maxX - $minX) * $scale) / 2);
        $oy = $top + (int) ((($H - $top - $bottom) - ($maxY - $minY) * $scale) / 2);
        $px = fn ($m) => [(int) round($ox + ($m[0] - $minX) * $scale), (int) round($oy + ($maxY - $m[1]) * $scale)];

        // gastronomik ko'cha o'qi
        $line = array_map(fn ($p) => $px($toM($p)), $c->street?->line ?? []);
        imagesetclip($img, 0, $top - 20, $W - 1, $H - $bottom + 10); // sarlavha va pastki yozuvlar ustiga chiqmasin
        imagesetthickness($img, 14);
        for ($i = 0; $i < count($line) - 1; $i++) {
            imageline($img, $line[$i][0], $line[$i][1], $line[$i + 1][0], $line[$i + 1][1], $road);
        }
        imagesetthickness($img, 1);
        imagesetclip($img, 0, 0, $W - 1, $H - 1);

        // hudud
        $poly = [];
        foreach ($pts as $m) {
            array_push($poly, ...$px($m));
        }
        imagefilledpolygon($img, $poly, $fill);
        imagesetthickness($img, 4);
        imagepolygon($img, $poly, $edge);
        imagesetthickness($img, 1);

        // tomonlar uzunligi va burchak raqamlari
        [$cx, $cy] = $px([0, 0]);
        for ($i = 0; $i < $n; $i++) {
            $a = $pts[$i];
            $b = $pts[($i + 1) % $n];
            $len = hypot($b[0] - $a[0], $b[1] - $a[1]);
            [$mx, $my] = $px([($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2]);
            // yozuv tashqariga suriladi
            $dx = $mx - $cx;
            $dy = $my - $cy;
            $d = max(hypot($dx, $dy), 1);
            $label = number_format($len, 1, ',', '').' m';
            imagettftext($img, 15, 0, (int) ($mx + $dx / $d * 26) - 28, (int) ($my + $dy / $d * 26) + 6, $ink, $font, $label);

            [$vx, $vy] = $px($a);
            imagefilledellipse($img, $vx, $vy, 16, 16, $red);
            $vdx = $vx - $cx;
            $vdy = $vy - $cy;
            $vd = max(hypot($vdx, $vdy), 1);
            imagettftext($img, 16, 0, (int) ($vx + $vdx / $vd * 22) - 6, (int) ($vy + $vdy / $vd * 22) + 8, $red, $bold, (string) ($i + 1));
        }

        // shimol va masshtab
        imagettftext($img, 20, 0, $W - 62, 140, $ink, $bold, 'Ş');
        imagesetthickness($img, 3);
        imageline($img, $W - 55, 210, $W - 55, 152, $ink);
        imageline($img, $W - 55, 152, $W - 65, 170, $ink);
        imageline($img, $W - 55, 152, $W - 45, 170, $ink);
        $nice = collect([1, 2, 5, 10, 20, 50, 100, 200, 500])->first(fn ($v) => $v * $scale >= 120) ?? 500;
        $bar = (int) ($nice * $scale);
        imageline($img, 40, $H - 110, 40 + $bar, $H - 110, $ink);
        imageline($img, 40, $H - 118, 40, $H - 102, $ink);
        imageline($img, 40 + $bar, $H - 118, 40 + $bar, $H - 102, $ink);
        imagesetthickness($img, 1);
        imagettftext($img, 15, 0, 40, $H - 120, $ink, $font, $nice.' m');

        // pastki yozuv
        imagettftext($img, 20, 0, 40, $H - 60, $ink, $bold, $t('Maydoni: '.number_format(Gps::polygonArea($ring), 1, ',', ' ').' m²').'   ·   '.$n.' '.$t('burchak'));
        imagettftext($img, 15, 0, 40, $H - 28, $grey, $font, $t('To‘q sariq — gastronomik ko‘cha o‘qi; ko‘k — ijaraga beriladigan tutash hudud; qizil raqamlar — burchak nuqtalari.'));

        return self::out($img);
    }

    private static function out(\GdImage $img): string
    {
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
