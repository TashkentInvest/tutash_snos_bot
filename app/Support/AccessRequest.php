<?php

namespace App\Support;

use App\Filament\Resources\StaffResource;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Telegram\Support\Script;
use App\Telegram\Support\Ui;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/**
 * Raqami ruxsat etilgan ro'yxatda topilmagan odam: u botda kutib qolmasin — admin darhol xabar oladi va
 * admin paneldan bir bosishda (rol + tuman) tasdiqlaydi; tasdiqlangach odamning o'ziga botda xabar boradi.
 */
class AccessRequest
{
    /** Tasdiq kutayotganlar: raqami tasdiqlangan (Telegram kontakti), lekin ro'yxatda topilmagan. */
    public static function waiting()
    {
        return Staff::whereIn('status', [Staff::REGISTERING, Staff::REJECTED])->whereNotNull('phone')->whereNotNull('telegram_id');
    }

    /** Adminlarga (DEBUG_ALERT_PHONES) xabar: kim, qaysi raqam, panelga havola. Bir raqam uchun kuniga bir marta. */
    public static function notifyAdmins(Nutgram $bot, Staff $staff): void
    {
        if (! Cache::add('access-request:'.$staff->phone.':'.today()->toDateString(), 1, now()->endOfDay())) {
            return;
        }
        $text = "🆕 <b>Ro‘yxatda yo‘q raqam bilan kirishga urindi</b>\n👤 ".Ui::e((string) $staff->full_name)
            ."\n📞 +998 ".Ui::e((string) $staff->phone)."\n\nTasdiqlash uchun: admin panel → Xodimlar → «Tasdiqlash» (rol va tumanni tanlang).";
        foreach (ErrorAlert::chatIds() as $chatId) {
            try {
                $bot->sendMessage(Script::apply($text), ['chat_id' => $chatId, 'parse_mode' => ParseMode::HTML,
                    'reply_markup' => Ui::markup([[InlineKeyboardButton::make('🔐 Admin panelda ochish', url: StaffResource::getUrl('index', ['tableFilters' => ['waiting' => ['isActive' => true]]]))]])]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** Admin tasdiqladi: rol va tumanlar beriladi, odamga botda xabar boradi. */
    public static function approve(Staff $staff, string $role, array $districtIds, ?int $userId): void
    {
        StaffInvites::add((string) $staff->phone, $staff->full_name, $role, $districtIds, $userId, $role === Staff::ROLE_CONTRACT);
        $staff->refresh()->update(['failed_attempts' => 0]);
        AuditLog::record('registration.approved_by_admin', $staff->telegram_id, ['by' => $userId, 'role' => $role, 'districts' => $districtIds]);

        try {
            app(Nutgram::class)->sendMessage(Script::apply("✅ <b>Administrator sizni tasdiqladi.</b>\nBotdan foydalanish uchun /start buyrug‘ini yuboring."), [
                'chat_id' => $staff->telegram_id, 'parse_mode' => ParseMode::HTML,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
