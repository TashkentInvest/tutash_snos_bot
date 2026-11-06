<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Hisobot sozlamasi: yoqilganmi va kimlarga boradi (admin panel → Sozlamalar → Hisobotlar).
 * Avtomatik yuborishda `enabled` tekshiriladi; qo'lda (dashboard tugmasi) yuborishda faqat oluvchilar olinadi.
 */
class ReportSetting extends Model
{
    public const SVOD = 'svod';
    public const ATTENDANCE = 'attendance';
    public const CHECKIN_HOURLY = 'checkin_hourly';
    public const CHECKIN_DAILY = 'checkin_daily';

    /** key => [nomi, qachon yuboriladi] (sahifadagi tartib bilan). */
    public static function catalog(): array
    {
        return [
            self::CHECKIN_HOURLY => ['Soatlik qayd — har soat yakuni', 'Har soatlik so‘rov muddati tugaganda (H:59:59): kim qayd etdi, kim javob bermadi. Rasm.'],
            self::CHECKIN_DAILY => ['Soatlik qayd — kun yakuni', 'Ish kunlari '.config('tutash.checkins.daily_report_at').':59 da: kun bo‘yi barcha soatlar jadvali. Rasm.'],
            self::ATTENDANCE => ['Ertalabki davomat xulosasi', 'Ish kunlari davomat yopilganda ('.\App\Models\Attendance::closeAt()->format('H:i').'): ishda, sabab bilan yo‘q, javob bermadi.'],
            self::SVOD => ['Kunlik buzilish hisoboti', 'Har kuni '.config('tutash.daily_report_at').' da: tumanlar kesimida svod va bajarilish (2 rasm).'],
        ];
    }

    protected $fillable = ['key', 'enabled', 'recipients'];

    protected $casts = ['enabled' => 'boolean', 'recipients' => 'array'];

    public static function for(string $key): self
    {
        return self::firstOrCreate(['key' => $key], ['enabled' => true, 'recipients' => []]);
    }

    public static function enabled(string $key): bool
    {
        return self::for($key)->enabled;
    }

    /** @return Collection<int, Staff> botda faol oluvchilar (botni bloklagan bo'lsa ham — yuborishda aniqlanadi) */
    public static function recipients(string $key): Collection
    {
        $ids = array_map('intval', self::for($key)->recipients ?? []);

        return $ids ? Staff::whereIn('id', $ids)->where('status', Staff::ACTIVE)->whereNotNull('telegram_id')->orderBy('full_name')->get() : collect();
    }
}
