<?php

namespace App\Jobs;

use App\Models\InspectionMedia;
use App\Support\MediaTool;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Tekshiruv videosi: yuz lavhasi + hudud lavhasini bitta mp4 ga birlashtiradi (og'ir ffmpeg ishi HTTP so'rovdan tashqarida).
 * Birlashtirib bo'lmasa, asosiy lavha o'zi qoladi, yuz lavhasi o'chiriladi.
 */
class MergeInspectionVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $mediaId, public string $facePath)
    {
    }

    public function handle(MediaTool $tool): void
    {
        $disk = Storage::disk(config('tutash.media_disk'));
        $media = InspectionMedia::find($this->mediaId);

        // tekshiruv bekor qilingan yoki video almashtirilgan — ortiqcha yuz lavhasi o'chiriladi
        if (! $media || ! $media->path || ! $disk->exists($media->path) || ! $disk->exists($this->facePath)) {
            $disk->delete($this->facePath);

            return;
        }

        $final = dirname($media->path).'/'.pathinfo($media->path, PATHINFO_FILENAME).'_merged.mp4';
        if (! $tool->merge($disk->path($this->facePath), $disk->path($media->path), $disk->path($final))) {
            $disk->delete($this->facePath);

            return;
        }

        $old = $media->path;
        $media->update(['path' => $final, 'mime' => 'video/mp4', 'size' => $disk->size($final)]);
        $disk->delete([$old, $this->facePath]);
    }
}
