<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ko'chadagi tadbirkorlik obyekti (dizayn-kod bo'yicha peshtaxtasi almashtirilishi kerak). Holat faqat tasdiqlangan
 * dalil bilan o'zgaradi: kutilmoqda → yechildi (jarayonda) → qo'yildi.
 */
class DesignObject extends Model
{
    protected $table = 'objects';

    public const PENDING = 'pending';

    public const REMOVED = 'removed';

    public const INSTALLED = 'installed';

    /** Nomlar — botdagi bilan bir xil (shared/labels.json). */
    public const STATUSES = [self::PENDING => 'Kutilmoqda', self::REMOVED => 'Yechildi', self::INSTALLED => 'Qo‘yildi'];

    public const STATUS_COLORS = [self::PENDING => 'gray', self::REMOVED => 'warning', self::INSTALLED => 'success'];

    protected $casts = ['street_id' => 'integer', 'created_by' => 'integer', 'is_active' => 'boolean', 'latitude' => 'float', 'longitude' => 'float',
        'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class, 'street_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'object_id');
    }

    public function pendingInspection(): HasOne
    {
        return $this->hasOne(Inspection::class, 'object_id')->whereIn('review', Inspection::OPEN)->latestOfMany();
    }

    public function scopeInDistrict(Builder $q, int $districtId): Builder
    {
        return $q->whereIn('street_id', Street::query()->where('district_id', $districtId)->select('id'));
    }

    public function label(): string
    {
        return (string) $this->shop_name;
    }
}
