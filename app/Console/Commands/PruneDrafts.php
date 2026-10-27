<?php

namespace App\Console\Commands;

use App\Models\Inspection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneDrafts extends Command
{
    protected $signature = 'inspections:prune-drafts {--hours=24}';

    protected $description = 'Tugallanmagan (draft) tekshiruvlarni belgilangan muddatdan keyin o\'chiradi va bazada yozuvi yo\'q media papkalarini tozalaydi.';

    public function handle(): int
    {
        // Model orqali o'chiriladi, shunda Inspection::deleted hodisasi fayllarni ham o'chiradi.
        $n = 0;
        Inspection::where('status', Inspection::DRAFT)
            ->where('updated_at', '<', now()->subHours((int) $this->option('hours')))
            ->each(function (Inspection $in) use (&$n) {
                $in->delete();
                $n++;
            });
        $this->info("O'chirildi: {$n}");

        // Avvalgi versiyalardan qolgan yetim papkalar (inspections/{id} — bunday tekshiruv bazada yo'q).
        $disk = Storage::disk(config('tutash.media_disk'));
        $ids = collect($disk->directories('inspections'))->filter(fn ($dir) => ctype_digit(basename($dir)))->mapWithKeys(fn ($dir) => [(int) basename($dir) => $dir]);
        $orphans = $ids->except(Inspection::whereIn('id', $ids->keys())->pluck('id')->all());
        $orphans->each(fn ($dir) => $disk->deleteDirectory($dir));
        $this->info("Yetim papkalar: {$orphans->count()}");

        return self::SUCCESS;
    }
}
