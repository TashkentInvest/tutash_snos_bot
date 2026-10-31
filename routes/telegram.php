<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Telegram\Handlers\AttendanceHandler;
use App\Telegram\Handlers\BrowseHandler;
use App\Telegram\Handlers\HelpHandler;
use App\Telegram\Handlers\InspectionHandler;
use App\Telegram\Handlers\MessageRouter;
use App\Telegram\Handlers\RegistrationHandler;
use App\Telegram\Middleware\GuardUpdate;
use App\Telegram\Support\Ui;
use SergiX44\Nutgram\Nutgram;

$bot->middleware(GuardUpdate::class);

$bot->onCommand('start', [RegistrationHandler::class, 'start'])->description('Boshlash');
$bot->onCommand('menu', [HelpHandler::class, 'menu'])->description('Bosh sahifa');
$bot->onCommand('davomat', [HelpHandler::class, 'attendance'])->description('Bugungi davomat (xodimlar uchun)');
$bot->onCommand('bekor', [HelpHandler::class, 'cancel'])->description('Joriy amalni bekor qilish');
$bot->onCommand('yordam', [HelpHandler::class, 'help'])->description('Foydalanish qo‘llanmasi');
$bot->onCommand('rol', [RegistrationHandler::class, 'resetRole'])->description('Kirish turini o‘zgartirish');

// Kirish turi (xodim / aholi) va ro'yxatdan o'tish
$bot->onCallbackQueryData('role:{role}', [RegistrationHandler::class, 'chooseRole']);
$bot->onContact([RegistrationHandler::class, 'contact']);

// Navigatsiya
$bot->onCallbackQueryData('home', [BrowseHandler::class, 'home']);
$bot->onCallbackQueryData('d:{id}', [BrowseHandler::class, 'district']);
$bot->onCallbackQueryData('dp:{id}:{page}', [BrowseHandler::class, 'district']);
$bot->onCallbackQueryData('m:{id}:{page}:{all}', [BrowseHandler::class, 'mahalla']);
$bot->onCallbackQueryData('s:{id}', [BrowseHandler::class, 'site']);
$bot->onCallbackQueryData('q:{id}', [BrowseHandler::class, 'askSearch']);
$bot->onCallbackQueryData('near:{id}', [BrowseHandler::class, 'askNear']);
$bot->onCallbackQueryData('noop', fn (Nutgram $bot) => $bot->answerCallbackQuery());

// Tekshiruv
$bot->onCallbackQueryData('go:{id}', [InspectionHandler::class, 'begin']);
$bot->onCallbackQueryData('gk:{id}:{kind}', [InspectionHandler::class, 'chooseKind']);
$bot->onCallbackQueryData('rw:{id}', [InspectionHandler::class, 'rework']);
$bot->onCallbackQueryData('fu:{id}', [InspectionHandler::class, 'followup']);
$bot->onCallbackQueryData('dm:{value}', [InspectionHandler::class, 'legacy']);
$bot->onCallbackQueryData('skip', [InspectionHandler::class, 'legacy']);
$bot->onCallbackQueryData('ok', [InspectionHandler::class, 'legacy']);
$bot->onCallbackQueryData('cancel', [InspectionHandler::class, 'cancel']);

// Davomat
$bot->onCallbackQueryData('at:{value}', [AttendanceHandler::class, 'answer']);

// Xabarlar
$bot->onPhoto([MessageRouter::class, 'photo']);
$bot->onVideoNote([MessageRouter::class, 'videoNote']);
$bot->onLocation([MessageRouter::class, 'location']);
$bot->onVideo([MessageRouter::class, 'other']);
$bot->onDocument([MessageRouter::class, 'other']);
$bot->onText('{text}', [MessageRouter::class, 'text']);

$bot->onException(function (Nutgram $bot, Throwable $e) {
    report($e);
    try {
        $bot->callbackQuery() && $bot->answerCallbackQuery(['text' => Ui::t('Xatolik yuz berdi. Iltimos, qayta urinib ko‘ring.')]);
        $bot->sendMessage(Ui::t('⚠️ Vaqtincha texnik xatolik yuz berdi. Iltimos, /start buyrug‘i orqali qayta urinib ko‘ring.'));
    } catch (Throwable) {
    }
});
