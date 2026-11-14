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
    public const GASTRO = 'gastro_svod';

    /** gastronomik svod: standart jadval (har ish kuni 18:00, faqat joyida tuzilganlar) */
    public const GASTRO_DEFAULTS = ['at' => '18:00', 'days' => [1, 2, 3, 4, 5], 'onsite' => true];

    /** key => [nomi, qachon yuboriladi] (sahifadagi tartib bilan). */
    public static function catalog(): array
    {
        return [
            self::CHECKIN_HOURLY => ['Soatlik qayd — har soat yakuni', 'Har soatlik so‘rov muddati tugaganda (H:59:59): kim qayd etdi, kim javob bermadi. Rasm.'],
            self::CHECKIN_DAILY => ['Soatlik qayd — kun yakuni', 'Oxirgi soat ('.collect(\App\Models\Checkin::slots())->max().') hisobotidan darhol keyin: kun bo‘yi barcha soatlar jadvali. Rasm.'],
            self::ATTENDANCE => ['Ertalabki davomat xulosasi', 'Ish kunlari davomat yopilganda ('.\App\Models\Attendance::closeAt()->format('H:i').'): ishda, sabab bilan yo‘q, javob bermadi (izoh bilan). Rasm.'],
            self::SVOD => ['Kunlik buzilish hisoboti', 'Har kuni '.config('tutash.daily_report_at').' da: tumanlar kesimida svod va bajarilish (2 rasm).'],
            self::GASTRO => ['Gastronomik ko‘chalar — ijara shartnomalari svodi', 'Tanlangan kunlari va vaqtda: tumanlar kesimida shartnomalar soni, bugun tuzilganlar, maydon, oylik va joriy yil to‘lovi. Rasm.'],
        ];
    }

    protected $fillable = ['key', 'enabled', 'recipients', 'options'];

    protected $casts = ['enabled' => 'boolean', 'recipients' => 'array', 'options' => 'array'];

    /** Gastronomik svod jadvali: saqlangan qiymat yoki standart. */
    public static function gastroOptions(): array
    {
        $o = (self::for(self::GASTRO)->options ?? []) + self::GASTRO_DEFAULTS;
        $o['days'] = array_values(array_map('intval', $o['days'] ?? []));
        $o['onsite'] = (bool) $o['onsite'];

        return $o;
    }

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
