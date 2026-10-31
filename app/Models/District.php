<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    protected $fillable = ['name', 'responsible_name', 'phone'];

    protected static function booted(): void
    {
        // Mas'ul telefon almashsa — eski va yangi raqamdagi xodimlarning tumanlari darhol yangilanadi.
        static::saved(function (District $district) {
            if ($district->wasChanged('phone')) {
                Staff::resyncDistrictsByPhone([$district->getOriginal('phone'), $district->phone]);
            }
        });
    }

    /** Shu tuman mas'ul telefoni bilan botda ro'yxatdan o'tgan faol xodim (bo'lsa). */
    public function responsibleStaff(): HasOne
    {
        return $this->hasOne(Staff::class, 'phone', 'phone')
            ->where('status', Staff::ACTIVE)->where('role', Staff::ROLE_EMPLOYEE);
    }

    /** Qo'shimcha xodim telefonlari (bitta tumanga 2-3 xodim). */
    public function phones(): HasMany
    {
        return $this->hasMany(DistrictPhone::class)->orderBy('id');
    }

    /** Shu raqam tumanning mas'ul yoki qo'shimcha xodim telefoni bo'lgan tumanlar. */
    public function scopeForPhone(Builder $query, ?string $phone): Builder
    {
        return $query->where(fn ($q) => $q->where('phone', $phone)->orWhereHas('phones', fn ($q) => $q->where('phone', $phone)));
    }

    /** Mas'ul telefoni kiritilgan, lekin o'sha raqam bilan hali hech kim botda ro'yxatdan o'tmagan tumanlar. */
    public function scopeUnregistered(Builder $query): Builder
    {
        return $query->whereNotNull('phone')->whereDoesntHave('responsibleStaff');
    }

    public function mahallas(): HasMany
    {
        return $this->hasMany(Mahalla::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'district_staff');
    }

    /** "Qoraboyev Abduvohid, +998 93 806 87 58" ko'rinishida (ism bo'lmasa — faqat raqam). */
    public function contactLine(): ?string
    {
        $phone = $this->phoneFormatted();

        return $phone ? trim(($this->responsible_name ? $this->responsible_name.', ' : '').$phone) : null;
    }

    public function phoneFormatted(): ?string
    {
        if (! $this->phone || strlen($this->phone) !== 9) {
            return null;
        }

        return '+998 '.substr($this->phone, 0, 2).' '.substr($this->phone, 2, 3).' '.substr($this->phone, 5, 2).' '.substr($this->phone, 7, 2);
    }
}
