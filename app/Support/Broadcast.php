<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Staff;
use App\Telegram\Support\Script;
use App\Telegram\Support\Ui;
use Illuminate\Database\Eloquent\Builder;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use Throwable;

/** Admin paneldan xodimlarga botda xabar yuborish (barchaga yoki tanlangan tumanlar xodimlariga). */
class Broadcast
{
    /** Xabar oluvchilar: faol xodimlar; tuman tanlansa — faqat shu tumanlarga biriktirilganlar. */
    public static function recipients(array $districtIds = []): Builder
    {
        return Staff::where('status', Staff::ACTIVE)->where('role', Staff::ROLE_EMPLOYEE)->whereNotNull('telegram_id')
            ->when($districtIds, fn ($q) => $q->whereHas('districts', fn ($q) => $q->whereIn('districts.id', $districtIds)));
    }

    /** @return array{sent: int, failed: int} */
    public static function send(string $text, array $districtIds, ?int $userId): array
    {
        $bot = app(Nutgram::class);
        $html = Script::apply("📢 <b>Xabarnoma</b>\n\n".Ui::e(trim($text)));
        $sent = $failed = 0;

        foreach (self::recipients($districtIds)->get() as $staff) {
            try {
                $bot->sendMessage($html, ['chat_id' => $staff->telegram_id, 'parse_mode' => ParseMode::HTML, 'disable_web_page_preview' => true]);
                $sent++;
            } catch (Throwable) {
                $failed++; // xodim botni bloklagan bo'lishi mumkin
            }
            usleep(40_000); // Telegram cheklovi: ~30 xabar/soniya
        }

        AuditLog::record('staff.broadcast', null, ['by' => $userId, 'districts' => $districtIds, 'sent' => $sent, 'failed' => $failed]);

        return ['sent' => $sent, 'failed' => $failed];
    }
}
