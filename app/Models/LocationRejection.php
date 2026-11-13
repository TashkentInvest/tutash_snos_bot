<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tekshiruvda qabul qilinmagan joylashuv: xodim nuqtasi va obyekt nuqtasi (admin → «Xatolikka uchraganlar»). */
class LocationRejection extends Model
{
    public const TOO_FAR = 'too_far';
    public const LOW_ACCURACY = 'low_accuracy';
    public const OUT_OF_DISTRICT = 'out_of_district';

    public const REASONS = [
        self::TOO_FAR => 'Obyektdan uzoq',
        self::LOW_ACCURACY => 'GPS aniqligi past',
        self::OUT_OF_DISTRICT => 'Tumandan tashqarida',
    ];

    public const SOURCES = ['inspection' => 'Tekshiruv', 'attendance' => 'Ertalabki davomat', 'checkin' => 'Soatlik qayd'];

    protected $fillable = ['staff_id', 'site_id', 'inspection_id', 'reason', 'lat', 'lng', 'accuracy', 'site_lat', 'site_lng', 'distance_m', 'device', 'source', 'note'];

    protected $casts = ['lat' => 'float', 'lng' => 'float', 'accuracy' => 'float', 'site_lat' => 'float', 'site_lng' => 'float', 'distance_m' => 'integer'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    /** tasdiqlanmagan (kadastr) obyekt bo'lsa ham ko'rinsin */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withoutGlobalScope('approved');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    /** Davomat / soatlik qayd tumandan tashqaridan yuborildi: xaritada eng yaqin obyekt bilan solishtiriladi. */
    public static function captureDistrict(Staff $staff, string $source, float $lat, float $lng, ?float $accuracy, array $outside, ?string $device): void
    {
        try {
            $site = \App\Support\Gps::nearestSite($staff, $lat, $lng);
            self::create([
                'staff_id' => $staff->id, 'site_id' => $site?->id, 'source' => $source, 'reason' => self::OUT_OF_DISTRICT,
                'lat' => $lat, 'lng' => $lng, 'accuracy' => $accuracy, 'site_lat' => $site?->lat, 'site_lng' => $site?->lng,
                'distance_m' => $site ? Site::distanceMeters($lat, $lng, $site->lat, $site->lng) : null, 'device' => $device,
                'note' => 'Biriktirilgan hudud: '.$outside['districts'].' — chegarasidan ~'.$outside['distance'].' m tashqarida',
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
        self::tellStaff($staff, $source, $outside, $site ?? null);
    }

    /**
     * Xodimning o'ziga botda: hududda emassiz, quyidagi hududlarda bo'lishingiz kerak (eng yaqin obyekt — xaritada).
     * Qayta-qayta urinsa ham 10 daqiqada bir marta (bot spam bo'lmasin; Mini App'dagi xato matni har safar chiqadi).
     */
    public static function tellStaff(Staff $staff, string $source, array $outside, ?Site $site): void
    {
        if (! $staff->telegram_id || ! \Illuminate\Support\Facades\Cache::add("out-of-district:{$staff->id}:{$source}", 1, now()->addMinutes(10))) {
            return;
        }
        $what = $source === 'checkin' ? 'Soatlik qayd' : 'Davomat';
        $text = "📍 <b>Siz biriktirilgan hududda emassiz</b>\n\n{$what} faqat o‘z hududingizdan qabul qilinadi. Siz quyidagi hudud(lar)da bo‘lishingiz kerak:\n<b>"
            .\App\Telegram\Support\Ui::e($outside['districts']).'</b>'
            ."\n\nHozirgi joyingiz chegaradan taxminan <b>".\App\Filament\Resources\LocationRejectionResource::meters($outside['distance']).'</b> tashqarida. '
            .'Hududingizga borib, qayta yuboring. Hududingiz noto‘g‘ri belgilangan bo‘lsa — tuman mas’uli yoki administratorga murojaat qiling.';
        try {
            app(\SergiX44\Nutgram\Nutgram::class)->sendMessage(\App\Telegram\Support\Script::apply($text), array_filter([
                'chat_id' => $staff->telegram_id, 'parse_mode' => \SergiX44\Nutgram\Telegram\Attributes\ParseMode::HTML,
                'reply_markup' => $site?->lat ? \App\Telegram\Support\Ui::markup([[\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    \App\Telegram\Support\Ui::t('🗺 Hududingizdagi eng yaqin obyekt'), url: \App\Support\Maps::url($site->lat, $site->lng))]]) : null,
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Yozish hech qachon tekshiruvni buzmasin. */
    public static function capture(Inspection $in, string $reason, float $lat, float $lng, ?float $accuracy, ?int $distance, ?string $device): void
    {
        try {
            self::create([
                'staff_id' => $in->staff_id, 'site_id' => $in->site_id, 'inspection_id' => $in->id, 'reason' => $reason,
                'lat' => $lat, 'lng' => $lng, 'accuracy' => $accuracy, 'distance_m' => $distance,
                'site_lat' => $in->site?->lat, 'site_lng' => $in->site?->lng, 'device' => $device,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
