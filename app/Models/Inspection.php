<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Inspection extends Model
{
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';

    // "Buzilgan" natijasi moderator tasdig'ini kutadi: tasdiqlanmaguncha obyekt holati va hisoblar o'zgarmaydi.
    public const REVIEW_PENDING = 'pending';
    public const REVIEW_APPROVED = 'approved';
    public const REVIEW_REJECTED = 'rejected';

    // "Muammo bor" natijasi: court_cancelled — sud qarori bekor qilingan (hujjat ilova qilinadi, moderator tasdiqlasa obyekt
    // jami sondan chiqariladi), other — boshqa muammo (faqat izoh, obyekt ro'yxatda qoladi).
    public const EXEMPT_COURT = 'court_cancelled';
    public const EXEMPT_OTHER = 'other';

    public const EXEMPT_TYPES = [self::EXEMPT_COURT => 'Sud qarori bekor qilingan', self::EXEMPT_OTHER => 'Boshqa muammo'];

    public const MAX_DOCUMENTS = 10;

    // Tekshiruv turi ("Tekshiruvni boshlash" dan keyin tanlanadi). "Tekshirish" bosqichlari turli kunlarda:
    // before (oldingi holat) -> process (buzish jarayoni, obyekt "Jarayonda") -> after (buzilgandan keyin, moderator tasdig'i).
    public const KIND_BEFORE = 'before';
    public const KIND_PROCESS = 'process';
    public const KIND_AFTER = 'after';
    public const KIND_COURT = 'court';   // sud qarori bekor: rasm, video va hujjatlar — moderator tasdig'i
    public const KIND_FOUND = 'found';   // kelganda allaqachon buzilgan — moderator tasdig'i

    public const KINDS = [
        self::KIND_BEFORE => 'Oldingi holat',
        self::KIND_PROCESS => 'Buzish jarayoni',
        self::KIND_AFTER => 'Buzilgandan keyingi holat',
        self::KIND_COURT => 'Sud qarori bekor',
        self::KIND_FOUND => 'Kelganda buzilgan',
    ];

    /** Natija maydonlari (hisobotlar va obyekt holati shular bo'yicha ishlaydi). */
    public static function kindAttributes(string $kind): array
    {
        return [
            'kind' => $kind,
            'is_demolished' => in_array($kind, [self::KIND_AFTER, self::KIND_FOUND], true),
            'in_progress' => $kind === self::KIND_PROCESS,
            'exempt_type' => $kind === self::KIND_COURT ? self::EXEMPT_COURT : null,
        ];
    }

    /** Turi (eski natijalarda — natija maydonlaridan aniqlanadi). */
    public function kindValue(): string
    {
        return $this->kind ?? match (true) {
            (bool) $this->is_demolished => self::KIND_AFTER,
            $this->exempt_type === self::EXEMPT_COURT => self::KIND_COURT,
            (bool) $this->in_progress => self::KIND_PROCESS,
            default => self::KIND_BEFORE,
        };
    }

    /** Shu tekshiruvda olinadigan rasm va video turlari. */
    public function mediaKinds(): array
    {
        return match ($this->isFollowup() ? self::KIND_AFTER : $this->kindValue()) {
            self::KIND_PROCESS => [InspectionMedia::PROCESS_PHOTO, InspectionMedia::PROCESS_VIDEO],
            self::KIND_AFTER, self::KIND_FOUND => [InspectionMedia::AFTER_PHOTO, InspectionMedia::AFTER_VIDEO],
            default => [InspectionMedia::BEFORE_PHOTO, InspectionMedia::BEFORE_VIDEO],
        };
    }

    // Moderator izohi holati: open — bajarilmagan, done — xodim bajardi (tekshirish kerak), closed — moderator yopdi.
    public const FOLLOWUP_OPEN = 'open';
    public const FOLLOWUP_DONE = 'done';
    public const FOLLOWUP_CLOSED = 'closed';

    public const FOLLOWUPS = [
        self::FOLLOWUP_OPEN => 'Bajarilmagan',
        self::FOLLOWUP_DONE => 'Xodim bajardi — tekshiring',
        self::FOLLOWUP_CLOSED => 'Yopilgan',
    ];

    // Ko'chirilgan (tuzatish qoralamasiga o'tgan) faylning eski yozuvi: hash shu qo'shimcha bilan belgilanadi.
    private const MOVED = '#r';

    protected $fillable = [
        'site_id', 'staff_id', 'status', 'kind', 'step', 'is_demolished', 'in_progress', 'exempt_type', 'reason',
        'staff_lat', 'staff_lng', 'distance_m', 'suspicious', 'flags', 'submitted_at', 'device',
        'review_status', 'reviewed_by', 'reviewed_at', 'review_note', 'archived_at',
        'rework_of', 'followup_of', 'edit_target', 'rework_located', 'followup', 'followup_at',
    ];

    protected $casts = [
        'is_demolished' => 'boolean',
        'in_progress' => 'boolean',
        'suspicious' => 'boolean',
        'rework_located' => 'boolean',
        'flags' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'archived_at' => 'datetime',
        'followup_at' => 'datetime',
        'staff_lat' => 'float',
        'staff_lng' => 'float',
    ];

    protected static function booted(): void
    {
        // Yozuv o'chirilsa (bekor qilish, eski qoralama, prune) diskdagi rasm/videolar ham o'chiriladi — yetim fayl qolmaydi.
        static::deleted(function (Inspection $in) {
            Storage::disk(config('tutash.media_disk'))->deleteDirectory("inspections/{$in->id}");
            // bekor qilingan tuzatish qoralamasi: rad etilgan natija fayllari yana takror tekshiruvida qatnashadi
            $in->rework_of && self::releaseMovedMedia($in->rework_of);
        });
    }

    /** Hisobga olinadigan natijalar: yuborilgan va moderator rad etmagan (rad etilganlar arxivlanadi), izoh bo'yicha tasdiqlar emas. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', self::SUBMITTED)->whereNull('archived_at')->whereNull('followup_of');
    }

    /**
     * Rad etilgan natijani tuzatish uchun qoralama: natija, izoh va fayllar nusxalanadi, xodim faqat keraklisini qayta oladi.
     * Fayl hashi yagona (takror fayl tekshiruvi), shuning uchun eski yozuvning hashi vaqtincha belgilab qo'yiladi.
     */
    public static function reworkDraft(Inspection $old): self
    {
        $draft = self::create([
            'site_id' => $old->site_id, 'staff_id' => $old->staff_id, 'status' => self::DRAFT, 'step' => 'edit',
            'rework_of' => $old->id, 'kind' => $old->kindValue(), 'is_demolished' => $old->is_demolished, 'in_progress' => $old->in_progress,
            'exempt_type' => $old->exempt_type, 'reason' => $old->reason, 'staff_lat' => $old->staff_lat, 'staff_lng' => $old->staff_lng,
            'distance_m' => $old->distance_m, 'suspicious' => $old->suspicious, 'flags' => $old->flags, 'device' => $old->device,
        ]);

        $disk = Storage::disk(config('tutash.media_disk'));
        foreach ($old->media()->get() as $m) {
            $path = "inspections/{$draft->id}/".basename($m->path);
            if (! $m->path || ! $disk->exists($m->path) || ! $disk->copy($m->path, $path)) {
                continue;
            }
            $unique = $m->isDocument() ? "doc:{$draft->id}:".substr($m->file_unique_id, strrpos($m->file_unique_id, ':') + 1) : $m->file_unique_id;
            $m->isDocument() || $m->update(['file_unique_id' => $m->file_unique_id.self::MOVED.$old->id]);
            $draft->media()->create([
                'kind' => $m->kind, 'telegram_file_id' => $m->telegram_file_id, 'file_unique_id' => $unique,
                'mime' => $m->mime, 'size' => $m->size, 'path' => $path, 'downloaded_at' => now(),
            ]);
        }

        return $draft->load(['site.mahalla', 'media']);
    }

    /** Ko'chirilgan fayllar hashini qaytaradi (agar u boshqa yozuvda ishlatilmayotgan bo'lsa). */
    public static function releaseMovedMedia(int $inspectionId): void
    {
        InspectionMedia::where('inspection_id', $inspectionId)->where('file_unique_id', 'like', '%'.self::MOVED.$inspectionId)->get()
            ->each(function (InspectionMedia $m) use ($inspectionId) {
                $hash = substr($m->file_unique_id, 0, -strlen(self::MOVED.$inspectionId));
                InspectionMedia::where('file_unique_id', $hash)->exists() || $m->update(['file_unique_id' => $hash]);
            });
    }

    public function isRework(): bool
    {
        return (bool) $this->rework_of;
    }

    public function isFollowup(): bool
    {
        return (bool) $this->followup_of;
    }

    public function reworkOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rework_of');
    }

    public function followupOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'followup_of');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(self::class, 'followup_of')->where('status', self::SUBMITTED);
    }

    public function reworks(): HasMany
    {
        return $this->hasMany(self::class, 'rework_of')->where('status', self::SUBMITTED);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(InspectionMedia::class);
    }

    public function mediaOf(string $kind): ?InspectionMedia
    {
        return $this->media->firstWhere('kind', $kind);
    }

    /** Ilova qilingan hujjatlar (sud qarori va h.k.): document_1 ... document_N */
    public function documents(): \Illuminate\Support\Collection
    {
        return $this->media->filter(fn (InspectionMedia $m) => $m->isDocument())->sortBy('id')->values();
    }

    public function isCourtCancelled(): bool
    {
        return $this->exempt_type === self::EXEMPT_COURT;
    }

    /** Moderator tasdig'ini talab qiladigan natija: obyekt holatini o'zgartiradi. */
    public function needsReview(): bool
    {
        // izoh bo'yicha tasdiq obyekt holatini o'zgartirmaydi — moderator uni «Yopish» orqali ko'rib chiqadi
        return ! $this->isFollowup() && ($this->is_demolished === true || $this->isCourtCancelled());
    }
}
