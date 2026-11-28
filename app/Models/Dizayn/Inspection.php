<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Xodim Mini App'dan yuborgan dalil: joylashuv, rasm, video, izoh — admin tasdiqlagach obyekt holati o'zgaradi. */
class Inspection extends Model
{
    protected $table = 'inspections';

    public const KINDS = [DesignObject::REMOVED => 'Yechildi (eski peshtaxta olindi)', DesignObject::INSTALLED => 'Dizayn-kod bo‘yicha qo‘yildi'];

    public const REVIEWS = ['pending' => 'Tekshirilmoqda', 'moderation' => 'Moderatsiyada', 'confirming' => 'Tasdiqlanmoqda', 'approved' => 'Tasdiqlangan', 'rejected' => 'Rad etilgan'];

    public const REVIEW_COLORS = ['pending' => 'warning', 'moderation' => 'info', 'confirming' => 'primary', 'approved' => 'success', 'rejected' => 'danger'];

    protected $casts = ['latitude' => 'float', 'longitude' => 'float', 'obj_latitude' => 'float', 'obj_longitude' => 'float', 'accuracy' => 'float',
        'distance_m' => 'float', 'created_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function object(): BelongsTo
    {
        return $this->belongsTo(DesignObject::class, 'object_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}
