<?php

namespace App\Telegram\Handlers;

use App\Models\Attendance;
use App\Models\Inspection;
use App\Models\Staff;
use App\Telegram\Support\Ui;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;

/** Menyudagi buyruqlar: /start, /davomat, /yordam. /menu va /bekor eski odat uchun yashirin ishlaydi. */
class HelpHandler
{
    /** /menu — bosh sahifaga qaytish. */
    public function menu(Nutgram $bot): void
    {
        $staff = RegistrationHandler::staff($bot);

        $staff->isActive() || $staff->isCitizen() ? (new BrowseHandler)->home($bot) : (new RegistrationHandler)->start($bot);
    }

    public function help(Nutgram $bot): void
    {
        $staff = RegistrationHandler::staff($bot);
        $send = config('tutash.attendance.send_at');
        $deadline = config('tutash.attendance.deadline');
        $hourly = 'Ish vaqtida har soatda xabar keladi — tugmani bosing, joylashuv va qisqa video yuboring (keyingi soatgacha). 13:00–14:00 tushlik.';

        if ($staff->isActive() && $staff->isContract()) {
            $text = "<b>Qisqacha</b>\n\n"
                ."• Har kuni ertalab {$send} da davomat so‘raladi — {$deadline} gacha javob bering.\n"
                ."• {$hourly}\n\n"
                .'Xabar yo‘qolsa — /davomat yoki /start.';
        } elseif ($staff->isActive()) {
            $text = "<b>Qisqacha</b>\n\n"
                ."• /start → tuman → MFY → obyekt → «Tekshiruvni boshlash».\n"
                ."• Har safar: joylashuv, rasm, video.\n"
                ."• Har kuni ertalab {$send} da davomat so‘raladi — {$deadline} gacha javob bering.\n"
                .($staff->doesHourlyCheckin() ? "• {$hourly}\n" : '')
                ."\nXabar yo‘qolsa — /davomat yoki /start.";
        } elseif ($staff->isCitizen()) {
            $text = "<b>Qisqacha</b>\n\n/start → tuman → MFY → obyekt. Obyekt kartasida holati va tuman mas’ulining telefoni bor.";
        } else {
            $text = '/start bosing va kirish turini tanlang.';
        }

        Ui::say($bot, $text);
    }

    /** /davomat — bugungi davomat holati. */
    public function attendance(Nutgram $bot): void
    {
        $staff = RegistrationHandler::staff($bot);
        if (! $staff->isActive()) {
            Ui::say($bot, 'Bu buyruq faqat tasdiqlangan xodimlar uchun.');

            return;
        }

        $row = Attendance::where('staff_id', $staff->id)->whereDate('date', today())->first();

        if (! $row) {
            Ui::say($bot, $staff->attendance_required
                ? 'Bugun uchun davomat so‘rovi yuborilmagan. So‘rov ish kunlari soat '.config('tutash.attendance.send_at').' da yuboriladi.'
                : 'Sizda kunlik davomat yuritilmaydi.');

            return;
        }

        $text = match ($row->status) {
            Attendance::PRESENT => '✅ Bugungi davomatingiz qayd etilgan ('.$row->responded_at?->format('H:i').').',
            Attendance::ABSENT => '⚠️ Bugun «ishda emas» deb qayd etilgan. Sabab: <i>'.Ui::e($row->reason).'</i>',
            Attendance::MISSED => '❌ Davomatni qabul qilish muddati ('.config('tutash.attendance.deadline').') tugagan, javob qayd etilmagan.',
            default => null,
        };

        if ($text) {
            Ui::say($bot, $text);

            return;
        }

        if ($row->isLate()) {
            $row->update(['status' => Attendance::MISSED]);
            Ui::say($bot, '❌ Davomatni qabul qilish muddati ('.config('tutash.attendance.deadline').') tugagan.');

            return;
        }

        // boshlangan jarayon (joylashuv yuborilgan yoki sabab yozilmoqda) — boshidan emas, shu joyidan davom etadi
        if (in_array($row->status, [Attendance::LOCATING, Attendance::RECORDING], true)) {
            Ui::say($bot, 'Davomatni yakunlang: tugmani bosing.', AttendanceHandler::webAppMarkup('📍 Davomatni davom ettirish'));

            return;
        }
        if ($row->status === Attendance::REASONING) {
            Ui::say($bot, 'Ishda emasligingiz sababini shu yerga yozib yuboring.');

            return;
        }

        // hali javob berilmagan — so'rov qayta ko'rsatiladi; yetkazilmagan bo'lsa endi yetkazildi (scheduler takror yubormaydi)
        $row->update(['status' => Attendance::SENT] + ($row->sent_at ? [] : ['sent_at' => now()]));
        Ui::say($bot, AttendanceHandler::promptText($staff), AttendanceHandler::promptMarkup());
    }

    /** /bekor — tugallanmagan tekshiruv qoralamasi va kutilayotgan amallarni bekor qiladi. */
    public function cancel(Nutgram $bot): void
    {
        $staff = RegistrationHandler::staff($bot);

        Cache::forget("tg-await:{$bot->userId()}");
        if ($staff->isActive()) {
            // model orqali: fayllar o'chiriladi, tuzatish qoralamasida esa avvalgi fayllar hashi qaytariladi
            Inspection::where('staff_id', $staff->id)->where('status', Inspection::DRAFT)->get()->each->delete();
        }

        Ui::say($bot, 'Joriy amal bekor qilindi.', Ui::removeKeyboard());
        if ($staff->isActive() || $staff->isCitizen()) {
            (new BrowseHandler)->home($bot);
        }
    }
}
