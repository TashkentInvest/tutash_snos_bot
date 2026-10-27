<?php

namespace App\Telegram\Handlers;

use App\Models\Attendance;
use App\Models\Inspection;
use App\Models\Staff;
use App\Telegram\Support\Ui;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;

/** Qulaylik buyruqlari: /menu, /yordam, /davomat, /bekor. */
class HelpHandler
{
    /** /menu — bosh sahifaga qaytish. */
    public function menu(Nutgram $bot): void
    {
        $staff = RegistrationHandler::staff($bot);

        $staff->canBrowse() ? (new BrowseHandler)->home($bot) : (new RegistrationHandler)->start($bot);
    }

    public function help(Nutgram $bot): void
    {
        $staff = RegistrationHandler::staff($bot);

        $common = "/menu — bosh sahifa (tuman → MFY → obyekt)\n/yordam — shu qo‘llanma\n/bekor — joriy amalni bekor qilish";

        if ($staff->isActive()) {
            $text = "<b>Xodim uchun qo‘llanma</b>\n\n"
                ."1. /menu orqali tumaningiz, MFY va obyektni tanlang (🔍 qidirish yoki 📍 yaqin obyektlar ham mavjud).\n"
                ."2. «Tekshiruvni boshlash» ni bosing: joylashuv → rasm → «buzildimi?» → (buzilgan bo‘lsa) rasm va dumaloq video.\n"
                ."3. Har ish kuni soat ".config('tutash.attendance.send_at')." da kelgan davomat so‘roviga ".config('tutash.attendance.deadline')." gacha javob bering.\n\n"
                ."/davomat — bugungi davomat holati (so‘rov yo‘qolgan bo‘lsa, qayta chiqaradi)\n{$common}";
        } elseif ($staff->isCitizen()) {
            $text = "<b>Aholi uchun qo‘llanma</b>\n\n"
                ."/menu orqali tumanni tanlang. Tuman ichida MFY va obyektlarni ko‘rishingiz, nom bo‘yicha qidirishingiz mumkin.\n"
                ."Obyekt kartasida huquqbuzar, sud qarori, holat va tuman mas’ul xodimining telefon raqami ko‘rsatiladi.\n\n"
                ."{$common}\n/rol — kirish turini o‘zgartirish";
        } else {
            $text = "<b>Qo‘llanma</b>\n\n/start — tizimga kirish («Xodim» yoki «Aholi» sifatida)\n/yordam — shu qo‘llanma";
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

        if (AttendanceHandler::expired()) {
            $row->update(['status' => Attendance::MISSED]);
            Ui::say($bot, '❌ Davomatni qabul qilish muddati ('.config('tutash.attendance.deadline').') tugagan.');

            return;
        }

        // Hali javob berilmagan — so'rovni qayta ko'rsatamiz (jarayon boshidan boshlanadi).
        // so'rov hali yetkazilmagan bo'lsa (qayta urinish kutilayotgan) — endi yetkazildi, scheduler takror yubormaydi
        $row->update(['status' => Attendance::SENT] + ($row->sent_at ? [] : ['sent_at' => now()]));
        Ui::say($bot, AttendanceHandler::promptText($staff), AttendanceHandler::promptMarkup());
    }

    /** /bekor — tugallanmagan tekshiruv qoralamasi va kutilayotgan amallarni bekor qiladi. */
    public function cancel(Nutgram $bot): void
    {
        $staff = RegistrationHandler::staff($bot);

        Cache::forget("tg-await:{$bot->userId()}");
        if ($staff->isActive()) {
            Inspection::where('staff_id', $staff->id)->where('status', Inspection::DRAFT)->delete();
        }

        Ui::say($bot, 'Joriy amal bekor qilindi.', Ui::removeKeyboard());
        if ($staff->canBrowse()) {
            (new BrowseHandler)->home($bot);
        }
    }
}
