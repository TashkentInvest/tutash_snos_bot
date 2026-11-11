<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tuman ko'chasi: kadastr xodimi Mini App'da qidiradi, ro'yxatda bo'lmasa qo'shadi (kim qo'shgani saqlanadi). */
class Street extends Model
{
    protected $fillable = ['district_id', 'name', 'created_by_staff_id'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id')->withTrashed();
    }
}
