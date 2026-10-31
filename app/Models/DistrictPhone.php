<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tumanning qo'shimcha xodim telefoni: shu raqam bilan botga kirgan odam tumanga xodim sifatida biriktiriladi. */
class DistrictPhone extends Model
{
    protected $fillable = ['district_id', 'phone', 'name'];

    protected static function booted(): void
    {
        // raqam qo'shilsa/o'zgarsa/o'chirilsa — shu raqamdagi xodimlarning tumanlari darhol yangilanadi
        static::saving(fn (DistrictPhone $p) => $p->phone = Staff::normalizePhone($p->phone));
        static::saved(fn (DistrictPhone $p) => Staff::resyncDistrictsByPhone([$p->getOriginal('phone'), $p->phone]));
        static::deleted(fn (DistrictPhone $p) => Staff::resyncDistrictsByPhone([$p->phone]));
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** Shu raqam bilan botda ro'yxatdan o'tgan faol xodim (bo'lsa). */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'phone', 'phone')
            ->where('status', Staff::ACTIVE)->where('role', Staff::ROLE_EMPLOYEE);
    }
}
