<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tuman bo'yicha ijara shartnomalari mas'uli («Shartnoma tushum» jadvali). Xodimning «Tumanlar»idan alohida:
 * xodim almashsa — shu yerda boshqasi tanlanadi, tuman shartnomalari yangi xodimga o'tadi; davomatga ta'sir qilmaydi.
 */
class GastroResponsible extends Model
{
    protected $fillable = ['district_id', 'staff_id', 'phone'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    /** Xodim shartnoma uchun mas'ul bo'lgan tumanlar. */
    public static function districtIdsFor(Staff $staff): array
    {
        // tanlangan xodim yoki botga keyin kirgan, jadvaldagi raqam egasi
        return self::where('staff_id', $staff->id)
            ->orWhere(fn ($q) => $q->whereNull('staff_id')->where('phone', $staff->phone ?: '-'))
            ->pluck('district_id')->all();
    }
}
