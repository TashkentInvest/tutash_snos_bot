<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
