<?php

namespace App\Support;

use App\Models\Staff;
use App\Telegram\Support\Ui;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use Throwable;

/**
 * Tizimdagi xatoliklar haqida Telegram orqali ogohlantirish (DEBUG_ALERT_PHONES — botda ro'yxatdan o'tgan raqamlar).
 * Bir xil xato (sinf + fayl + qator) ALERT_EVERY_MINUTES ichida bir marta yuboriladi — xatolar oqimi spam bo'lmasin.
 * Hech qachon o'zi xato tashlamaydi: Telegram yoki baza ishlamasa jim o'tib ketadi.
 */
class ErrorAlert
{
    public const ALERT_EVERY_MINUTES = 10;

    private static bool $sending = false;

    public static function report(Throwable $e, ?string $where = null): void
    {
        if (self::$sending) {
            return; // ogohlantirish yuborishdagi xato qayta ogohlantirish chaqirmasin
        }
        // xodim botni bloklagan (403) — odatiy hol, tizim xatosi emas
        if ($e instanceof \SergiX44\Nutgram\Telegram\Exceptions\TelegramException && $e->getCode() === 403) {
            return;
        }
        self::$sending = true;
        try {
            // avval takrorni tekshiramiz (arzon), keyin bazadan oluvchilar
            $key = 'error-alert:'.md5(get_class($e).'|'.$e->getFile().'|'.$e->getLine());
            if (! Cache::add($key, 1, now()->addMinutes(self::ALERT_EVERY_MINUTES))) {
                return;
            }
            $chatIds = self::chatIds();
            if (! $chatIds) {
                return;
            }
            $text = self::text($e, $where);
            foreach ($chatIds as $chatId) {
                try {
                    app(Nutgram::class)->sendMessage($text, ['chat_id' => $chatId, 'parse_mode' => ParseMode::HTML]);
                } catch (Throwable) {
                }
            }
        } catch (Throwable) {
        } finally {
            self::$sending = false;
        }
    }

    /** @return list<int> */
    public static function chatIds(): array
    {
        $phones = collect(explode(',', (string) config('tutash.debug_alert_phones')))
            ->map(fn ($p) => Staff::normalizePhone(trim($p)))->filter()->unique()->values();
        if ($phones->isEmpty()) {
            return [];
        }

        return Staff::whereIn('phone', $phones)->whereNotNull('telegram_id')->pluck('telegram_id')
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public static function text(Throwable $e, ?string $where = null): string
    {
        $where ??= app()->runningInConsole()
            ? 'artisan '.implode(' ', array_slice($_SERVER['argv'] ?? [], 1))
            : request()->method().' /'.ltrim(request()->path(), '/');
        $file = Str::after($e->getFile(), base_path().DIRECTORY_SEPARATOR);

        return "🐞 <b>Debug: tizimda xatolik mavjud</b>\n\n"
            .'<b>'.Ui::e(class_basename($e)).'</b>: '.Ui::e(Str::limit($e->getMessage(), 400))."\n"
            .'📄 <code>'.Ui::e($file).':'.$e->getLine()."</code>\n"
            .'📍 '.Ui::e(Str::limit($where, 150))."\n"
            .'🕒 '.now()->format('d.m.Y H:i:s').' · '.Ui::e((string) config('app.env'));
    }
}
