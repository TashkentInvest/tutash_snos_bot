<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Botdagi post (rasm / video + matn) va uning statistikasi. */
class Post extends Model
{
    public const SENDING = 'sending';
    public const SENT = 'sent';

    protected $fillable = ['caption', 'media_type', 'media_path', 'file_id', 'audience', 'ack_button', 'status', 'total', 'delivered', 'failed', 'created_by', 'finished_at', 'recalled_at', 'recalled', 'recall_failed'];

    protected $casts = ['audience' => 'array', 'ack_button' => 'boolean', 'finished_at' => 'datetime', 'recalled_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleted(fn (self $p) => $p->media_path && \Illuminate\Support\Facades\Storage::disk('local')->delete($p->media_path));
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PostDelivery::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Statistika: yetkazildi, «Tanishdim» bosgan, post kelgandan keyin botdan foydalangan (ko'rgan bo'lishi ehtimoli katta —
     * Telegram shaxsiy chatda «ko'rildi»ni botga bermaydi), va shulardan birortasi — «ko'rgan».
     * @return array{total: int, delivered: int, failed: int, ack: int, active: int, seen: int}
     */
    public function stats(): array
    {
        return $this->statsCache ??= $this->computeStats(); // jadvalda bir qatorda 3 ustun — bir marta hisoblanadi
    }

    private ?array $statsCache = null;

    private function computeStats(): array
    {
        $sent = fn () => PostDelivery::where('post_deliveries.post_id', $this->id)->where('post_deliveries.status', PostDelivery::SENT)
            ->join('staff', 'staff.id', '=', 'post_deliveries.staff_id');
        $after = fn ($q) => $q->whereColumn('staff.last_seen_at', '>', 'post_deliveries.sent_at');

        return [
            'total' => (int) $this->total, 'delivered' => (int) $this->delivered, 'failed' => (int) $this->failed,
            'ack' => $sent()->whereNotNull('post_deliveries.ack_at')->count(),
            'active' => $sent()->where($after)->count(),
            'seen' => $sent()->where(fn ($q) => $q->whereNotNull('post_deliveries.ack_at')->orWhere($after))->count(),
        ];
    }

    /** Ro'yxat va xabarlarda: matn boshi yoki media turi. */
    public function title(): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $this->caption));

        return $t !== '' ? \Illuminate\Support\Str::limit($t, 60) : (['photo' => 'Rasm', 'video' => 'Video'][$this->media_type] ?? 'Post');
    }
}
