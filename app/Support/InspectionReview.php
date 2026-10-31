<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\Site;
use App\Telegram\Support\Script;
use App\Telegram\Support\Ui;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** "Buzilgan" natijasini moderator tasdiqlashi: tasdiqlangunga qadar obyekt holati va hisoblar o'zgarmaydi. */
class InspectionReview
{
    /** $note — ixtiyoriy izoh (masalan, «oxiriga yetkazing»): xodimga yuboriladi va bajarilishi nazorat qilinadi. */
    public static function approve(Inspection $inspection, ?int $userId, ?string $note = null): bool
    {
        $note = trim((string) $note) ?: null;
        $done = DB::transaction(function () use ($inspection, $userId, $note) {
            $in = Inspection::with('site')->lockForUpdate()->find($inspection->id);
            if (! $in || $in->review_status !== Inspection::REVIEW_PENDING) {
                return null;
            }

            $in->update(['review_status' => Inspection::REVIEW_APPROVED, 'reviewed_by' => $userId, 'reviewed_at' => now(),
                'review_note' => $note, 'followup' => $note ? Inspection::FOLLOWUP_OPEN : null, 'followup_at' => null,
                'followup_ack_at' => null, 'followup_reminded_at' => null, 'followup_reminders' => 0]);
            self::closeParent($in);
            // sud qarori bekor qilingan obyekt buzilmaydi va jami sondan chiqariladi
            $in->site->update($in->isCourtCancelled()
                ? ['status' => Site::EXEMPT, 'demolished_at' => null]
                : ['status' => Site::DEMOLISHED, 'demolished_at' => ($in->submitted_at ?? now())->toDateString()]);

            return $in;
        });

        if (! $done) {
            return false;
        }

        AuditLog::record('inspection.approved', null, ['inspection' => $done->id, 'site' => $done->site_id, 'by' => $userId]);
        self::notify($done, ($done->isCourtCancelled() ? '✅ <b>Sud qarori bekor qilingani tasdiqlandi</b> — obyekt hisobdan chiqarildi.' : '✅ <b>Obyekt buzilgani tasdiqlandi.</b>')."\n".'📍 '.Ui::e($done->site->address)
            .($note ? "\n\n💬 Moderator izohi: <i>".Ui::e($note)."</i>\n\nIzoh bilan tanishgach «👌 Tushundim» ni, bajargach «✅ Bajarildi» ni bosib <b>rasm va video</b> yuboring. Bajarilmaguncha har kuni eslatma keladi." : ''),
            $note ? self::followupMarkup($done) : null);

        return true;
    }

    public static function reject(Inspection $inspection, ?int $userId, string $note): bool
    {
        $done = DB::transaction(function () use ($inspection, $userId, $note) {
            $in = Inspection::with('site')->lockForUpdate()->find($inspection->id);
            if (! $in || $in->review_status !== Inspection::REVIEW_PENDING) {
                return null;
            }

            // Rad etilgan natija arxivlanadi (fayllar saqlanadi): obyekt sahifasi bo'shaydi va xodim qayta yubora oladi.
            $in->update(['review_status' => Inspection::REVIEW_REJECTED, 'reviewed_by' => $userId, 'reviewed_at' => now(), 'review_note' => $note, 'archived_at' => now(),
                'followup' => Inspection::FOLLOWUP_OPEN, 'followup_at' => null, 'followup_ack_at' => null, 'followup_reminded_at' => null, 'followup_reminders' => 0]);
            self::closeParent($in);
            $in->site->update(['last_inspected_at' => Inspection::where('site_id', $in->site_id)->where('status', Inspection::SUBMITTED)->whereNull('archived_at')->max('submitted_at')]);

            return $in;
        });

        if (! $done) {
            return false;
        }

        AuditLog::record('inspection.rejected', null, ['inspection' => $done->id, 'site' => $done->site_id, 'by' => $userId]);
        self::notify($done, ($done->isCourtCancelled() ? '❌ <b>Sud qarori bekor qilingani tasdiqlanmadi.</b>' : '❌ <b>Obyekt buzilgani tasdiqlanmadi.</b>')."\n".'📍 '.Ui::e($done->site->address)."\n".'Sabab: <i>'.Ui::e($note).'</i>'."\n\n«✏️ Tuzatib qayta yuborish» tugmasini bosing: avvalgi rasm va videolar saqlanadi, faqat keraklisini qayta olasiz.",
            InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(Ui::t('✏️ Tuzatib qayta yuborish'), callback_data: "rw:{$done->id}")));

        return true;
    }

    /** Izoh bajarilganini moderator ko'rib chiqdi: yopadi. */
    public static function closeFollowup(Inspection $in, ?int $userId): bool
    {
        if (! in_array($in->followup, [Inspection::FOLLOWUP_OPEN, Inspection::FOLLOWUP_DONE], true)) {
            return false;
        }
        $in->update(['followup' => Inspection::FOLLOWUP_CLOSED, 'followup_at' => now()]);
        AuditLog::record('inspection.followup_closed', null, ['inspection' => $in->id, 'by' => $userId]);

        return true;
    }

    /** Izoh bajarilmagan deb topildi: yangi izoh bilan qayta ochiladi va xodimga yuboriladi. */
    public static function reopenFollowup(Inspection $in, ?int $userId, string $note): bool
    {
        if ($in->review_status !== Inspection::REVIEW_APPROVED || $in->followup !== Inspection::FOLLOWUP_DONE) {
            return false;
        }
        $in->update(['followup' => Inspection::FOLLOWUP_OPEN, 'followup_at' => null, 'review_note' => $note, 'followup_ack_at' => null]);
        AuditLog::record('inspection.followup_reopened', null, ['inspection' => $in->id, 'by' => $userId]);
        self::notify($in, "🔁 <b>Izoh hali to‘liq bajarilmagan.</b>\n📍 ".Ui::e($in->site->address)."\n\n💬 Moderator izohi: <i>".Ui::e($note)."</i>\n\nBajargach, pastdagi tugma orqali rasm va video yuboring.", self::followupMarkup($in));

        return true;
    }

    /** Izoh bilan tanishdi / bajarildi tugmalari (FollowupReminder). */
    public static function followupMarkup(Inspection $in): InlineKeyboardMarkup
    {
        return \App\Support\FollowupReminder::noteMarkup($in);
    }

    /** Tuzatib yuborilgan natija ko'rib chiqildi — avvalgi (rad etilgan) natija izohi yopiladi. */
    private static function closeParent(Inspection $in): void
    {
        $in->rework_of && Inspection::whereKey($in->rework_of)->whereIn('followup', [Inspection::FOLLOWUP_OPEN, Inspection::FOLLOWUP_DONE])
            ->update(['followup' => Inspection::FOLLOWUP_CLOSED, 'followup_at' => now()]);
    }

    /** Tekshiruvni yuborgan xodimga natija haqida xabar beradi (yetkazilmasa, jarayon to'xtamaydi). */
    private static function notify(Inspection $in, string $html, ?InlineKeyboardMarkup $markup = null): void
    {
        try {
            $staff = $in->staff;
            if ($staff?->telegram_id) {
                app(Nutgram::class)->sendMessage(Script::apply($html), array_filter([
                    'chat_id' => $staff->telegram_id, 'parse_mode' => ParseMode::HTML, 'reply_markup' => $markup,
                ]));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
