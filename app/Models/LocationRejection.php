<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tekshiruvda qabul qilinmagan joylashuv: xodim nuqtasi va obyekt nuqtasi (admin → «Xatolikka uchraganlar»). */
class LocationRejection extends Model
{
    public const TOO_FAR = 'too_far';
    public const LOW_ACCURACY = 'low_accuracy';

    public const REASONS = [
        self::TOO_FAR => 'Obyektdan uzoq',
        self::LOW_ACCURACY => 'GPS aniqligi past',
    ];

    protected $fillable = ['staff_id', 'site_id', 'inspection_id', 'reason', 'lat', 'lng', 'accuracy', 'site_lat', 'site_lng', 'distance_m', 'device'];

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
