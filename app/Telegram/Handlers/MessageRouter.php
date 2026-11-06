<?php

namespace App\Telegram\Handlers;

use App\Models\Staff;
use App\Telegram\Support\Ui;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;

/**
 * Foydalanuvchi holatiga qarab matn/media/lokatsiya xabarlarini kerakli handlerga yo'naltiradi.
 * Ustuvorlik: davomat (vaqt cheklangan) -> tekshiruv qoralamasi -> qidiruv/yaqin obyektlar.
 */
class MessageRouter
{
    private const START_HINT = 'Boshlash uchun /start buyrug‘ini yuboring.';

    public function text(Nutgram $bot): void
    {
        if (str_starts_with((string) $bot->message()->text, '/')) {
            return; // buyruqlar alohida handlerda
        }

        $staff = RegistrationHandler::staff($bot);

        if ($staff->status === Staff::REGISTERING) {
            $staff->role === null
                ? (new RegistrationHandler)->askRole($bot)
                : (new RegistrationHandler)->name($bot, $staff);

            return;
        }
        // telefon bosqichi: raqamni qo'lda yozgan yoki tugmani yashirib qo'ygan — qayta ro'yxatdan o'tkazmaymiz, tugmani qaytaramiz
        if ($staff->status === Staff::AWAITING_PHONE) {
            (new RegistrationHandler)->askPhone($bot);

            return;
        }
        // davomat (sabab matni) — har qanday tasdiqlangan xodimda, shartnoma xodimida ham
        if ($staff->isActive() && ($attendance = AttendanceHandler::open($staff))) {
            $handler = new AttendanceHandler;
            $attendance->status === \App\Models\Attendance::REASONING
                ? $handler->reason($bot, $staff, $attendance)
                : $handler->wrongInput($bot, $attendance);

            return;
        }

        if ($staff->canBrowse()) {
            // 🔍 qidiruv bosilgan bo'lsa — matn qidiruv; eski tugallanmagan tekshiruv uni to'sib qo'ymasin
            $await = Cache::get("tg-await:{$bot->userId()}");
            if (($await['type'] ?? null) === 'search') {
                (new BrowseHandler)->search($bot, $staff, $await);

                return;
            }

            if ($staff->isActive() && ($draft = InspectionHandler::draft($staff))) {
                (new InspectionHandler)->reason($bot, $staff, $draft);

                return;
            }
        }

        // ro'yxatdan o'tgan foydalanuvchiga "/start yuboring" demaymiz — bosh sahifasini ko'rsatamiz
        if ($staff->isActive() || $staff->isCitizen()) {
            (new BrowseHandler)->home($bot);

            return;
        }

        Ui::say($bot, self::START_HINT);
    }

    public function photo(Nutgram $bot): void
    {
        $this->media($bot, 'photo');
    }

    public function videoNote(Nutgram $bot): void
    {
        $this->media($bot, 'videoNote');
    }

    /** Oddiy video, hujjat, ovoz va h.k. — qabul qilinmaydi. */
    public function other(Nutgram $bot): void
    {
        $staff = Staff::where('telegram_id', $bot->userId())->first();
        if (! $staff?->isActive()) {
            return;
        }

        if ($attendance = AttendanceHandler::open($staff)) {
            (new AttendanceHandler)->wrongInput($bot, $attendance);
        } elseif ($draft = InspectionHandler::draft($staff)) {
            (new InspectionHandler)->wrongInput($bot, $draft);
        }
    }

    public function location(Nutgram $bot): void
    {
        $staff = Staff::where('telegram_id', $bot->userId())->first();
        if (! $staff?->isActive()) {
            return;
        }

        if ($attendance = AttendanceHandler::open($staff)) {
            (new AttendanceHandler)->location($bot, $staff, $attendance);

            return;
        }

        if ($draft = InspectionHandler::draft($staff)) {
            (new InspectionHandler)->location($bot, $staff, $draft);

            return;
        }

        $await = Cache::get("tg-await:{$bot->userId()}");
        if (($await['type'] ?? null) === 'near') {
            (new BrowseHandler)->near($bot, $staff, $await);
        }
    }

    private function media(Nutgram $bot, string $method): void
    {
        $staff = Staff::where('telegram_id', $bot->userId())->first();
        if (! $staff?->isActive()) {
            return;
        }

        if ($attendance = AttendanceHandler::open($staff)) {
            $handler = new AttendanceHandler;
            $method === 'videoNote'
                ? $handler->videoNote($bot, $staff, $attendance)
                : $handler->wrongInput($bot, $attendance);

            return;
        }

        if ($draft = InspectionHandler::draft($staff)) {
            (new InspectionHandler)->{$method}($bot, $staff, $draft);
        }
    }
}
