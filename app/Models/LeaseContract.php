<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ijara shartnomasi (gastronomik ko'chadagi tutash hudud) — namunadan yasalgan Word/PDF; tasdiq yo'q, hisob uchun saqlanadi. */
class LeaseContract extends Model
{
    public const LEGAL = 'legal';
    public const INDIVIDUAL = 'individual';

    public const PARTIES = [self::LEGAL => 'Yuridik shaxs', self::INDIVIDUAL => 'Jismoniy shaxs'];

    /** Faoliyat turlari (shartnomaning 1.3 va 2.7-bandlari) — ro'yxatda yo'g'ini xodim o'zi yozadi. */
    public const ACTIVITIES = ['Umumiy ovqatlanish', 'Savdo', 'Xizmat ko‘rsatish'];

    protected $fillable = [
        'district_id', 'gastro_street_id', 'staff_id', 'party', 'tenant_name', 'tenant_address', 'director', 'account', 'inn', 'mfo',
        'phone', 'passport', 'passport_issuer', 'passport_year', 'jshshir', 'mfy', 'house', 'cadastre', 'area_m2', 'activity',
        'start_date', 'end_date', 'annual_amount', 'application_number', 'application_date', 'lat', 'lng', 'street_distance_m',
        'docx_path', 'pdf_path', 'gps_accuracy', 'polygon', 'polygon_area_m2', 'photos',
    ];

    public const PHOTOS = 4; // joyida, kameradan — majburiy

    protected $casts = [
        'inn' => 'encrypted', 'passport' => 'encrypted', 'jshshir' => 'encrypted',
        'start_date' => 'date', 'end_date' => 'date', 'application_date' => 'date',
        'area_m2' => 'float', 'annual_amount' => 'float', 'lat' => 'float', 'lng' => 'float',
        'polygon' => 'array', 'photos' => 'array', 'polygon_area_m2' => 'float', 'gps_accuracy' => 'float',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function street(): BelongsTo
    {
        return $this->belongsTo(GastroStreet::class, 'gastro_street_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    /**
     * To'lov jadvali: joriy yil to'lovi boshlanish oyidan dekabrgacha teng bo'linadi (namunadagi kabi:
     * 21 787 682,67 / 6 oy = 3 631 280,44). Tiyin farqi oxirgi oyga qo'shiladi.
     * @return list<array{0: int, 1: float}> [oy raqami, summa]
     */
    public function schedule(): array
    {
        $months = range($this->start_date->month, 12);
        $each = floor($this->annual_amount / count($months) * 100) / 100;
        $rows = array_map(fn ($m) => [$m, $each], $months);
        $rows[count($rows) - 1][1] = round($this->annual_amount - $each * (count($months) - 1), 2);

        return $rows;
    }
}
