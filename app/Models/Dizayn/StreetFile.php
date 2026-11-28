<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ko'chaning konsepsiya fayli (PDF); path — botning data papkasiga nisbatan. */
class StreetFile extends Model
{
    protected $table = 'street_files';

    protected $casts = ['size' => 'integer', 'created_at' => 'datetime'];

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class, 'street_id');
    }
}
