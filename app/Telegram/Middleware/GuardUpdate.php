<?php

namespace App\Telegram\Middleware;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use SergiX44\Nutgram\Nutgram;

/**
 * Har bir update uchun: faqat shaxsiy chat, foydalanuvchi bo'yicha tezlik chegarasi
 * va bir vaqtda bitta update'ni qayta ishlash (qo'sh bosishlar poygasini oldini oladi).
 */
class GuardUpdate
{
    public function __invoke(Nutgram $bot, $next): void
    {
        $userId = $bot->userId();
        $chat = $bot->chat();

        if (! $userId || ($chat && $chat->type !== 'private')) {
            return;
        }

        $key = 'tg-rate:'.$userId;
        if (RateLimiter::tooManyAttempts($key, config('tutash.rate_limit_per_minute'))) {
            $bot->callbackQuery() && $bot->answerCallbackQuery(['text' => 'Juda tez. Biroz kuting.']);

            return;
        }
        RateLimiter::hit($key, 60);

        $lock = Cache::lock('tg-lock:'.$userId, 15);
        if ($bot->callbackQuery()) {
            // tugma: qo'sh bosish — ikkinchisi tashlab yuboriladi (poyga bo'lmasin)
            if (! $lock->get()) {
                $bot->answerCallbackQuery();

                return;
            }
        } else {
            // matn/kontakt/joylashuv: yo'qolmasin — oldingi update tugashini 5 soniyagacha kutamiz
            try {
                $lock->block(5);
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
                return;
            }
        }

        try {
            $next($bot);
        } finally {
            $lock->release();
        }
    }
}
