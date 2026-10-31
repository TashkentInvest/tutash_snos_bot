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
 * Tur "Tekshiruvni boshlash" dan keyin tanlanadi (Inspection::KINDS): location -> photo -> video -> [sud qarori: documents] -> confirm
 * "Tekshirish" bosqichlari turli kunlarda: oldingi holat -> buzish jarayoni -> buzilgandan keyingi holat (moderator tasdig'i).
 */
class InspectionHandler
{
    public static function draft(Staff $staff): ?Inspection
    {
        return Inspection::with(['site.mahalla', 'media'])->where('staff_id', $staff->id)->where('status', Inspection::DRAFT)->first();
    }

    /** Tekshiruv boshlanishi mumkin bo'lgan obyekt (yoki null — sababi xodimga ko'rsatiladi). */
    private function startableSite(Nutgram $bot, Staff $staff, string $siteId): ?Site
    {
        $site = Site::find((int) $siteId);
        if (! $site || ! $staff->canAccessDistrict($site->district_id) || $site->isDemolished() || $site->isExempt()) {
            $bot->answerCallbackQuery();

            return null;
        }
        if (Inspection::where('site_id', $site->id)->where('status', Inspection::SUBMITTED)->where('review_status', Inspection::REVIEW_PENDING)->exists()) {
            $bot->answerCallbackQuery(['text' => Ui::t('Bu obyekt bo‘yicha natija moderator tasdig‘ini kutmoqda.'), 'show_alert' => true]);

            return null;
        }
        $bot->answerCallbackQuery();

        return $site;
    }

    /** Obyekt bo'yicha oldingi holat qayd etilganmi (yoki avval har qanday natija yuborilganmi). */
    public static function hasBefore(Site $site): bool
    {
        return Inspection::counted()->where('site_id', $site->id)->exists();
    }

    /** "Tekshiruvni boshlash" ("go:{id}"): uchta yo'l — tekshirish, sud qarori, kelganda buzilgan. */
    public function begin(Nutgram $bot, string $siteId): void
    {
        if (! ($staff = BrowseHandler::guardEmployee($bot)) || ! ($site = $this->startableSite($bot, $staff, $siteId))) {
            return;
        }

        // shu obyekt bo'yicha tugallanmagan tekshiruv bo'lsa — davom ettiriladi
        if (($draft = self::draft($staff)) && $draft->site_id === $site->id) {
            $this->prompt($bot, $draft);

            return;
        }

        Ui::say($bot, '<b>'.Ui::e(Ui::cut($site->address, 60))."</b>\n\nTekshiruv turini tanlang:", Ui::markup([
            [Ui::btn('📋 Tekshirish (oldin → jarayon → keyin)', "gk:{$site->id}:check")],
            [Ui::btn('⚖️ Sud qarori bekor qilingan', "gk:{$site->id}:court")],
            [Ui::btn('🏚 Kelganda buzilgan ekan', "gk:{$site->id}:found")],
            [Ui::btn('⬅️ Orqaga', "s:{$site->id}")],
        ]));
    }

    /**
     * Tur tanlandi ("gk:{id}:{kind}"). "Tekshirish" bosqichlari turli kunlarda: oldingi holat qayd etilmagan bo'lsa — u,
     * aks holda xodim tanlaydi: buzish jarayoni yoki buzib bo'lingan (keyingi holat).
     */
    public function chooseKind(Nutgram $bot, string $siteId, string $kind): void
    {
        if (! ($staff = BrowseHandler::guardEmployee($bot)) || ! ($site = $this->startableSite($bot, $staff, $siteId))) {
            return;
        }

        if ($kind === 'check') {
            if (! self::hasBefore($site)) {
                $kind = Inspection::KIND_BEFORE;
            } else {
                Ui::say($bot, '<b>'.Ui::e(Ui::cut($site->address, 60))."</b>\n\nObyektning oldingi holati qayd etilgan. Qaysi bosqich?", Ui::markup([
                    [Ui::btn('🚧 Buzish jarayoni', "gk:{$site->id}:process")],
                    [Ui::btn('✅ Buzib bo‘lindi (keyingi holat)', "gk:{$site->id}:after")],
                    [Ui::btn('⬅️ Orqaga', "go:{$site->id}")],
                ]));

                return;
            }
        }
        if (! isset(Inspection::KINDS[$kind]) || (in_array($kind, [Inspection::KIND_PROCESS, Inspection::KIND_AFTER], true) && ! self::hasBefore($site))) {
            return;
        }

        $draft = self::draft($staff);
        $draft?->delete(); // eski tugallanmagan qoralama bekor qilinadi
        try {
            $draft = Inspection::create(['site_id' => $site->id, 'staff_id' => $staff->id, 'step' => 'location', ...Inspection::kindAttributes($kind)]);
        } catch (UniqueConstraintViolationException) {
            return; // parallel urinish
        }
        $draft->load(['site.mahalla', 'media']);

        $this->prompt($bot, $draft);
    }

    /**
     * Rad etilgan natijani tuzatish ("rw:{id}"): avvalgi natija, izoh va fayllar saqlanadi,
     * xodim Mini App'da faqat moderator ko'rsatgan qismini (rasm, video, izoh, hujjat) qayta oladi.
     */
    public function rework(Nutgram $bot, string $id): void
    {
        if (! $staff = BrowseHandler::guardEmployee($bot)) {
            return;
        }
        $old = Inspection::with('site')->where('staff_id', $staff->id)->where('status', Inspection::SUBMITTED)
            ->where('review_status', Inspection::REVIEW_REJECTED)->find((int) $id);
        $site = $old?->site;

        $error = match (true) {
            ! $old || ! $staff->canAccessDistrict($site->district_id) => 'Natija topilmadi.',
            $old->followup !== Inspection::FOLLOWUP_OPEN => 'Bu natija allaqachon tuzatib yuborilgan.',
            $site->isDemolished() || $site->isExempt() => 'Obyekt holati allaqachon tasdiqlangan.',
            Inspection::where('site_id', $site->id)->where('status', Inspection::SUBMITTED)->where('review_status', Inspection::REVIEW_PENDING)->exists() => 'Bu obyekt bo‘yicha natija moderator tasdig‘ini kutmoqda.',
            default => null,
        };
        if ($error) {
            $bot->answerCallbackQuery(['text' => Ui::t($error), 'show_alert' => true]);

            return;
        }
        $bot->answerCallbackQuery();

        $draft = self::draft($staff);
        if ($draft && $draft->rework_of !== $old->id) {
            $draft->delete();
            $draft = null;
        }
        try {
            $draft ??= DB::transaction(fn () => Inspection::reworkDraft($old));
        } catch (UniqueConstraintViolationException) {
            return; // parallel urinish
        }

        Ui::say($bot, '<b>'.Ui::e(Ui::cut($site->address, 60)).'</b>'."\n\n💬 Moderator izohi: <i>".Ui::e((string) $old->review_note)."</i>\n\n"
            .'Oynani oching va <b>qaysi qismini qayta olishni</b> tanlang: rasm, video yoki hujjat. Qolgan ma’lumotlar avvalgidek saqlanadi.', self::webAppMarkup());
    }

    /** Tasdiqlangan natija bo'yicha moderator izohi bajarilganini rasm va video bilan tasdiqlash ("fu:{id}"). */
    public function followup(Nutgram $bot, string $id): void
    {
        if (! $staff = BrowseHandler::guardEmployee($bot)) {
            return;
        }
        $parent = Inspection::with('site')->where('staff_id', $staff->id)->where('status', Inspection::SUBMITTED)
            ->where('review_status', Inspection::REVIEW_APPROVED)->find((int) $id);
        if (! $parent || $parent->followup !== Inspection::FOLLOWUP_OPEN) {
            $bot->answerCallbackQuery(['text' => Ui::t($parent ? 'Bu izoh bo‘yicha natija allaqachon yuborilgan.' : 'Natija topilmadi.'), 'show_alert' => true]);

            return;
        }
        $bot->answerCallbackQuery();

        $draft = self::draft($staff);
        if ($draft && $draft->followup_of !== $parent->id) {
            $draft->delete();
            $draft = null;
        }
        try {
            $draft ??= Inspection::create([
                'site_id' => $parent->site_id, 'staff_id' => $staff->id, 'step' => 'location', 'followup_of' => $parent->id,
                'kind' => Inspection::KIND_AFTER, 'is_demolished' => $parent->is_demolished, 'exempt_type' => $parent->exempt_type,
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        Ui::say($bot, '<b>'.Ui::e(Ui::cut($parent->site->address, 60)).'</b>'."\n\n💬 Moderator izohi: <i>".Ui::e((string) $parent->review_note)."</i>\n\n"
            .'Obyekt yoniga borib, oynani oching: joylashuv → bajarilgan ish rasmi → video → qisqa izoh.', self::webAppMarkup());
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
        $kind = $in->isFollowup() ? 'Izoh bo‘yicha bajarilgan ish' : Inspection::KINDS[$in->kindValue()];
        $steps = 'joylashuv → rasm → video'.($in->kindValue() === Inspection::KIND_COURT && ! $in->isFollowup() ? ' → sud qarori hujjatlari' : '');

        Ui::say($bot, "{$addr}\nTekshiruv: <b>".Ui::e($kind)."</b>\n\nPastdagi tugma orqali oynani oching: {$steps}.\n<i>Joylashuv, rasm va video faqat shu oynada, telefon GPS’i va jonli kamerasidan olinadi; chatga yuborilgan fayllar qabul qilinmaydi.</i>", self::webAppMarkup());
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
            // moderator izohi bo'yicha yuborildi — avvalgi natijada «xodim bajardi, tekshiring» holati
            if ($parent = $in->rework_of ?? $in->followup_of) {
                Inspection::whereKey($parent)->where('followup', Inspection::FOLLOWUP_OPEN)
                    ->update(['followup' => Inspection::FOLLOWUP_DONE, 'followup_at' => now()]);
                $in->rework_of && Inspection::releaseMovedMedia($in->rework_of);
            }
            if ($in->isFollowup()) {
                return $in; // obyekt holati allaqachon tasdiqlangan
            }

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
        // har bir turda rasm va video majburiy; sud qarori — hujjat ham; izoh bo'yicha tasdiq — izoh ham
        [$photo, $video] = $in->mediaKinds();
        if (! $in->mediaOf($photo) || ! $in->mediaOf($video)) {
            return false;
        }
        if ($in->isFollowup()) {
            return mb_strlen((string) $in->reason) >= 10;
        }

        return $in->kindValue() !== Inspection::KIND_COURT || $in->documents()->isNotEmpty();
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
