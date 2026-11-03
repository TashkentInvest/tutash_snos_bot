<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Shartnoma xodimining soatlik qaydi: joylashuv va jonli video (har soatda, javob muddati — keyingi soatgacha). */
class Checkin extends Model
{
    public const SENT = 'sent';
    public const RECORDING = 'recording';
    public const PRESENT = 'present';
    public const MISSED = 'missed';

    public const OPEN = [self::SENT, self::RECORDING];

    public const STATUSES = [
        self::SENT => 'Kutilmoqda',
        self::RECORDING => 'Video kutilmoqda',
        self::PRESENT => 'Qayd etildi',
        self::MISSED => 'Javob bermagan',
    ];

    protected $fillable = [
        'staff_id', 'date', 'slot', 'status', 'sent_at', 'deadline_at', 'responded_at', 'message_id',
        'lat', 'lng', 'nearest_site_m', 'flags', 'video_path', 'face_video_path', 'video_unique_id', 'video_duration', 'device',
    ];

    protected $casts = [
        'date' => 'date',
        'sent_at' => 'datetime',
        'deadline_at' => 'datetime',
        'responded_at' => 'datetime',
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
        return in_array($this->status, self::OPEN, true) && now()->lessThanOrEqualTo($this->deadline_at);
    }

    /** Bugungi soatlar (config: tutash.checkins.slots), masalan ["10:00", "11:00", ...]. */
    public static function slots(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('tutash.checkins.slots')))));
    }

    /** Soat bo'yicha javob muddati: keyingi soatgacha (masalan 10:00 -> 10:59:59). */
    public static function deadlineFor(string $slot, ?\Carbon\Carbon $day = null): \Carbon\Carbon
    {
        return ($day ?? today())->copy()->setTimeFromTimeString($slot)->addMinutes((int) config('tutash.checkins.window_minutes'))->subSecond();
    }
}
