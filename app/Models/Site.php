<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Site extends Model
{
    public const NOT_DEMOLISHED = 'not_demolished';
    public const DEMOLISHED = 'demolished';

    public const IN_PROGRESS = 'in_progress'; // qisman buzilgan, buzish davom etmoqda

    protected $fillable = [
        'district_id', 'mahalla_id', 'source_no', 'address', 'lat', 'lng',
        'violator_name', 'violator_passport', 'org_inn', 'area_m2', 'is_tutash',
        'court_date', 'court_number', 'court_status', 'status', 'demolished_at', 'last_inspected_at',
    ];

    protected $casts = [
        'violator_passport' => 'encrypted',
        'org_inn' => 'encrypted',
        'is_tutash' => 'boolean',
        'court_date' => 'date',
        'demolished_at' => 'date',
        'last_inspected_at' => 'datetime',
        'lat' => 'float',
        'lng' => 'float',
        'area_m2' => 'float',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function mahalla(): BelongsTo
    {
        return $this->belongsTo(Mahalla::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    /** Moderator tasdig'ini kutayotgan "buzilgan" tekshiruv (bo'lsa). */
    public function pendingReview(): HasOne
    {
        return $this->hasOne(Inspection::class)->where('status', Inspection::SUBMITTED)->where('review_status', Inspection::REVIEW_PENDING)->latestOfMany();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::NOT_DEMOLISHED, self::IN_PROGRESS]);
    }

    public function isDemolished(): bool
    {
        return $this->status === self::DEMOLISHED;
    }

    public function maskedPassport(): ?string
    {
        $p = $this->violator_passport;

        return $p ? substr($p, 0, 2).str_repeat('•', max(strlen($p) - 4, 0)).substr($p, -2) : null;
    }

    /** Haversine masofa (metr). */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
