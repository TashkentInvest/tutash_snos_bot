<?php

namespace App\Telegram\Handlers;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Telegram\Support\Ui;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;

/**
 * Kunlik davomat: 08:00 da xodimga so'rov yuboriladi, javob 09:00 gacha qabul qilinadi.
 *
 * Ha  -> joylashuv (faqat tugma orqali) -> dumaloq video (majburiy) -> "present"
 * Yo'q -> sabab (matn) -> "absent"
 * Javob yo'q -> muddat tugagach "missed" (attendance:close)
 */
class AttendanceHandler
{
    /** Xodimning bugungi tugallanmagan davomat jarayoni (muddat o'tgan bo'lsa — yopiladi). */
    public static function open(Staff $staff): ?Attendance
    {
        $row = Attendance::where('staff_id', $staff->id)->whereDate('date', today())
            ->whereIn('status', [Attendance::LOCATING, Attendance::RECORDING, Attendance::REASONING])->first();

        if ($row && self::expired()) {
            $row->update(['status' => Attendance::MISSED]);

            return null;
        }

        return $row;
    }

    public static function expired(): bool
    {
        return now()->greaterThan(Attendance::deadline());
    }

    /** Inline tugmali so'rov matni (buyruq va test uchun umumiy). */
    public static function promptText(Staff $staff): string
    {
        return '<b>Davomat</b>'."\n\nHurmatli <b>".Ui::e($staff->full_name).'</b>!'
            ."\nIsh kuni boshlandi. Siz hududga chiqib, ishni boshladingizmi?\n\n"
            .'<i>Javobni soat '.config('tutash.attendance.deadline').' gacha bildirishingiz kerak.</i>';
    }

    public static function promptMarkup()
    {
        return Ui::markup([[Ui::btn('✅ Ha, ishni boshladim', 'at:1'), Ui::btn('❌ Yo‘q, ishda emasman', 'at:0')]]);
    }

    /** Davomat Mini App'ini (joylashuv va jonli video) ochuvchi tugma. */
    public static function webAppMarkup(string $label): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            Ui::t($label),
            web_app: new WebAppInfo(rtrim((string) config('app.url'), '/').'/tg/attendance-location'),
        ));
    }

    // ---------------------------------------------------------------- callbacks

    public function answer(Nutgram $bot, string $value): void
    {
        if (! $staff = BrowseHandler::guardEmployee($bot)) {
            return;
        }

        $row = Attendance::where('staff_id', $staff->id)->whereDate('date', today())->first();
        if (! $row) {
            $bot->answerCallbackQuery(['text' => Ui::t('Davomat so‘rovi topilmadi.'), 'show_alert' => true]);

            return;
        }

        if (in_array($row->status, [Attendance::PRESENT, Attendance::ABSENT], true)) {
            $bot->answerCallbackQuery(['text' => Ui::t('Javobingiz avval qayd etilgan.'), 'show_alert' => true]);

            return;
        }

        if ($row->status === Attendance::MISSED || self::expired()) {
            $row->isOpen() && $row->update(['status' => Attendance::MISSED]);
            $bot->answerCallbackQuery(['text' => Ui::t('Davomatni qabul qilish muddati ('.config('tutash.attendance.deadline').') tugagan.'), 'show_alert' => true]);

            return;
        }

        $bot->answerCallbackQuery();

        if ($value === '1') {
            $row->update(['status' => Attendance::LOCATING]);
            Ui::show($bot, '✅ Tanlovingiz: <b>ishni boshladim</b>.');
            $kb = self::webAppMarkup('📍 Joylashuvni yuborish');
            Ui::say($bot, "<b>1/2.</b> Pastdagi tugmani bosib, <b>joylashuvingizni</b> telefon GPS’idan aniqlang.\n<i>Joylashuv faqat shu tugma orqali qabul qilinadi; xabar sifatida yuborilgan, uzatilgan yoki xaritadan tanlangan joylashuv qabul qilinmaydi.</i>", $kb);

            return;
        }

        $row->update(['status' => Attendance::REASONING]);
        Ui::show($bot, '❌ Tanlovingiz: <b>ishda emasman</b>.');
        Ui::say($bot, 'Iltimos, ishda bo‘lmaslik <b>sababini</b> yozing (kamida 10 ta belgi):');
    }

    // ---------------------------------------------------------------- xabarlar

    public function location(Nutgram $bot, Staff $staff, Attendance $row): void
    {
        if ($row->status !== Attendance::LOCATING) {
            $this->wrongInput($bot, $row);

            return;
        }

        // Joylashuv faqat Mini App (qurilma GPS'i, Telegram imzosi bilan) orqali qabul qilinadi.
        // Xabar sifatida kelgan har qanday joylashuv (uzatilgan, «yashirin muallif» bilan, xaritadan tanlangan) rad etiladi.
        AuditLog::record('attendance.bad_location', $bot->userId(), ['attendance' => $row->id]);
        Ui::say($bot, '❗ Joylashuv xabar sifatida qabul qilinmaydi. Iltimos, yuqoridagi «📍 Joylashuvni yuborish» tugmasini bosing — joylashuv telefon GPS’idan avtomatik aniqlanadi.');
    }

    /** Video faqat Mini App ichida jonli yoziladi; chatga yuborilgan (dumaloq, oddiy, uzatilgan) video qabul qilinmaydi. */
    public function videoNote(Nutgram $bot, Staff $staff, Attendance $row): void
    {
        AuditLog::record('attendance.video_in_chat', $bot->userId(), ['attendance' => $row->id]);
        Ui::say($bot, '❗ Video chatga yuborilgan holda qabul qilinmaydi. Pastdagi tugma orqali oynani ochib, videoni <b>shu oynada kamera orqali</b> yozing.', self::webAppMarkup('🎥 Videoni yozish'));
    }

    public function reason(Nutgram $bot, Staff $staff, Attendance $row): void
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $bot->message()->text));
        if (mb_strlen($text) < 10 || mb_strlen($text) > 1000) {
            Ui::say($bot, '❗ Sabab 10 tadan 1000 tagacha belgidan iborat bo‘lishi kerak.');

            return;
        }

        $row->update(['reason' => $text, 'status' => Attendance::ABSENT, 'responded_at' => now()]);
        AuditLog::record('attendance.absent', $bot->userId(), ['attendance' => $row->id]);
        Ui::say($bot, '✅ Javobingiz qayd etildi. Ko‘rsatilgan sabab: <i>'.Ui::e($text).'</i>');
    }

    public function wrongInput(Nutgram $bot, Attendance $row): void
    {
        $hint = match ($row->status) {
            Attendance::LOCATING => 'Pastdagi tugma orqali <b>joylashuvingizni</b> yuboring.',
            Attendance::RECORDING => 'Videoni «🎥 Videoni yozish» tugmasi orqali ochiladigan oynada, kamera bilan yozing.',
            Attendance::REASONING => 'Sababni <b>matn</b> bilan yozing.',
            default => 'Iltimos, tugmalardan foydalaning.',
        };
        Ui::say($bot, "❗ {$hint}");
    }
}
