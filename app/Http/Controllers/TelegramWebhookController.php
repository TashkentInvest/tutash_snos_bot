<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SergiX44\Nutgram\Nutgram;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, Nutgram $bot)
    {
        $secret = (string) config('services.telegram.webhook_secret');

        // Maxfiy token sozlanmagan bo'lsa yoki mos kelmasa — Telegram emas, rad etamiz.
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        $bot->run();

        return response()->noContent();
    }
}
