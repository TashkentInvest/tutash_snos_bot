<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** Dizayn-kod botidan foydalangan odam (fuqaro, xodim yoki rahbar). */
class BotUser extends Model
{
    protected $table = 'users';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public const LANGS = ['uz' => 'O‘zbekcha', 'ru' => 'Русский'];

    protected $casts = [
        'first_seen' => 'datetime', 'last_seen' => 'datetime', 'registered_at' => 'datetime', 'consent_at' => 'datetime',
        'concept_at' => 'datetime', 'approved_at' => 'datetime', 'blocked_at' => 'datetime',
        'latitude' => 'float', 'longitude' => 'float', 'start_count' => 'integer',
    ];

    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class, 'user_id', 'user_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(UserPhoto::class, 'user_id', 'user_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class, 'user_id', 'user_id');
    }

    /** Ism: ro'yxatdagi F.I.Sh, bo'lmasa Telegram ismi, bo'lmasa telefon / id. */
    public function displayName(): string
    {
        return trim((string) $this->full_name) ?: (trim($this->first_name.' '.$this->last_name) ?: ($this->phone ?: (string) $this->user_id));
    }
}
