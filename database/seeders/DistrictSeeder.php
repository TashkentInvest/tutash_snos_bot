<?php

namespace Database\Seeders;

use App\Models\District;
use Illuminate\Database\Seeder;

class DistrictSeeder extends Seeder
{
    /** Tuman => mas'ul xodim telefoni (oxirgi 9 raqam). Manba: mas'ul xodimlar jadvali (2026-10-29). Bir xodim bir nechta tumanga qarashi mumkin (Sanjar, Azim, Ibroxim). */
    public const PHONES = [
        'Bektemir tumani' => '942778855',
        "Mirzo Ulug'bek tumani" => '943340333',
        'Mirobod tumani' => '977009773',
        'Olmazor tumani' => '908148977',
        'Sergeli tumani' => '974766464',
        'Uchtepa tumani' => '901210333',
        'Chilonzor tumani' => '950507174',
        'Shayxontohur tumani' => '978981899',
        'Yunusobod tumani' => '943340333',
        'Yakkasaroy tumani' => '977009773',
        'Yangihayot tumani' => '974766464',
        'Yashnobod tumani' => '974123335',
    ];

    public const NAMES = [
        'Bektemir tumani' => 'Sherzod',
        "Mirzo Ulug'bek tumani" => 'Choriyev Sanjar',
        'Mirobod tumani' => 'Xamidov Ibroxim',
        'Olmazor tumani' => 'Ortikov Otabek',
        'Sergeli tumani' => 'Isroilov Azimjon',
        'Uchtepa tumani' => 'Mirodilov Mirkamol',
        'Chilonzor tumani' => 'Sanjar 2',
        'Shayxontohur tumani' => 'Shernafasov Shoxzod',
        'Yunusobod tumani' => 'Choriyev Sanjar',
        'Yakkasaroy tumani' => 'Xamidov Ibroxim',
        'Yangihayot tumani' => 'Isroilov Azimjon',
        'Yashnobod tumani' => 'Xasan',
    ];

    public function run(): void
    {
        foreach (self::PHONES as $name => $phone) {
            District::updateOrCreate(['name' => $name], ['phone' => $phone, 'responsible_name' => self::NAMES[$name] ?? null]);
        }
    }
}
