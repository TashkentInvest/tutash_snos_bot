<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gastronomik va turizm ko'chasi (KMZ dan): ijara shartnomalari shu ko'chalar bo'yida tuziladi. */
class GastroStreet extends Model
{
    protected $fillable = ['district_id', 'name', 'line'];

    protected $casts = ['line' => 'array'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
