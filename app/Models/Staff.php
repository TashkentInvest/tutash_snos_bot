<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Staff extends Model
{
    public const REGISTERING = 'registering';
    public const AWAITING_PHONE = 'awaiting_phone';
    public const ACTIVE = 'active';
    public const REJECTED = 'rejected';
    public const BLOCKED = 'blocked';
    public const CITIZEN = 'citizen'; // aholi sifatida kirgan (xodimlik huquqi yo'q)

    public const ROLE_EMPLOYEE = 'employee';
    public const ROLE_CITIZEN = 'citizen';

    protected $table = 'staff';

    protected $fillable = ['telegram_id', 'username', 'full_name', 'phone', 'status', 'role', 'is_global', 'attendance_required', 'receives_reports', 'failed_attempts', 'registered_at', 'last_seen_at'];

    protected $casts = ['is_global' => 'boolean', 'attendance_required' => 'boolean', 'receives_reports' => 'boolean', 'registered_at' => 'datetime', 'last_seen_at' => 'datetime'];

    public function districts(): BelongsToMany
    {
        return $this->belongsToMany(District::class, 'district_staff');
    }

    /** Barcha tumanlarni ko'ra oladigan xodimlar (is_global) yoki biriktirilgan tumanlar. */
    public function accessibleDistricts(): \Illuminate\Database\Eloquent\Builder|BelongsToMany
    {
        return ($this->is_global || $this->isCitizen()) ? District::query() : $this->districts();
    }

    /** ALL_DISTRICTS_PHONES (.env, vergul bilan) — barcha tumanlarni ko'rish huquqi. */
    public static function isGlobalPhone(?string $phone): bool
    {
        $list = array_map([self::class, 'normalizePhone'], explode(',', (string) config('tutash.all_districts_phones')));

        return $phone !== null && in_array($phone, array_filter($list), true);
    }

    /** ALL_DISTRICTS_EMPLOYEE_PHONES — admin emas, lekin barcha tumanlarga biriktiriladigan xodimlar (davomat yuritiladi). */
    public static function isAllDistrictsEmployeePhone(?string $phone): bool
    {
        $list = array_map([self::class, 'normalizePhone'], explode(',', (string) config('tutash.all_districts_employee_phones')));

        return $phone !== null && in_array($phone, array_filter($list), true);
    }

    /**
     * Tuman mas'ul telefoni bo'yicha ro'yxatdan o'tgan xodimlarning tumanlarini qayta bog'laydi
     * (admin tuman telefonini o'zgartirganda). Barcha tumanlarga biriktirilganlar va global adminlarga tegilmaydi.
     */
    public static function resyncDistrictsByPhone(array $phones): void
    {
        $phones = array_values(array_filter($phones));
        if ($phones === []) {
            return;
        }

        self::where('status', self::ACTIVE)->where('role', self::ROLE_EMPLOYEE)->where('is_global', false)
            ->whereIn('phone', $phones)->get()
            ->reject(fn (Staff $s) => self::isAllDistrictsEmployeePhone($s->phone))
            ->each(fn (Staff $s) => $s->districts()->sync(District::where('phone', $s->phone)->pluck('id')));
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function isCitizen(): bool
    {
        return $this->status === self::CITIZEN;
    }

    /** Botda ko'rish huquqi bor: tasdiqlangan xodim yoki aholi. */
    public function canBrowse(): bool
    {
        return $this->isActive() || $this->isCitizen();
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function canAccessDistrict(int $districtId): bool
    {
        return $this->accessibleDistricts()->whereKey($districtId)->exists();
    }

    /** Telefonni oxirgi 9 raqamga keltiradi: "+998 99 201 22 72" -> "992012272". */
    public static function normalizePhone(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);

        return strlen($digits) >= 9 ? substr($digits, -9) : null;
    }
}
