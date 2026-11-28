<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dizayn-kod botiga post: panel yozadi (posts + post_deliveries, status=sending), bot o'zi yuboradi (post_worker),
 * «Tanishdim» va «Botdan o'chirish» ham bot tomonda.
 */
class Post extends Model
{
    protected $table = 'posts';

    protected $casts = ['audience' => 'array', 'ack_button' => 'boolean', 'created_at' => 'datetime', 'finished_at' => 'datetime',
        'recalled_at' => 'datetime', 'recall_finished_at' => 'datetime'];

    public function deliveries(): HasMany
    {
        return $this->hasMany(PostDelivery::class, 'post_id');
    }
}
