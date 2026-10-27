<?php

namespace App\Jobs;

use App\Models\Attendance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;

/** Davomat dumaloq videosini o'z serverimizga arxivlaydi. */
class DownloadAttendanceVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public int $attendanceId)
    {
    }

    public function handle(Nutgram $bot): void
    {
        $row = Attendance::find($this->attendanceId);
        if (! $row || ! $row->video_file_id || $row->video_downloaded_at) {
            return;
        }

        $file = $bot->getFile($row->video_file_id);
        if (! $file) {
            $this->fail(new \RuntimeException('Telegram getFile javob bermadi'));

            return;
        }

        $relative = "attendance/{$row->date->format('Y-m-d')}/{$row->id}.mp4";
        $disk = Storage::disk(config('tutash.media_disk'));
        $disk->makeDirectory(dirname($relative));
        $absolute = $disk->path($relative);

        if (! $bot->downloadFile($file, $absolute)) {
            throw new \RuntimeException('Faylni yuklab bo‘lmadi');
        }

        $row->update(['video_path' => $relative, 'video_downloaded_at' => now()]);
    }
}
