<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Post bitta oluvchiga (Telegram id): yetkazildimi, «Tanishdim», botdan o'chirildimi. */
class PostDelivery extends Model
{
    protected $table = 'post_deliveries';

    protected $casts = ['sent_at' => 'datetime', 'ack_at' => 'datetime', 'recalled_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'user_id', 'user_id');
    }
}
