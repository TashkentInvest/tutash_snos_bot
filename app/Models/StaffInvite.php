<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Admin oldindan qo'shgan xodim: shu telefon bilan botga kirgan odam avtomatik shu rolni (va tumanlarni) oladi. */
class StaffInvite extends Model
{
    protected $fillable = ['phone', 'full_name', 'role', 'district_ids', 'created_by', 'staff_id', 'used_at'];

    protected $casts = ['district_ids' => 'array', 'used_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(fn (StaffInvite $i) => $i->phone = Staff::normalizePhone($i->phone));
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    /** Ro'yxatdan o'tishda ishlatilmagan taklif (telefon bo'yicha). */
    public static function pendingFor(?string $phone): ?self
    {
        return $phone ? self::where('phone', $phone)->whereNull('used_at')->first() : null;
    }
}
