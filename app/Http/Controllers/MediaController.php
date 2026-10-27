<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\InspectionMedia;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function __invoke(InspectionMedia $media)
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $disk = Storage::disk(config('tutash.media_disk'));
        abort_unless($media->path && $disk->exists($media->path), 404);

        return $this->privateFile(response()->file($disk->path($media->path), [
            'Content-Type' => $media->mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    public function attendanceVideo(Attendance $attendance)
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $path = request()->boolean('face') ? $attendance->face_video_path : $attendance->video_path;

        $disk = Storage::disk(config('tutash.media_disk'));
        abort_unless($path && $disk->exists($path), 404);

        return $this->privateFile(response()->file($disk->path($path), [
            'Content-Type' => str_ends_with($path, '.webm') ? 'video/webm' : (str_ends_with($path, '.mov') ? 'video/quicktime' : 'video/mp4'),
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    /** Fayl qayta olinsa eski nusxa ko'rsatilmasin: brauzer har safar yangiligini tekshiradi, umumiy keshga tushmaydi. */
    private function privateFile(\Symfony\Component\HttpFoundation\BinaryFileResponse $response): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->headers->addCacheControlDirective('no-cache');

        return $response;
    }
}
