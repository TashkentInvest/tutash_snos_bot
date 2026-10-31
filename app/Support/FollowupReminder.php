<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\Staff;
use App\Telegram\Support\Script;
use App\Telegram\Support\Ui;
use Illuminate\Database\Eloquent\Builder;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/**
 * Moderator izohi bajarilmaguncha xodimga eslatma: "Obyekt bo'yicha izoh — bajardingizmi?"
 * Har ish kuni ertalab avtomatik (followups:remind) va admin paneldan qo'lda ("Eslatish").
 */
class FollowupReminder
{
    /** Eslatiladigan izohlar: ochiq (bajarilmagan), xodimi faol. */
    public static function open(): Builder
    {
        return Inspection::with(['site', 'staff'])->where('status', Inspection::SUBMITTED)->where('followup', Inspection::FOLLOWUP_OPEN)
            ->whereIn('review_status', [Inspection::REVIEW_APPROVED, Inspection::REVIEW_REJECTED])
            ->whereHas('staff', fn ($q) => $q->where('status', Staff::ACTIVE)->whereNotNull('telegram_id'));
    }

    /** Tasdiqlangan natijaga izoh: tanishdi / bajarildi tugmalari. */
    public static function noteMarkup(Inspection $in): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(Ui::t('👌 Tushundim'), callback_data: "fa:{$in->id}"))
            ->addRow(InlineKeyboardButton::make(Ui::t('✅ Bajarildi — rasm va video yuborish'), callback_data: "fu:{$in->id}"));
    }

    /** Eslatma tugmalari: rad etilgan — tuzatish; tasdiqlangan — bajarildi / hali yo'q. */
    public static function reminderMarkup(Inspection $in): InlineKeyboardMarkup
    {
        if ($in->review_status === Inspection::REVIEW_REJECTED) {
            return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(Ui::t('✏️ Tuzatib qayta yuborish'), callback_data: "rw:{$in->id}"));
        }

        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(Ui::t('✅ Ha, bajarildi — rasm va video'), callback_data: "fu:{$in->id}"))
            ->addRow(InlineKeyboardButton::make(Ui::t('⏳ Hali yo‘q'), callback_data: "fn:{$in->id}"));
    }

    public static function text(Inspection $in): string
    {
        $head = $in->review_status === Inspection::REVIEW_REJECTED
            ? '🔔 <b>Eslatma:</b> rad etilgan natija hali tuzatilmagan.'
            : '🔔 <b>Eslatma:</b> moderator izohi bo‘yicha javob kutilmoqda.';

        return $head."\n📍 ".Ui::e($in->site->address)
            ."\n\n💬 Moderator izohi: <i>".Ui::e((string) $in->review_note).'</i>'
            ."\n\n".($in->review_status === Inspection::REVIEW_REJECTED ? 'Tuzatib qayta yuboring.' : '<b>Bajardingizmi?</b>');
    }

    /** Bitta eslatma yuboradi; yuborilgan bo'lsa true. */
    public static function send(Inspection $in, ?int $userId = null): bool
    {
        $in->loadMissing(['site', 'staff']);
        if ($in->followup !== Inspection::FOLLOWUP_OPEN || ! $in->staff?->telegram_id) {
            return false;
        }

        try {
            app(Nutgram::class)->sendMessage(Script::apply(self::text($in)), [
                'chat_id' => $in->staff->telegram_id, 'parse_mode' => ParseMode::HTML, 'reply_markup' => self::reminderMarkup($in),
            ]);
        } catch (Throwable $e) {
            report($e); // xodim botni bloklagan bo'lishi mumkin

            return false;
        }

        $in->forceFill(['followup_reminded_at' => now(), 'followup_reminders' => $in->followup_reminders + 1])->save();
        AuditLog::record('inspection.followup_reminded', null, ['inspection' => $in->id, 'by' => $userId]);

        return true;
    }

    /**
     * Barcha ochiq izohlarga eslatma. $skipToday — bugun eslatilganlar qayta yuborilmaydi (avtomatik eslatmada).
     *
     * @return int yuborilganlar soni
     */
    public static function sendAll(?int $userId = null, bool $skipToday = false): int
    {
        $sent = 0;
        foreach (self::open()->get() as $in) {
            if ($skipToday && $in->followup_reminded_at?->isToday()) {
                continue;
            }
            $sent += (int) self::send($in, $userId);
            usleep(40_000); // Telegram cheklovi
        }

        return $sent;
    }

    /** Holat (admin panel uchun): javob kutilmoqda / tanishdi / bajardi — tekshiring / yopilgan. */
    public static function label(Inspection $in): ?string
    {
        return match ($in->followup) {
            Inspection::FOLLOWUP_OPEN => $in->followup_ack_at ? 'Tanishdi, bajarilmagan' : 'Javob kutilmoqda',
            Inspection::FOLLOWUP_DONE => 'Xodim bajardi — tekshiring',
            Inspection::FOLLOWUP_CLOSED => 'Yopilgan',
            default => null,
        };
    }
}
