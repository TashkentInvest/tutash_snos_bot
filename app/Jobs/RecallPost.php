<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SergiX44\Nutgram\Nutgram;

/**
 * Yuborilgan postni oluvchilar chatidan o'chiradi (navbatda). Telegram botga o'z xabarini faqat 48 soat ichida
 * o'chirishga ruxsat beradi — eskisi «o'chmadi» bo'lib sanaladi. Statistika panelda qoladi.
 */
class RecallPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(public int $postId)
    {
    }

    public function handle(Nutgram $bot): void
    {
        $post = Post::find($this->postId);
        if (! $post) {
            return;
        }
        $post->deliveries()->where('status', PostDelivery::SENT)->whereNull('recalled_at')->whereNotNull('message_id')->with('staff:id,telegram_id')
            ->chunkById(200, function ($rows) use ($bot) {
                foreach ($rows as $d) {
                    try {
                        $bot->deleteMessage($d->staff->telegram_id, (int) $d->message_id);
                        $d->update(['recalled_at' => now(), 'error' => null]);
                    } catch (\Throwable $e) {
                        $d->update(['error' => 'O‘chirilmadi: '.mb_substr($e->getMessage(), 0, 200)]); // 48 soatdan eski yoki chat yo'q
                    }
                    usleep(40_000); // Telegram cheklovi
                }
            });

        $post->update([
            'recalled' => $post->deliveries()->whereNotNull('recalled_at')->count(),
            'recall_failed' => $post->deliveries()->where('status', PostDelivery::SENT)->whereNull('recalled_at')->count(),
        ]);
    }
}
