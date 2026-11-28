<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Botdan yuklab olingan dizayn-kod fayli (ko'cha konsepsiyasi PDF). */
class Download extends Model
{
    protected $table = 'downloads';

    protected $casts = ['sent_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'user_id', 'user_id');
    }
}
