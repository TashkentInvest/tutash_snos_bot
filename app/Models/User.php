<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
        'is_viewer' => 'boolean',
        'checkins_only' => 'boolean',
        'attendance_only' => 'boolean',
        'leases_only' => 'boolean',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_admin || (bool) $this->is_viewer || (bool) $this->checkins_only || (bool) $this->attendance_only || (bool) $this->leases_only;
    }

    /** Joriy foydalanuvchi faqat «Soatlik qayd» jadvalini ko'radi (boshqa bo'limlar yopiq, amallar yo'q). */
    public static function currentIsCheckinsOnly(): bool
    {
        $u = auth()->user();

        return $u && ! $u->is_admin && (bool) $u->checkins_only;
    }

    /** Faqat «Ertalabki davomat» (ko'rish, videolar). */
    public static function currentIsAttendanceOnly(): bool
    {
        $u = auth()->user();

        return $u && ! $u->is_admin && (bool) $u->attendance_only;
    }

    /** Faqat «Ijara shartnomalari»: ko'rish, xatoni tuzatish, Word yuklab olish; o'chira olmaydi. */
    public static function currentIsLeasesOnly(): bool
    {
        $u = auth()->user();

        return $u && ! $u->is_admin && (bool) $u->leases_only;
    }

    /** Bitta bo'lim bilan cheklangan foydalanuvchi (bosh sahifa, obyektlar, tekshiruvlar unga yopiq). */
    public static function currentIsLimited(): bool
    {
        return self::currentIsCheckinsOnly() || self::currentIsAttendanceOnly() || self::currentIsLeasesOnly();
    }

    /** Joriy foydalanuvchi to'liq admin (kuzatuvchi emas) — boshqaruv amallari, davomat va sozlamalar faqat unga. */
    public static function currentIsAdmin(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }
}
