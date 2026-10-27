<?php

namespace App\Support;

/** Mini App yuborgan qurilma ma'lumotini (JSON) o'qiladigan qisqa matnga aylantiradi. */
class Device
{
    public static function clean(?string $json): ?string
    {
        $json = trim((string) $json);
        if ($json === '' || mb_strlen($json) > 1500 || ! is_array(json_decode($json, true))) {
            return null;
        }

        return $json;
    }

    /** Masalan: "Android · SM-S918B · Telegram android 11.4 · kamera 480x480 (environment)" */
    public static function label(?string $json): string
    {
        $d = json_decode((string) $json, true);
        if (! is_array($d)) {
            return '—';
        }

        $ua = (string) ($d['ua'] ?? '');
        $parts = [];
        if (preg_match('/Android [\d.]+; ([^)]+?)(?: Build|\))/', $ua, $m)) {
            $parts[] = 'Android';
            $parts[] = trim($m[1]);
        } elseif (preg_match('/(iPhone|iPad)/', $ua, $m)) {
            $parts[] = $m[1].' (iOS)';
        } elseif ($ua !== '') {
            $parts[] = mb_substr($ua, 0, 50);
        }
        if (! empty($d['tg']['p'])) {
            $parts[] = 'Telegram '.$d['tg']['p'].' '.($d['tg']['v'] ?? '');
        }
        if (! empty($d['cam']['w'])) {
            $parts[] = 'kamera '.$d['cam']['w'].'x'.($d['cam']['h'] ?? '?').(! empty($d['cam']['f']) ? ' ('.$d['cam']['f'].')' : '');
        }

        return implode(' · ', $parts) ?: '—';
    }
}
