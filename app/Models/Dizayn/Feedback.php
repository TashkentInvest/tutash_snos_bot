<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fuqaroning murojaati (e'tiroz, ehtiyoj, taklif). Javob shu yerda yoziladi (answer, answered_at, answered_by) —
 * bot uni o'zi fuqaroga yuboradi va answer_sent_at / answer_error ni qo'yadi.
 */
class Feedback extends Model
{
    protected $table = 'feedback';

    public const ANSWER_MAX = 3000; // iqtibos bilan Telegram'ning 4096 belgilik chegarasiga sig'adi

    public const LEVELS = ['high' => 'Yuqori', 'medium' => 'O‘rta', 'low' => 'Past'];

    public const LEVEL_COLORS = ['high' => 'danger', 'medium' => 'warning', 'low' => 'success'];

    public const STATUSES = ['new' => 'Yangi', 'answered' => 'Javob yuborilmoqda', 'delivered' => 'Javob yetkazildi', 'failed' => 'Yetkazilmadi'];

    public const STATUS_COLORS = ['new' => 'danger', 'answered' => 'warning', 'delivered' => 'success', 'failed' => 'gray'];

    protected $casts = ['created_at' => 'datetime', 'answered_at' => 'datetime', 'answer_sent_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'user_id', 'user_id');
    }

    public function status(): string
    {
        return match (true) {
            $this->answer === null => 'new',
            $this->answer_sent_at === null => 'answered',
            $this->answer_error !== null => 'failed',
            default => 'delivered',
        };
    }
}
