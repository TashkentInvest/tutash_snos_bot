<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ko'chadagi tadbirkorlik obyekti (dizayn-kod bo'yicha peshtaxtasi almashtirilishi kerak). */
class DesignObject extends Model
{
    protected $table = 'objects';

    public const PENDING = 'pending';

    public const REMOVED = 'removed';

    public const INSTALLED = 'installed';

    public const STATUSES = [self::PENDING => 'Kutilmoqda', self::REMOVED => 'Eski peshtaxta yechildi', self::INSTALLED => 'Dizayn-kod bo‘yicha qo‘yildi'];

    public const STATUS_COLORS = [self::PENDING => 'gray', self::REMOVED => 'warning', self::INSTALLED => 'success'];

    protected $casts = ['is_active' => 'boolean', 'latitude' => 'float', 'longitude' => 'float', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

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
}
