<?php

namespace App\Telegram\Handlers;

use App\Models\AuditLog;
use App\Models\District;
use App\Models\Staff;
use App\Telegram\Support\Ui;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

class RegistrationHandler
{
    public static function staff(Nutgram $bot): Staff
    {
        $staff = Staff::firstOrCreate(
            ['telegram_id' => $bot->userId()],
            ['status' => Staff::REGISTERING],
        );
        $staff->forceFill(['username' => $bot->user()?->username, 'last_seen_at' => now()])->save();

        return $staff;
    }

    public function start(Nutgram $bot): void
    {
        $staff = self::staff($bot);

        if ($staff->status === Staff::BLOCKED) {
            Ui::say($bot, "⛔ Sizga tizimdan foydalanish huquqi berilmagan.\nSavollar yuzasidan administrator bilan bog‘laning.");

            return;
        }

        // Tasdiqlangan xodim to'g'ridan-to'g'ri ishga kiradi; qolganlarga har safar kirish turi taklif etiladi
        // (noto'g'ri tanlov yoki ruxsat etilmagan raqam holatida administrator panelda tuzatishi mumkin).
        if (! $staff->isActive()) {
            $this->askRole($bot);

            return;
        }
        $staff->isCitizen() || self::permissionsPrompt($bot);
        (new BrowseHandler)->home($bot);
    }

    /**
     * Joylashuv, kamera va mikrofon ruxsatlarini tekshiruvchi Mini App tugmasi (xodimlarga /start da yuboriladi).
     * Telegram joylashuv ruxsatini har bir bot uchun alohida saqlaydi — bir marta rad etilsa, shu yerdan qayta yoqiladi.
     */
    public static function permissionsPrompt(Nutgram $bot): void
    {
        Ui::say($bot, "🔐 Tekshiruv va davomat uchun botga <b>joylashuv, kamera va mikrofon</b> ruxsati kerak.\nTugmani bosib, ruxsatlarni tekshiring.",
            InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
                Ui::t('🔐 Ruxsatlarni tekshirish'),
                web_app: new WebAppInfo(rtrim((string) config('app.url'), '/').'/tg/permissions'),
            )));
    }

    /** Kirish turini (xodim yoki aholi) tanlash. */
    public function askRole(Nutgram $bot): void
    {
        Ui::show($bot, "👋 Assalomu alaykum!\n\n<b>«Tutash snos»</b> axborot tizimiga xush kelibsiz.\nIltimos, tizimga kirish turini tanlang:", Ui::markup([
            [Ui::btn('👷 Xodim sifatida', 'role:employee')],
            [Ui::btn('👤 Aholi sifatida', 'role:citizen')],
        ]));
    }

    public function chooseRole(Nutgram $bot, string $role): void
    {
        $staff = self::staff($bot);
        $bot->answerCallbackQuery();

        // Tasdiqlangan xodim va bloklangan foydalanuvchi rolini bot orqali o'zgartira olmaydi.
        if (in_array($staff->status, [Staff::ACTIVE, Staff::BLOCKED], true) || ! in_array($role, [Staff::ROLE_EMPLOYEE, Staff::ROLE_CITIZEN], true)) {
            return;
        }

        if ($role === Staff::ROLE_CITIZEN) {
            $name = trim(($bot->user()?->first_name ?? '').' '.($bot->user()?->last_name ?? ''));
            $staff->update(['role' => Staff::ROLE_CITIZEN, 'status' => Staff::CITIZEN, 'full_name' => $staff->full_name ?: ($name ?: null)]);
            (new BrowseHandler)->home($bot);

            return;
        }

        // Telefon tekshiruvidan ko'p marta o'ta olmaganlar uchun xodim sifatida kirish yopiq (administrator tiklaydi).
        if ($staff->failed_attempts >= config('tutash.max_registration_attempts')) {
            $staff->update(['role' => Staff::ROLE_EMPLOYEE, 'status' => Staff::REJECTED]);
            Ui::say($bot, '⛔ Telefon raqamingiz ruxsat etilgan ro‘yxatda topilmadi va urinishlar soni tugadi. Iltimos, administrator bilan bog‘laning yoki /start orqali «Aholi» sifatida kiring.');

            return;
        }

        $staff->update(['role' => Staff::ROLE_EMPLOYEE, 'status' => Staff::REGISTERING]);
        $this->askName($bot, $staff);
    }

    /** /rol — kirish turini qayta tanlash (/start bilan bir xil). */
    public function resetRole(Nutgram $bot): void
    {
        $this->start($bot);
    }

    private function askName(Nutgram $bot, Staff $staff): void
    {
        $staff->update(['status' => Staff::REGISTERING]);
        Ui::say($bot, "<b>Xodim sifatida ro‘yxatdan o‘tish</b>\n\nIltimos, familiya, ism va otangizning ismini to‘liq yozing (masalan: <i>Aliyev Ali Aliyevich</i>):", Ui::removeKeyboard());
    }

    private function askPhone(Nutgram $bot): void
    {
        $kb = ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
            ->addRow(KeyboardButton::make(Ui::t('📱 Telefon raqamni yuborish'), request_contact: true));

        Ui::say($bot, "Shaxsingizni tasdiqlash uchun pastdagi tugma orqali <b>o‘zingizning telefon raqamingizni</b> yuboring.\n<i>Boshqa shaxsning kontaktini yuborish mumkin emas.</i>", $kb);
    }

    /** Ro'yxatdan o'tish bosqichida matn (F.I.SH). */
    public function name(Nutgram $bot, Staff $staff): void
    {
        $name = Ui::cut($bot->message()->text, 100);

        if (mb_strlen($name) < 8 || count(explode(' ', $name)) < 2 || preg_match('/[\d<>@#\/\\\\]/u', $name)) {
            Ui::say($bot, '❗ Ma’lumot noto‘g‘ri kiritildi. Familiya, ism va otangizning ismini to‘liq yozing.');

            return;
        }

        $staff->update(['full_name' => $name, 'status' => Staff::AWAITING_PHONE]);
        $this->askPhone($bot);
    }

    public function contact(Nutgram $bot): void
    {
        $staff = self::staff($bot);
        $message = $bot->message();

        if ($staff->status !== Staff::AWAITING_PHONE) {
            return;
        }

        // Faqat o'z kontakti va forward qilinmagan bo'lishi shart.
        if ($message->contact->user_id !== $bot->userId() || $message->forward_date !== null) {
            AuditLog::record('registration.foreign_contact', $bot->userId());
            Ui::say($bot, '❗ Faqat o‘zingizning telefon raqamingizni, pastdagi tugma orqali yuboring.');

            return;
        }

        $phone = Staff::normalizePhone($message->contact->phone_number);
        $global = Staff::isGlobalPhone($phone);
        $districts = ($global || Staff::isAllDistrictsEmployeePhone($phone))
            ? District::all()
            : ($phone ? District::forPhone($phone)->get() : collect());

        if ($districts->isEmpty()) {
            $staff->increment('failed_attempts');
            $locked = $staff->failed_attempts >= config('tutash.max_registration_attempts');
            $staff->update(['phone' => $phone, 'status' => $locked ? Staff::REJECTED : Staff::REGISTERING]);
            AuditLog::record('registration.phone_not_found', $bot->userId(), ['phone' => $phone, 'name' => $staff->full_name]);

            Ui::say($bot, $locked
                ? '⛔ Telefon raqamingiz ruxsat etilgan ro‘yxatda topilmadi. Urinishlar soni tugadi. Iltimos, administrator bilan bog‘laning.'
                : "⛔ Telefon raqamingiz ruxsat etilgan ro‘yxatda topilmadi.\nAgar bu xato deb hisoblasangiz, tuman mas’ul xodimiga murojaat qilib, qayta /start buyrug‘ini yuboring.", Ui::removeKeyboard());

            return;
        }

        $staff->update([
            'phone' => $phone, 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE, 'registered_at' => now(),
            'failed_attempts' => 0, 'is_global' => $global, 'attendance_required' => ! $global,
        ]);
        $staff->districts()->sync($global ? [] : $districts->pluck('id'));
        AuditLog::record('registration.ok', $bot->userId(), ['districts' => $districts->pluck('name')]);

        Ui::say($bot, "✅ Shaxsingiz muvaffaqiyatli tasdiqlandi.\nSizga biriktirilgan hudud: <b>".Ui::e($districts->count() === District::count() && $districts->count() > 1 ? 'barcha tumanlar' : $districts->pluck('name')->join(', '))."</b>", Ui::removeKeyboard());
        self::permissionsPrompt($bot);
        (new BrowseHandler)->home($bot);
    }
}
