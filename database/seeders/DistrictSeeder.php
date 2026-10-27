<?php

namespace Database\Seeders;

use App\Models\District;
use Illuminate\Database\Seeder;

class DistrictSeeder extends Seeder
{
    /** Tuman => mas'ul xodim telefoni (oxirgi 9 raqam). Manba: mas'ul xodimlar jadvali (Solijonov Sarvarbek — Yashnobod va Mirobod). */
    public const PHONES = [
        'Bektemir tumani' => '938068758',
        "Mirzo Ulug'bek tumani" => '997610700',
        'Mirobod tumani' => '930010301',
        'Olmazor tumani' => '778788228',
        'Sergeli tumani' => '992340101',
        'Uchtepa tumani' => '931619099',
        'Chilonzor tumani' => '974100313',
        'Shayxontohur tumani' => '909380444',
        'Yunusobod tumani' => '888665544',
        'Yakkasaroy tumani' => '990043006',
        'Yangihayot tumani' => '870429191',
        'Yashnobod tumani' => '930010301',
    ];

    public const NAMES = [
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

    public function run(): void
    {
        foreach (self::PHONES as $name => $phone) {
            District::updateOrCreate(['name' => $name], ['phone' => $phone, 'responsible_name' => self::NAMES[$name] ?? null]);
        }
    }
}
