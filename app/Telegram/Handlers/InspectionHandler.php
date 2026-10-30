<?php

namespace App\Telegram\Handlers;

use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\InspectionMedia;
use App\Models\Site;
use App\Models\Staff;
use App\Telegram\Support\Ui;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;

/**
 * Tekshiruv jarayoni — holat (step) bazada saqlanadi, shuning uchun bot qayta ishga tushsa ham,
 * tugma ikki marta bosilsa ham jarayon buzilmaydi. Bot faqat qoralamani ochadi; joylashuv, rasm, video, natija va sabab
 * Telegram Mini App ichida (InspectionAppController) olinadi — chatga yuborilgan media qabul qilinmaydi.
 *
 * location -> before_photo -> before_video(ixtiyoriy) -> demolished?
 *   yo'q: reason -> confirm
 *   ha:   after_photo -> after_video(majburiy, dumaloq) -> confirm
 */
class InspectionHandler
{
    public static function draft(Staff $staff): ?Inspection
    {
        return Inspection::with(['site.mahalla', 'media'])->where('staff_id', $staff->id)->where('status', Inspection::DRAFT)->first();
    }

    public function begin(Nutgram $bot, string $siteId): void
    {
        if (! $staff = BrowseHandler::guardEmployee($bot)) {
            return;
        }
        $bot->answerCallbackQuery();

        $site = Site::find((int) $siteId);
        if (! $site || ! $staff->canAccessDistrict($site->district_id) || $site->isDemolished()) {
            return;
        }

        if (Inspection::where('site_id', $site->id)->where('status', Inspection::SUBMITTED)->where('review_status', Inspection::REVIEW_PENDING)->exists()) {
            $bot->answerCallbackQuery(['text' => Ui::t('Bu obyekt bo‘yicha “buzilgan” natijasi moderator tasdig‘ini kutmoqda.'), 'show_alert' => true]);

            return;
        }

        $draft = self::draft($staff);
        if ($draft && $draft->site_id !== $site->id) {
            $draft->delete(); // eski tugallanmagan qoralama bekor qilinadi
            $draft = null;
        }

        if (! $draft) {
            try {
                $draft = Inspection::create(['site_id' => $site->id, 'staff_id' => $staff->id, 'step' => 'location']);
            } catch (UniqueConstraintViolationException) {
                return; // parallel urinish
            }
            $draft->load(['site.mahalla', 'media']);
        }

        $this->prompt($bot, $draft);
    }

    /** Mini App'ni ochuvchi tugma (qoralama bo'yicha joriy bosqichdan davom etadi). */
    public static function webAppMarkup(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                Ui::t('🏗 Tekshiruvni boshlash / davom ettirish'),
                web_app: new WebAppInfo(rtrim((string) config('app.url'), '/').'/tg/inspection'),
            ))
            ->addRow(Ui::btn('❌ Bekor qilish', 'cancel'));
    }

    /** Tekshiruv oynasini ochish uchun ko'rsatma yuboradi. */
    public function prompt(Nutgram $bot, Inspection $in): void
    {
        $addr = '<b>'.Ui::e(Ui::cut($in->site->address, 60)).'</b>';

        Ui::say($bot, "{$addr}\n\nTekshiruv pastdagi tugma orqali ochiladigan oynada o‘tkaziladi: joylashuv → rasm → video → natija.\n<i>Joylashuv, rasm va video faqat shu oynada, telefon GPS’i va jonli kamerasidan olinadi; chatga yuborilgan fayllar qabul qilinmaydi.</i>", self::webAppMarkup());
    }

    private function advance(Inspection $in, string $step): void
    {
        $in->update(['step' => $step]);
    }

    // ---------------------------------------------------------------- callbacks

    public function cancel(Nutgram $bot): void
    {
        if (! $staff = BrowseHandler::guardEmployee($bot)) {
            return;
        }
        $bot->answerCallbackQuery(['text' => Ui::t('Bekor qilindi')]);

        if ($draft = self::draft($staff)) {
            $draft->delete();
        }
        Ui::say($bot, 'Tekshiruv bekor qilindi.', Ui::removeKeyboard());
        (new BrowseHandler)->home($bot);
    }

    /** Eski tugmalar (skip / dm / ok) — endi barcha bosqichlar Mini App ichida. */
    public function legacy(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();
        if (($staff = BrowseHandler::guardEmployee($bot)) && ($in = self::draft($staff))) {
            $this->prompt($bot, $in);
        }
    }

    /** Tekshiruvni yakunlaydi (Mini App'dan chaqiriladi): qoralama -> yuborilgan, obyekt holati yangilanadi. */
    public static function finalize(Staff $staff): ?Inspection
    {
        return DB::transaction(function () use ($staff) {
            $in = Inspection::with(['site', 'media'])->where('staff_id', $staff->id)->where('status', Inspection::DRAFT)->lockForUpdate()->first();
            if (! $in || $in->step !== 'confirm' || ! (new self)->complete($in)) {
                return null;
            }

            // "Buzilgan" natijasi moderator tasdiqlamaguncha obyekt holatini o'zgartirmaydi (InspectionReview::approve).
            $in->update([
                'status' => Inspection::SUBMITTED, 'step' => 'done', 'submitted_at' => now(),
                'review_status' => $in->needsReview() ? Inspection::REVIEW_PENDING : null,
            ]);
            $site = $in->site;
            $site->last_inspected_at = now();
            if (! $site->isDemolished() && ! $site->isExempt() && ! $in->needsReview()) {
                $site->status = $in->in_progress ? Site::IN_PROGRESS : Site::NOT_DEMOLISHED;
            }
            $site->save();

            return $in;
        });
    }

    public function complete(Inspection $in): bool
    {
        if (! $in->mediaOf(InspectionMedia::BEFORE_PHOTO)) {
            return false;
        }

        if ($in->is_demolished === true) {
            return $in->mediaOf(InspectionMedia::AFTER_PHOTO) && $in->mediaOf(InspectionMedia::AFTER_VIDEO);
        }

        return $in->is_demolished === false && mb_strlen((string) $in->reason) >= 10
            && (! $in->isCourtCancelled() || $in->documents()->isNotEmpty());
    }

    // ---------------------------------------------------------------- xabarlar (chatga yuborilgan narsalar qabul qilinmaydi)

    public function location(Nutgram $bot, Staff $staff, Inspection $in): void
    {
        $this->wrongInput($bot, $in);
    }

    public function photo(Nutgram $bot, Staff $staff, Inspection $in): void
    {
        $this->wrongInput($bot, $in);
    }

    public function videoNote(Nutgram $bot, Staff $staff, Inspection $in): void
    {
        $this->wrongInput($bot, $in);
    }

    public function reason(Nutgram $bot, Staff $staff, Inspection $in): void
    {
        $this->wrongInput($bot, $in);
    }

    /** Joylashuv, rasm, video va sabab faqat Mini App ichida qabul qilinadi. */
    public function wrongInput(Nutgram $bot, Inspection $in): void
    {
        AuditLog::record('inspection.input_in_chat', $bot->userId(), ['inspection' => $in->id, 'step' => $in->step]);
        Ui::say($bot, '❗ Joylashuv, rasm, video va izohlar chatga yuborilgan holda qabul qilinmaydi. Pastdagi tugma orqali oynani ochib, tekshiruvni shu oynada davom ettiring.', self::webAppMarkup());
    }
}
