<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tuman xodimi (Dizayn-kod): ko'chalardagi obyektlarni tekshiradi (peshtaxta yechildi / dizayn-kod bo'yicha qo'yildi). */
class Staff extends Model
{
    protected $table = 'staff';

    protected $casts = ['is_active' => 'boolean', 'created_at' => 'datetime', 'user_id' => 'integer'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'user_id', 'user_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'staff_id');
    }
}
