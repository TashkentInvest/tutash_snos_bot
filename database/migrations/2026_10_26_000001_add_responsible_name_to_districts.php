<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NAMES = [
        'Bektemir tumani' => 'Qoraboyev Abduvohid',
        "Mirzo Ulug'bek tumani" => 'Rahimjonov Mahmud',
        'Mirobod tumani' => 'Solijonov Sarvarbek',
        'Olmazor tumani' => "G'afurov Abdumalik",
        'Sergeli tumani' => 'Saydullayev Saydvali',
        'Uchtepa tumani' => "Aribjanov G'ulomjon",
        'Chilonzor tumani' => 'Pirmatov Shohjahon',
        'Shayxontohur tumani' => "Sultanov Bahromxo'ja",
        'Yunusobod tumani' => 'Boqiyev Obidjon',
        'Yakkasaroy tumani' => 'Karimov Ilhom',
        'Yangihayot tumani' => 'Alimov Chori',
        'Yashnobod tumani' => 'Solijonov Sarvarbek',
    ];

    public function up(): void
    {
        Schema::table('districts', function (Blueprint $table) {
            $table->string('responsible_name')->nullable()->after('name');
        });

        foreach (self::NAMES as $district => $name) {
            DB::table('districts')->where('name', $district)->update(['responsible_name' => $name]);
        }
    }

    public function down(): void
    {
        Schema::table('districts', function (Blueprint $table) {
            $table->dropColumn('responsible_name');
        });
    }
};
