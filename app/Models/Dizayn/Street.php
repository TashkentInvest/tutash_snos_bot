<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Dizayn-kod ko'chasi: konsepsiya PDF fayllari, rejadagi obyektlar soni, uzunligi va chizig'i. */
class Street extends Model
{
    protected $table = 'streets';

    protected $casts = ['is_active' => 'boolean', 'planned_objects' => 'integer', 'nonres_objects' => 'integer', 'length_km' => 'float'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StreetFile::class, 'street_id');
    }

    public function objects(): HasMany
    {
        return $this->hasMany(DesignObject::class, 'street_id');
    }
}
