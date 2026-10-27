<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['telegram_id', 'event', 'context'];

    protected $casts = ['context' => 'array'];

    public static function record(string $event, ?int $telegramId = null, array $context = []): void
    {
        static::create(['event' => $event, 'telegram_id' => $telegramId, 'context' => $context ?: null]);
    }
}
