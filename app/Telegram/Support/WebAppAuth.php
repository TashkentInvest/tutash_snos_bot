<?php

namespace App\Telegram\Support;

/** Telegram Mini App initData imzosini tekshiradi (https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app). */
class WebAppAuth
{
    /** @return array{id:int}|null  imzo to'g'ri va yangi bo'lsa — foydalanuvchi, aks holda null */
    public static function user(string $initData, int $maxAgeSeconds = 900): ?array
    {
        parse_str($initData, $data);
        $hash = $data['hash'] ?? null;
        if (! is_string($hash) || empty($data['auth_date']) || empty($data['user'])) {
            return null;
        }
        unset($data['hash']);
        ksort($data);

        $check = collect($data)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $secret = hash_hmac('sha256', (string) config('nutgram.token'), 'WebAppData', true);

        if (! hash_equals(hash_hmac('sha256', $check, $secret), $hash)) {
            return null;
        }
        if (abs(time() - (int) $data['auth_date']) > $maxAgeSeconds) {
            return null;
        }

        $user = json_decode($data['user'], true);

        return isset($user['id']) ? ['id' => (int) $user['id']] : null;
    }
}
