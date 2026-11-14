<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gastronomik va turizm ko'chasi (KMZ dan): ijara shartnomalari shu ko'chalar bo'yida tuziladi. */
class GastroStreet extends Model
{
    protected $fillable = ['district_id', 'name', 'line'];

    protected $casts = ['line' => 'array'];

    protected static function booted(): void
    {
        $forget = fn () => \Illuminate\Support\Facades\Cache::forget('gastro-lines');
        static::saved($forget);
        static::deleted($forget);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** Xaritalardagi «Gastronomik ko'chalar» qatlami uchun: [['n' => nomi, 'l' => [[lat, lng], ...]], ...] */
    public static function mapLines(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('gastro-lines', 3600,
            fn () => self::orderBy('id')->get(['name', 'line'])->map(fn (self $s) => ['n' => $s->name, 'l' => $s->line])->all());
    }
}
