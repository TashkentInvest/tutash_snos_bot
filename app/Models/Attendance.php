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
        'video_file_id', 'video_unique_id', 'video_duration', 'video_path', 'face_video_path', 'video_downloaded_at', 'flags', 'device', 'deadline_at', 'comment',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'sent_at' => 'datetime',
        'responded_at' => 'datetime',
        'video_downloaded_at' => 'datetime',
        'deadline_at' => 'datetime',
        'flags' => 'array',
        'lat' => 'float',
        'lng' => 'float',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed(); // o'chirilgan (arxivdagi) xodimning tarixi ham ko'rinadi
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public static function deadline(?Carbon $day = null): Carbon
    {
        return ($day ?? now())->copy()->setTimeFromTimeString(config('tutash.attendance.deadline'));
    }

    /**
     * Joylashuvni muddatgacha yuborib, videoni yozayotgan xodim uchun qo'shimcha daqiqalar:
     * 08:59 da joylashuv yuborgan odamning videosi 09:00:20 da yetib kelsa ham qabul qilinadi.
     */
    public const GRACE_MINUTES = 3;

    /** Video qabul qilinadigan oxirgi payt va attendance:close vaqti (muddat + GRACE_MINUTES). */
    public static function closeAt(?Carbon $day = null): Carbon
    {
        return self::deadline($day)->addMinutes(self::GRACE_MINUTES);
    }

    /** Shu yozuvning javob muddati: qayta ochilgan bo'lsa — o'zining (deadline_at), aks holda umumiy (09:00). */
    public function dueAt(): Carbon
    {
        return $this->deadline_at?->copy() ?? self::deadline();
    }

    /** Muddat o'tdimi (joylashuv va javob tugmalari uchun). */
    public function isLate(): bool
    {
        return now()->greaterThan($this->dueAt());
    }

    /** Video qabul qilinadigan oxirgi payt: muddat + GRACE_MINUTES. */
    public function videoUntil(): Carbon
    {
        return $this->dueAt()->addMinutes(self::GRACE_MINUTES);
    }

    public static function sendTime(?Carbon $day = null): Carbon
    {
        return ($day ?? now())->copy()->setTimeFromTimeString(config('tutash.attendance.send_at'));
    }
}
