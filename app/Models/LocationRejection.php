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
