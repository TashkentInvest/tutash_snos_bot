<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** Dizayn-kod botidagi tuman (nomi botda kirillcha saqlanadi — panelda lotinchada ko'rsatiladi). */
class District extends Model
{
    protected $table = 'districts';

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function streets(): HasMany
    {
        return $this->hasMany(Street::class, 'district_id');
    }
}
