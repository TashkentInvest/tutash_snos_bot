<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Xodim Mini App'dan yuborgan dalil: joylashuv, rasm, video, izoh — admin tasdiqlagach obyekt holati o'zgaradi
 * (App\Support\Dizayn\InspectionReview). Rasm/video yo'li botning uploads papkasiga nisbatan.
 */
class Inspection extends Model
{
    protected $table = 'inspections';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Moderator izoh bilan ushlab turibdi: tasdiqlanmagan, obyekt tugallanmagan; xodim yangi dalil yubora olmaydi. */
    public const MODERATION = 'moderation';

    /** «Jarayonli tasdiqlandi»: qo'yilgan, lekin tuzatish kerak (PROCESS_REASONS) — xodim yangi dalil yuboradi. */
    public const CONFIRMING = 'confirming';

    /** Hali ochiq: moderator qarorini kutyapti (obyekt yangi dalil olmaydi). */
    public const OPEN = [self::PENDING, self::MODERATION];

    /** Tasdiqlash mumkin bo'lganlar. */
    public const LOCKED = [self::PENDING, self::MODERATION, self::CONFIRMING];

    public const KINDS = [DesignObject::REMOVED => 'Yechildi (eski peshtaxta olindi)', DesignObject::INSTALLED => 'Dizayn-kod bo‘yicha qo‘yildi'];

    /** Nomlar — botdagi bilan bir xil (shared/labels.json). */
    public const REVIEWS = [self::PENDING => 'Tekshiruvda', self::MODERATION => 'Moderator jarayonida', self::CONFIRMING => 'Jarayonli tasdiqlandi',
        self::APPROVED => 'Tasdiqlandi', self::REJECTED => 'Rad etildi'];

    public const REVIEW_COLORS = [self::PENDING => 'warning', self::MODERATION => 'info', self::CONFIRMING => 'primary', self::APPROVED => 'success', self::REJECTED => 'danger'];

    /** Nimani tuzatish kerak: kalit => [uz, ru] (xodimga o'z tilida boradi). */
    public const PROCESS_REASONS = [
        'letters' => ['Bukva qo‘yilmagan', 'Буквы не установлены'],
        'design' => ['Dizayn-kodga mos emas', 'Не соответствует дизайн-коду'],
    ];

    protected $casts = ['object_id' => 'integer', 'staff_id' => 'integer', 'latitude' => 'float', 'longitude' => 'float', 'obj_latitude' => 'float',
        'obj_longitude' => 'float', 'accuracy' => 'float', 'distance_m' => 'float', 'created_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function object(): BelongsTo
    {
        return $this->belongsTo(DesignObject::class, 'object_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    /** Xodim turgan joy. */
    public function mapUrl(): ?string
    {
        return $this->latitude !== null ? "https://maps.google.com/?q={$this->latitude},{$this->longitude}" : null;
    }

    /** Xodim xaritada belgilagan obyekt joyi. */
    public function objectMapUrl(): ?string
    {
        return $this->obj_latitude !== null ? "https://maps.google.com/?q={$this->obj_latitude},{$this->obj_longitude}" : null;
    }
}
