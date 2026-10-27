<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionMedia extends Model
{
    public const BEFORE_PHOTO = 'before_photo';
    public const BEFORE_VIDEO = 'before_video';
    public const AFTER_PHOTO = 'after_photo';
    public const AFTER_VIDEO = 'after_video';

    protected $table = 'inspection_media';

    protected $fillable = ['inspection_id', 'kind', 'telegram_file_id', 'file_unique_id', 'path', 'mime', 'size', 'downloaded_at'];

    protected $casts = ['downloaded_at' => 'datetime'];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function isVideo(): bool
    {
        return str_ends_with($this->kind, 'video');
    }
}
