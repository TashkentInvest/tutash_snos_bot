<?php

namespace App\Jobs;

use App\Models\InspectionMedia;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;

/** Telegram serveridagi rasm/videoni o'z serverimizga arxivlaydi (file_id'ga ishonib qolmaslik uchun). */
class DownloadInspectionMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public int $mediaId)
    {
    }

    public function handle(Nutgram $bot): void
    {
        $media = InspectionMedia::find($this->mediaId);
        if (! $media || $media->downloaded_at) {
            return;
        }

        $file = $bot->getFile($media->telegram_file_id);
        if (! $file) {
            $this->fail(new \RuntimeException('Telegram getFile javob bermadi'));

            return;
        }

        $ext = $media->isVideo() ? 'mp4' : 'jpg';
        $relative = "inspections/{$media->inspection_id}/{$media->kind}.{$ext}";
        $disk = Storage::disk(config('tutash.media_disk'));
        $disk->makeDirectory(dirname($relative));
        $absolute = $disk->path($relative);

        if (! $bot->downloadFile($file, $absolute)) {
            throw new \RuntimeException('Faylni yuklab bo\'lmadi');
        }

        $media->update(['path' => $relative, 'size' => filesize($absolute), 'downloaded_at' => now()]);
    }
}
