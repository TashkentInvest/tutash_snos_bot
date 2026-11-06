<?php

namespace App\Jobs;

use App\Models\Checkin;
use App\Support\MediaTool;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Soatlik qaydning ikki lavhasini (old kamera — yuz, orqa kamera — hudud) bitta mp4 videoga birlashtiradi
 * (davomatdagi MergeAttendanceVideo bilan bir xil). Muvaffaqiyatsiz bo'lsa asl lavhalar qoladi — jadvalda ikkalasi ham ochiladi.
 */
class MergeCheckinVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $checkinId)
    {
    }

    public function handle(MediaTool $tool): void
    {
        $row = Checkin::find($this->checkinId);
        if (! $row || ! $row->face_video_path || ! $row->video_path) {
            return;
        }

        $disk = Storage::disk(config('tutash.media_disk'));
        $face = $disk->path($row->face_video_path);
        $main = $disk->path($row->video_path);
        if (! is_file($face) || ! is_file($main)) {
            return;
        }

        $dir = dirname($row->video_path);
        $merged = $disk->path("{$dir}/{$row->id}_merged.mp4");
        if (! $tool->merge($face, $main, $merged)) {
            return;
        }

        $final = "{$dir}/{$row->id}.mp4";
        @unlink($face);
        @unlink($main);
        rename($merged, $disk->path($final));

        $row->update(['video_path' => $final, 'face_video_path' => null]);
    }
}
