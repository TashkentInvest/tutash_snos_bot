<?php

namespace App\Models;

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

    protected $fillable = [
        'site_id', 'staff_id', 'status', 'step', 'is_demolished', 'reason',
        'staff_lat', 'staff_lng', 'distance_m', 'suspicious', 'flags', 'submitted_at', 'device',
        'review_status', 'reviewed_by', 'reviewed_at', 'review_note', 'archived_at',
    ];

    protected $casts = [
        'is_demolished' => 'boolean',
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
}
