<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Inspection extends Model
{
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';

    // "Buzilgan" natijasi moderator tasdig'ini kutadi: tasdiqlanmaguncha obyekt holati va hisoblar o'zgarmaydi.
    public const REVIEW_PENDING = 'pending';
    public const REVIEW_APPROVED = 'approved';
    public const REVIEW_REJECTED = 'rejected';

    // "Muammo bor" natijasi: court_cancelled — sud qarori bekor qilingan (hujjat ilova qilinadi, moderator tasdiqlasa obyekt
    // jami sondan chiqariladi), other — boshqa muammo (faqat izoh, obyekt ro'yxatda qoladi).
    public const EXEMPT_COURT = 'court_cancelled';
    public const EXEMPT_OTHER = 'other';

    public const EXEMPT_TYPES = [self::EXEMPT_COURT => 'Sud qarori bekor qilingan', self::EXEMPT_OTHER => 'Boshqa muammo'];

    public const MAX_DOCUMENTS = 10;

    protected $fillable = [
        'site_id', 'staff_id', 'status', 'step', 'is_demolished', 'in_progress', 'exempt_type', 'reason',
        'staff_lat', 'staff_lng', 'distance_m', 'suspicious', 'flags', 'submitted_at', 'device',
        'review_status', 'reviewed_by', 'reviewed_at', 'review_note', 'archived_at',
    ];

    protected $casts = [
        'is_demolished' => 'boolean',
        'in_progress' => 'boolean',
        'suspicious' => 'boolean',
        'flags' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'archived_at' => 'datetime',
        'staff_lat' => 'float',
        'staff_lng' => 'float',
    ];

    protected static function booted(): void
    {
        // Yozuv o'chirilsa (bekor qilish, eski qoralama, prune) diskdagi rasm/videolar ham o'chiriladi — yetim fayl qolmaydi.
        static::deleted(fn (Inspection $in) => Storage::disk(config('tutash.media_disk'))->deleteDirectory("inspections/{$in->id}"));
    }

    /** Hisobga olinadigan natijalar: yuborilgan va moderator rad etmagan (rad etilganlar arxivlanadi). */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', self::SUBMITTED)->whereNull('archived_at');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(InspectionMedia::class);
    }

    public function mediaOf(string $kind): ?InspectionMedia
    {
        return $this->media->firstWhere('kind', $kind);
    }

    /** Ilova qilingan hujjatlar (sud qarori va h.k.): document_1 ... document_N */
    public function documents(): \Illuminate\Support\Collection
    {
        return $this->media->filter(fn (InspectionMedia $m) => $m->isDocument())->sortBy('id')->values();
    }

    public function isCourtCancelled(): bool
    {
        return $this->exempt_type === self::EXEMPT_COURT;
    }

    /** Moderator tasdig'ini talab qiladigan natija: obyekt holatini o'zgartiradi. */
    public function needsReview(): bool
    {
        return $this->is_demolished === true || $this->isCourtCancelled();
    }
}
