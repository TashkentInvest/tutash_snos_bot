<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Post bitta oluvchiga: yetkazildimi va «Tanishdim» bosilgan vaqti. */
class PostDelivery extends Model
{
    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const FAILED = 'failed';

    protected $fillable = ['post_id', 'staff_id', 'status', 'message_id', 'sent_at', 'ack_at', 'error'];

    protected $casts = ['sent_at' => 'datetime', 'ack_at' => 'datetime'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }
}
