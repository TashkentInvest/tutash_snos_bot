<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Mirobod tumani: yangi KMZ (MIROBOD TUMANI_1.kmz) dagi qo'shimcha gastronomik ko'chalar — Fidokor va Said Baraka.
 * Taras Shevchenko chizig'i o'zgarmagan. Nomi bo'yicha bor bo'lsa qo'shilmaydi (qayta ishga tushsa ham dublikat yo'q).
 */
return new class extends Migration
{
    private const DISTRICT = 'Mirobod tumani';

    private const NAMES = ['Fidokor', 'Said Baraka'];

    public function up(): void
    {
        $district = DB::table('districts')->where('name', self::DISTRICT)->value('id');
        if (! $district) {
            return;
        }
        $streets = collect(json_decode(file_get_contents(database_path('data/gastro-streets.json')), true)[self::DISTRICT] ?? [])
            ->whereIn('name', self::NAMES);
        foreach ($streets as $s) {
            if (DB::table('gastro_streets')->where('district_id', $district)->where('name', $s['name'])->exists()) {
                continue;
            }
            DB::table('gastro_streets')->insert(['district_id' => $district, 'name' => $s['name'],
                'line' => json_encode($s['line']), 'created_at' => now(), 'updated_at' => now()]);
        }
        \Illuminate\Support\Facades\Cache::forget('gastro-lines'); // obyekt xaritalaridagi qatlam darhol yangilansin
    }

    public function down(): void
    {
        $district = DB::table('districts')->where('name', self::DISTRICT)->value('id');
        $district && DB::table('gastro_streets')->where('district_id', $district)->whereIn('name', self::NAMES)
            ->whereNotExists(fn ($q) => $q->from('lease_contracts')->whereColumn('lease_contracts.gastro_street_id', 'gastro_streets.id'))
            ->delete();
    }
};
