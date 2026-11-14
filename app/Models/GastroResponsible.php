<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tuman bo'yicha ijara shartnomalari mas'ullari («Shartnoma tushum» jadvali) — bir tumanga bir nechta xodim.
 * Xodimning «Tumanlar»idan alohida: bu yerda o'zgartirish davomat va obyektlarga ta'sir qilmaydi.
 * phone — jadvaldagi raqam: tumanga hali hech kim biriktirilmagan bo'lsa, botga keyin kirgan shu raqam egasi mas'ul hisoblanadi.
 * staff_id ustuni eskirgan (yagona mas'ul davri) — endi gastro_responsible_staff ishlatiladi.
 */
class GastroResponsible extends Model
{
    protected $fillable = ['district_id', 'phone', 'tariff'];

    protected $casts = ['tariff' => 'float']; // 1 m² uchun oylik ijara tarifi, so'm

    public static function tariffFor(int $districtId): ?float
    {
        $t = self::where('district_id', $districtId)->value('tariff');

        return $t !== null && (float) $t > 0 ? (float) $t : null;
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'gastro_responsible_staff');
    }

    /** Xodim shartnoma uchun mas'ul bo'lgan tumanlar. */
    public static function districtIdsFor(Staff $staff): array
    {
        return self::whereHas('staff', fn ($q) => $q->whereKey($staff->id))
            ->orWhere(fn ($q) => $q->doesntHave('staff')->where('phone', $staff->phone ?: '-'))
            ->orderBy('district_id')->pluck('district_id')->all();
    }

    /** Ro'yxat va xaritada ko'rsatish: biriktirilganlar ismi yoki jadvaldagi raqam. */
    public function label(): ?string
    {
        $names = $this->staff->pluck('full_name');

        return $names->isNotEmpty() ? $names->join(', ') : ($this->phone ? $this->phone.' (botga kirmagan)' : null);
    }
}
