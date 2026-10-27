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
use Throwable;

/** "Buzilgan" natijasini moderator tasdiqlashi: tasdiqlangunga qadar obyekt holati va hisoblar o'zgarmaydi. */
class InspectionReview
{
    public static function approve(Inspection $inspection, ?int $userId): bool
    {
        $done = DB::transaction(function () use ($inspection, $userId) {
            $in = Inspection::with('site')->lockForUpdate()->find($inspection->id);
            if (! $in || $in->review_status !== Inspection::REVIEW_PENDING) {
                return null;
            }

            $in->update(['review_status' => Inspection::REVIEW_APPROVED, 'reviewed_by' => $userId, 'reviewed_at' => now()]);
            $in->site->update(['status' => Site::DEMOLISHED, 'demolished_at' => ($in->submitted_at ?? now())->toDateString()]);

            return $in;
        });

        if (! $done) {
            return false;
        }

        AuditLog::record('inspection.approved', null, ['inspection' => $done->id, 'site' => $done->site_id, 'by' => $userId]);
        self::notify($done, '✅ <b>Obyekt buzilgani tasdiqlandi.</b>'."\n".'📍 '.Ui::e($done->site->address));

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
            $in->update(['review_status' => Inspection::REVIEW_REJECTED, 'reviewed_by' => $userId, 'reviewed_at' => now(), 'review_note' => $note, 'archived_at' => now()]);
            $in->site->update(['last_inspected_at' => Inspection::where('site_id', $in->site_id)->where('status', Inspection::SUBMITTED)->whereNull('archived_at')->max('submitted_at')]);

            return $in;
        });

        if (! $done) {
            return false;
        }

        AuditLog::record('inspection.rejected', null, ['inspection' => $done->id, 'site' => $done->site_id, 'by' => $userId]);
        self::notify($done, '❌ <b>Obyekt buzilgani tasdiqlanmadi.</b>'."\n".'📍 '.Ui::e($done->site->address)."\n".'Sabab: <i>'.Ui::e($note).'</i>'."\n\nObyekt bo‘yicha tekshiruvni qayta o‘tkazishingiz mumkin.");

        return true;
    }

    /** Tekshiruvni yuborgan xodimga natija haqida xabar beradi (yetkazilmasa, jarayon to'xtamaydi). */
    private static function notify(Inspection $in, string $html): void
    {
        try {
            $staff = $in->staff;
            if ($staff?->telegram_id) {
                app(Nutgram::class)->sendMessage(Script::apply($html), ['chat_id' => $staff->telegram_id, 'parse_mode' => ParseMode::HTML]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
