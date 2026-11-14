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
        'docx_path', 'pdf_path', 'gps_accuracy', 'polygon', 'polygon_area_m2', 'photos', 'tariff',
    ];

    /**
     * To'lov: 1 m² oylik tarif × maydon = oylik to'lov. Birinchi oy — kunlar bo'yicha: boshlanish kunidan oy oxirigacha
     * (boshlanish kuni ham kiradi; 10-noyabr → 21/30 oy; 1-sanada — to'liq oy). Keyingi oylar — to'liq.
     * Joriy yil to'lovi = birinchi oy + oylik × (dekabrgacha qolgan oylar).
     * @return array{monthly: float, months: int, first: float, first_days: int, days_in_month: int, annual: float}
     */
    public static function amounts(float $area, float $tariff, \DateTimeInterface $start): array
    {
        $monthly = round($area * $tariff, 2);
        $d = \Carbon\CarbonImmutable::instance($start);
        $dim = $d->daysInMonth;
        $days = $dim - $d->day + 1;
        $first = $days === $dim ? $monthly : round($monthly * $days / $dim, 2);
        $months = 13 - $d->month;

        return ['monthly' => $monthly, 'months' => $months, 'first' => $first, 'first_days' => $days, 'days_in_month' => $dim,
            'annual' => round($first + $monthly * ($months - 1), 2)];
    }

    public const PHOTOS = 4; // joyida, kameradan — majburiy

    protected $casts = [
        'inn' => 'encrypted', 'passport' => 'encrypted', 'jshshir' => 'encrypted',
        'start_date' => 'date', 'end_date' => 'date', 'application_date' => 'date',
        'area_m2' => 'float', 'annual_amount' => 'float', 'lat' => 'float', 'lng' => 'float',
        'polygon' => 'array', 'photos' => 'array', 'polygon_area_m2' => 'float', 'gps_accuracy' => 'float', 'tariff' => 'float',
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
     * Tarif bo'yicha: birinchi oy kunlar bo'yicha (to'liq bo'lmasa 3-element — kunlar soni), qolganlari maydon × tarif.
     * @return list<array{0: int, 1: float, 2?: int}> [oy raqami, summa, (kunlar)]
     */
    public function schedule(): array
    {
        $months = range($this->start_date->month, 12);
        if ($this->tariff) {
            $a = self::amounts($this->area_m2, $this->tariff, $this->start_date);

            return array_map(fn ($m) => $m === $this->start_date->month && $a['first_days'] < $a['days_in_month']
                ? [$m, $a['first'], $a['first_days']]
                : [$m, $a['monthly']], $months);
        }
        $each = floor($this->annual_amount / count($months) * 100) / 100;
        $rows = array_map(fn ($m) => [$m, $each], $months);
        $rows[count($rows) - 1][1] = round($this->annual_amount - $each * (count($months) - 1), 2);

        return $rows;
    }
}
