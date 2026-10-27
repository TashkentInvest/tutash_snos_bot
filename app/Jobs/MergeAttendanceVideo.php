<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Support\MediaTool;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Davomatdagi ikki lavhani (old kamera — yuz, orqa kamera — hudud) bitta mp4 videoga birlashtiradi.
 * Muvaffaqiyatsiz bo'lsa, asl lavhalar o'zgarishsiz qoladi (video baribir ko'rinadi).
 */
class MergeAttendanceVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $attendanceId)
    {
    }

    public function handle(MediaTool $tool): void
    {
        $row = Attendance::find($this->attendanceId);
        if (! $row || ! $row->face_video_path || ! $row->video_path) {
            return;
        }

        $disk = Storage::disk(config('tutash.media_disk'));
        $face = $disk->path($row->face_video_path);
        $main = $disk->path($row->video_path);
        if (! is_file($face) || ! is_file($main)) {
            return;
        }

        $merged = $disk->path("attendance/{$row->date->format('Y-m-d')}/{$row->id}_merged.mp4");
        if (! $tool->merge($face, $main, $merged)) {
            return;
        }

        $final = "attendance/{$row->date->format('Y-m-d')}/{$row->id}.mp4";
        @unlink($face);
        @unlink($main);
        rename($merged, $disk->path($final));

        $row->update(['video_path' => $final, 'face_video_path' => null]);
    }
}
