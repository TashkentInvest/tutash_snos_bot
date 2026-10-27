<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/** ffmpeg yordamida media qayta ishlash: videodan kadr olish va ikki lavhani birlashtirish. Testlarda almashtiriladi. */
class MediaTool
{
    /** Videoning boshlanishidan ozgina o'tgach bitta kadrni JPEG qilib saqlaydi. */
    public function frame(string $video, string $outJpg): bool
    {
        foreach (['0.5', '0'] as $at) {
            $process = new Process(['ffmpeg', '-y', '-ss', $at, '-i', $video, '-frames:v', '1', '-vf', "scale='min(1280,iw)':-2", '-q:v', '3', $outJpg]);
            $process->setTimeout(60);
            $process->run();

            if ($process->isSuccessful() && is_file($outJpg) && filesize($outJpg) > 2_000) {
                return true;
            }
        }
        @unlink($outJpg);

        return false;
    }

    /**
     * Rasm/video butunlay qora ekanini aniqlaydi (ba'zi Android qurilmalarda kamera qora tasvir beradi).
     * Qora tasvirning yorqinligi (YAVG) ~16; hukm chiqarib bo'lmasa — false.
     */
    public function isBlack(string $file): bool
    {
        $process = new Process(['ffmpeg', '-v', 'error', '-i', $file, '-vf', 'fps=3,signalstats,metadata=print:key=lavfi.signalstats.YAVG:file=-', '-an', '-f', 'null', '-']);
        $process->setTimeout(60);
        $process->run();

        if (! preg_match_all('/YAVG=([0-9.]+)/', $process->getOutput(), $m) || $m[1] === []) {
            return false;
        }

        return max(array_map('floatval', $m[1])) < 20;
    }

    /** Yuz lavhasi + hudud lavhasi -> bitta 480x480 mp4 (H.264 + AAC). */
    public function merge(string $face, string $main, string $out): bool
    {
        $audio = $this->hasAudio($face) && $this->hasAudio($main);

        $scale = 'scale=480:480:force_original_aspect_ratio=increase,crop=480:480,setsar=1,fps=25,format=yuv420p,setpts=PTS-STARTPTS';
        $filter = "[0:v]{$scale}[v0];[1:v]{$scale}[v1];";
        $filter .= $audio
            ? '[0:a]aresample=44100,asetpts=PTS-STARTPTS[a0];[1:a]aresample=44100,asetpts=PTS-STARTPTS[a1];[v0][a0][v1][a1]concat=n=2:v=1:a=1[v][a]'
            : '[v0][v1]concat=n=2:v=1:a=0[v]';

        $cmd = ['ffmpeg', '-y', '-i', $face, '-i', $main, '-filter_complex', $filter, '-map', '[v]'];
        $audio && array_push($cmd, '-map', '[a]', '-c:a', 'aac', '-b:a', '64k');
        array_push($cmd, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-movflags', '+faststart', '-threads', '1', $out);

        $process = new Process($cmd);
        $process->setTimeout(150);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($out) || filesize($out) < 5_000) {
            @unlink($out);
            Log::warning('Videolarni birlashtirib bo‘lmadi', ['error' => mb_substr($process->getErrorOutput(), -800)]);

            return false;
        }

        return true;
    }

    private function hasAudio(string $file): bool
    {
        $probe = new Process(['ffprobe', '-v', 'error', '-select_streams', 'a', '-show_entries', 'stream=codec_type', '-of', 'csv=p=0', $file]);
        $probe->setTimeout(30);
        $probe->run();

        return $probe->isSuccessful() && str_contains($probe->getOutput(), 'audio');
    }
}
