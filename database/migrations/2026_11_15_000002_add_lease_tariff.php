<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tutash hudud ijarasi: tuman bo'yicha 1 m² uchun OYLIK tarif (so'm). Shartnoma to'lovi = maydon × tarif × oylar
 * (boshlanish oyidan dekabrgacha). Namunada (RONI1, Mirobod): 58,5 m² × 62 073,19 ≈ 3 631 280 so'm/oy.
 * Shartnomada tuzilgan paytdagi tarif saqlanadi (keyin tarif o'zgarsa ham eski shartnoma o'zgarmaydi).
 */
return new class extends Migration
{
    private const TARIFFS = [
        'Yakkasaroy tumani' => 60669.56, 'Mirobod tumani' => 62073.19, "Mirzo Ulug'bek tumani" => 60388.84,
        'Chilonzor tumani' => 37101.44, 'Yunusobod tumani' => 37286.85, 'Shayxontohur tumani' => 49234.47,
        'Yashnobod tumani' => 62976.78, 'Olmazor tumani' => 47450.67, 'Uchtepa tumani' => 36698.67,
        'Sergeli tumani' => 47450.67, 'Yangihayot tumani' => 24378.67, 'Bektemir tumani' => 36698.67,
    ];

    public function up(): void
    {
        Schema::table('gastro_responsibles', fn (Blueprint $t) => $t->decimal('tariff', 14, 2)->nullable());
        Schema::table('lease_contracts', fn (Blueprint $t) => $t->decimal('tariff', 14, 2)->nullable());

        foreach (DB::table('districts')->pluck('id', 'name') as $name => $id) {
            if (isset(self::TARIFFS[$name])) {
                DB::table('gastro_responsibles')->where('district_id', $id)->update(['tariff' => self::TARIFFS[$name]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('gastro_responsibles', fn (Blueprint $t) => $t->dropColumn('tariff'));
        Schema::table('lease_contracts', fn (Blueprint $t) => $t->dropColumn('tariff'));
    }
};
