<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\InspectionMedia;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Admin tuzatishlari: xodim "Oldingi holat" deb yuborgan, lekin rasm/video aslida buzilgandan keyingi holat bo'lsa —
 * natija "keyingi holat"ga ko'chiriladi (rasm/video ham), haqiqiy avvalgi holat esa admin tomonidan yuklanadi.
 */
class InspectionFix
{
    /** "Oldingi holat" -> "Buzilgandan keyingi holat": moderator tasdig'iga tushadi (tasdiqlansa — obyekt buzilgan). */
    public static function canMoveToAfter(Inspection $in): bool
    {
        return $in->status === Inspection::SUBMITTED && ! $in->archived_at && ! $in->isFollowup()
            && $in->kindValue() === Inspection::KIND_BEFORE && ! $in->review_status && ! $in->site?->isDemolished();
    }

    public static function moveToAfter(Inspection $in, ?int $userId): bool
    {
        if (! self::canMoveToAfter($in)) {
            return false;
        }
        DB::transaction(function () use ($in) {
            $in->media()->where('kind', InspectionMedia::BEFORE_PHOTO)->update(['kind' => InspectionMedia::AFTER_PHOTO]);
            $in->media()->where('kind', InspectionMedia::BEFORE_VIDEO)->update(['kind' => InspectionMedia::AFTER_VIDEO]);
            $in->update([...Inspection::kindAttributes(Inspection::KIND_AFTER), 'review_status' => Inspection::REVIEW_PENDING]);
        });
        AuditLog::record('inspection.moved_to_after', null, ['inspection' => $in->id, 'by' => $userId]);

        return true;
    }

    /**
     * Admin yuklagan avvalgi holat fayli (Filament vaqtinchalik joyidan) tekshiruvga biriktiriladi.
     * $kind — before_photo yoki before_video; mavjud bo'lsa almashtiriladi. Xato bo'lsa — matn, aks holda null.
     */
    public static function attach(Inspection $in, string $kind, string $uploadedPath, ?int $userId): ?string
    {
        $disk = Storage::disk(config('tutash.media_disk'));
        if (! $disk->exists($uploadedPath)) {
            return 'Fayl topilmadi, qayta yuklang.';
        }
        $hash = sha1_file($disk->path($uploadedPath));
        $ext = strtolower(pathinfo($uploadedPath, PATHINFO_EXTENSION)) ?: ($kind === InspectionMedia::BEFORE_VIDEO ? 'mp4' : 'jpg');
        $path = "inspections/{$in->id}/{$kind}_".substr($hash, 0, 12).".{$ext}";
        $old = $in->media()->where('kind', $kind)->value('path');

        $disk->move($uploadedPath, $path);
        try {
            $in->media()->updateOrCreate(['kind' => $kind], [
                'telegram_file_id' => 'admin', 'file_unique_id' => $hash, 'mime' => $disk->mimeType($path) ?: null,
                'size' => $disk->size($path), 'path' => $path, 'downloaded_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $disk->delete($path);

            return 'Bu fayl tizimda allaqachon bor (boshqa natijada ishlatilgan).';
        }
        $old && $old !== $path && $disk->delete($old);
        AuditLog::record('inspection.before_uploaded', null, ['inspection' => $in->id, 'kind' => $kind, 'by' => $userId]);

        return null;
    }

    /** Ko'chirish + avvalgi holatni yuklash + (ixtiyoriy) darhol tasdiqlash. @return string[] xatolar */
    public static function apply(Inspection $in, array $data, ?int $userId): array
    {
        $errors = [];
        if (! empty($data['move']) && ! self::moveToAfter($in, $userId)) {
            $errors[] = 'Natijani keyingi holatga ko‘chirib bo‘lmadi (holati o‘zgargan).';
        }
        foreach ([InspectionMedia::BEFORE_PHOTO => 'before_photo', InspectionMedia::BEFORE_VIDEO => 'before_video'] as $kind => $field) {
            if ($file = $data[$field] ?? null) {
                ($error = self::attach($in, $kind, is_array($file) ? reset($file) : $file, $userId)) && $errors[] = $error;
            }
        }
        if (! empty($data['approve']) && $in->refresh()->review_status === Inspection::REVIEW_PENDING) {
            InspectionReview::approve($in, $userId);
        }

        return $errors;
    }
}
