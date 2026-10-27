<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;

class SetTelegramWebhook extends Command
{
    protected $signature = 'bot:webhook {url : To\'liq HTTPS manzil, masalan https://domen.uz/telegram/webhook} {--remove : Webhookni o\'chirish (polling rejimiga qaytish)}';

    protected $description = 'Telegram webhookni maxfiy token bilan o\'rnatadi yoki o\'chiradi.';

    public function handle(Nutgram $bot): int
    {
        if ($this->option('remove')) {
            $bot->deleteWebhook(['drop_pending_updates' => true]);
            $this->info('Webhook o\'chirildi.');

            return self::SUCCESS;
        }

        $secret = (string) config('services.telegram.webhook_secret');
        if (strlen($secret) < 16) {
            $this->error('TELEGRAM_WEBHOOK_SECRET .env da kamida 16 belgi bo\'lishi kerak.');

            return self::FAILURE;
        }

        $bot->setWebhook($this->argument('url'), [
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'max_connections' => 40,
            'drop_pending_updates' => true,
        ]);
        $this->info('Webhook o\'rnatildi.');

        return self::SUCCESS;
    }
}
