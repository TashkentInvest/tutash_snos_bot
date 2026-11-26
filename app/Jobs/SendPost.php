<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostDelivery;
use App\Telegram\Support\Script;
use App\Telegram\Support\Ui;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

/**
 * Postni oluvchilarga yuboradi (navbatda — ko'p odamga yuborish sahifani kuttirmaydi). Fayl Telegram'ga bir marta yuklanadi,
 * keyin file_id bilan. To'xtab qolsa — qayta ishga tushganda faqat yuborilmaganlarga davom etadi.
 */
class SendPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 3;

    public function __construct(public int $postId)
    {
    }

    public static function caption(Post $post): ?string
    {
        return filled($post->caption) ? Script::apply(Ui::e(trim($post->caption))) : null;
    }

    public function handle(Nutgram $bot): void
    {
        $post = Post::find($this->postId);
        if (! $post) {
            return;
        }
        $caption = self::caption($post);
        $markup = $post->ack_button ? Ui::markup([[Ui::btn('✅ Tanishdim', 'pa:'.$post->id)]]) : null;

        $post->deliveries()->where('status', PostDelivery::PENDING)->with('staff:id,telegram_id')->orderBy('id')
            ->chunkById(200, function ($rows) use ($bot, $post, $caption, $markup) {
                foreach ($rows as $d) {
                    $opt = array_filter(['chat_id' => $d->staff?->telegram_id, 'parse_mode' => ParseMode::HTML, 'reply_markup' => $markup]);
                    try {
                        $msg = match ($post->media_type) {
                            'photo' => $bot->sendPhoto($this->media($post), $opt + array_filter(['caption' => $caption])),
                            'video' => $bot->sendVideo($this->media($post), $opt + array_filter(['caption' => $caption, 'supports_streaming' => true])),
                            default => $bot->sendMessage($caption ?? '—', $opt + ['disable_web_page_preview' => true]),
                        };
                        if (! $post->file_id && $post->media_type) { // keyingilarga qayta yuklanmaydi
                            $id = $post->media_type === 'photo' ? collect($msg?->photo ?? [])->last()?->file_id : $msg?->video?->file_id;
                            $id && $post->update(['file_id' => $id]);
                        }
                        $d->update(['status' => PostDelivery::SENT, 'message_id' => $msg?->message_id, 'sent_at' => now()]);
                    } catch (\Throwable $e) {
                        $d->update(['status' => PostDelivery::FAILED, 'error' => mb_substr($e->getMessage(), 0, 250)]); // botni bloklagan bo'lishi mumkin
                    }
                    usleep(40_000); // Telegram cheklovi: ~30 xabar/soniya
                }
                $this->count($post);
            });

        $this->count($post);
        $post->update(['status' => Post::SENT, 'finished_at' => now()]);
    }

    private function media(Post $post): InputFile|string
    {
        if ($post->file_id) {
            return $post->file_id;
        }
        $fh = fopen(Storage::disk('local')->path($post->media_path), 'r');

        return InputFile::make($fh, basename($post->media_path));
    }

    private function count(Post $post): void
    {
        $post->update([
            'delivered' => $post->deliveries()->where('status', PostDelivery::SENT)->count(),
            'failed' => $post->deliveries()->where('status', PostDelivery::FAILED)->count(),
        ]);
    }
}
