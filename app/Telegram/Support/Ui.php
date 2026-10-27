<?php

namespace App\Telegram\Support;

use App\Models\Site;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;

/** Xabar ko'rinishi va tugmalar uchun umumiy yordamchilar. */
class Ui
{
    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function cut(?string $s, int $len): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));

        return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1).'…' : $s;
    }

    /** Imlo (alifbo) filtri qo'llangan matn: bildirishnoma va tugmalar uchun. */
    public static function t(?string $text): string
    {
        return Script::apply($text);
    }

    public static function btn(string $text, string $data): InlineKeyboardButton
    {
        return InlineKeyboardButton::make(Script::apply($text), callback_data: $data);
    }

    public static function markup(array $rows): InlineKeyboardMarkup
    {
        $m = InlineKeyboardMarkup::make();
        foreach ($rows as $row) {
            $m->addRow(...(is_array($row) ? $row : [$row]));
        }

        return $m;
    }

    /** Callback bosilgan bo'lsa xabarni tahrirlaydi, aks holda yangi xabar yuboradi. */
    public static function show(Nutgram $bot, string $text, ?InlineKeyboardMarkup $markup = null): void
    {
        $opt = self::options($markup);
        $text = Script::apply($text);

        if ($bot->callbackQuery()?->message) {
            try {
                $bot->editMessageText($text, $opt);

                return;
            } catch (\Throwable) {
                // "message is not modified" yoki xabar o'chirilgan — yangisini yuboramiz
            }
        }

        $bot->sendMessage($text, $opt);
    }

    public static function say(Nutgram $bot, string $text, $markup = null): void
    {
        $bot->sendMessage(Script::apply($text), self::options($markup));
    }

    /** reply_markup null bo'lsa umuman yuborilmaydi (Telegram "null" ni rad etadi). */
    private static function options($markup): array
    {
        return array_filter(
            ['parse_mode' => ParseMode::HTML, 'reply_markup' => $markup, 'disable_web_page_preview' => true],
            fn ($v) => $v !== null,
        );
    }

    public static function removeKeyboard(): ReplyKeyboardRemove
    {
        return ReplyKeyboardRemove::make(true);
    }

    public static function statusEmoji(Site $site): string
    {
        return $site->isDemolished() ? '✅' : ($site->last_inspected_at ? '🔁' : '⏳');
    }

    /**
     * Obyekt kartochkasi. Xodim: to'liq pasport, maydon, tekshiruv sanasi.
     * Aholi: faqat manzil, huquqbuzar, sud qarori, holat va tuman mas'uli raqami.
     */
    public static function siteCard(Site $site, bool $citizen = false): string
    {
        $lines = [
            '📍 <b>'.self::e($site->address).'</b>',
            'MFY: '.self::e($site->mahalla?->name),
            'Huquqbuzar: '.self::e($site->violator_name ?: '—'),
        ];
        if (! $citizen) {
            if ($site->violator_passport) {
                $lines[] = 'Pasport: '.self::e($site->violator_passport);
            }
            $lines[] = 'Egallangan maydon: '.($site->area_m2 !== null ? rtrim(rtrim(number_format($site->area_m2, 2, '.', ' '), '0'), '.').' m²' : '—');
        }
        if ($site->court_number || $site->court_date) {
            $lines[] = 'Sud qarori: '.self::e(trim(($site->court_number ?: '').' '.($site->court_date?->format('d.m.Y') ?: '')))
                .($site->court_status ? ' ('.self::e($site->court_status).')' : '');
        } else {
            $lines[] = 'Sud qarori: ma’lumot mavjud emas';
        }
        $lines[] = '';
        $lines[] = 'Holat: '.($site->isDemolished()
            ? '✅ <b>Buzilgan</b>'.($site->demolished_at ? ' ('.$site->demolished_at->format('d.m.Y').')' : '')
            : ($site->relationLoaded('pendingReview') && $site->pendingReview
                ? '🕓 <b>Ko‘rib chiqilmoqda</b> (natija moderator tasdig‘ini kutmoqda)'
                : '⏳ <b>Buzilmagan</b>'));
        if (! $citizen && $site->last_inspected_at) {
            $lines[] = 'Oxirgi tekshiruv: '.$site->last_inspected_at->format('d.m.Y H:i');
        }
        if ($citizen && ($phone = $site->district?->contactLine())) {
            $lines[] = '';
            $lines[] = '📞 '.self::e($site->district->name).' bo‘yicha mas’ul xodim: <b>'.self::e($phone).'</b>';
        }

        return implode("
", $lines);
    }
}
