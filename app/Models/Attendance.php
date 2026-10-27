<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    public const SENT = 'sent';
    public const LOCATING = 'locating';
    public const RECORDING = 'recording';
    public const REASONING = 'reasoning';
    public const PRESENT = 'present';
    public const ABSENT = 'absent';
    public const MISSED = 'missed';

    /** Xodim hali javobini tugatmagan holatlar. */
    public const OPEN = [self::SENT, self::LOCATING, self::RECORDING, self::REASONING];

    protected $fillable = [
        'staff_id', 'date', 'status', 'sent_at', 'responded_at', 'message_id', 'lat', 'lng', 'reason',
        'video_file_id', 'video_unique_id', 'video_duration', 'video_path', 'face_video_path', 'video_downloaded_at', 'flags', 'device',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'sent_at' => 'datetime',
        'responded_at' => 'datetime',
        'video_downloaded_at' => 'datetime',
        'flags' => 'array',
        'lat' => 'float',
        'lng' => 'float',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public static function deadline(?Carbon $day = null): Carbon
    {
        return ($day ?? now())->copy()->setTimeFromTimeString(config('tutash.attendance.deadline'));
    }

    public static function sendTime(?Carbon $day = null): Carbon
    {
        return ($day ?? now())->copy()->setTimeFromTimeString(config('tutash.attendance.send_at'));
    }
}
