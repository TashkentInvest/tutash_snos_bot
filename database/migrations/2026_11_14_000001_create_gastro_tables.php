<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gastronomik va turizm ko'chalari (ijara shartnomalari shu ko'chalarda tuziladi) va har tuman bo'yicha shartnoma mas'uli.
 * Mas'ullar xodimning «Tumanlar»idan ALOHIDA: bu yerda o'zgartirish davomat/obyektlarga ta'sir qilmaydi.
 * Boshlang'ich ma'lumot: database/data/gastro-streets.json (KMZ: Abduraxim chizgan xarita + Olmazor) va «Shartnoma tushum» jadvali.
 */
return new class extends Migration
{
    /** «Shartnoma tushum» jadvali: tuman => mas'ul telefoni */
    private const RESPONSIBLES = [
        "Mirzo Ulug'bek tumani" => '943340333', 'Yunusobod tumani' => '943340333',
        'Chilonzor tumani' => '950507174', 'Uchtepa tumani' => '950507174',
        'Shayxontohur tumani' => '978981899', 'Olmazor tumani' => '978981899',
        'Sergeli tumani' => '974766464', 'Yangihayot tumani' => '974766464',
        'Yashnobod tumani' => '942778855', 'Bektemir tumani' => '942778855',
        'Yakkasaroy tumani' => '993313441', 'Mirobod tumani' => '993313441',
    ];

    public function up(): void
    {
        Schema::create('gastro_streets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->json('line'); // [[lat, lng], ...]
            $table->timestamps();
        });

        Schema::create('gastro_responsibles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('phone', 20)->nullable(); // botga hali kirmagan bo'lsa — raqami
            $table->timestamps();
        });

        $districts = DB::table('districts')->pluck('id', 'name');
        $file = database_path('data/gastro-streets.json');
        if (is_file($file)) {
            foreach (json_decode(file_get_contents($file), true) as $name => $streets) {
                if (! isset($districts[$name])) {
                    continue;
                }
                foreach ($streets as $s) {
                    DB::table('gastro_streets')->insert(['district_id' => $districts[$name], 'name' => $s['name'],
                        'line' => json_encode($s['line']), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        foreach ($districts as $name => $id) {
            $phone = self::RESPONSIBLES[$name] ?? null;
            DB::table('gastro_responsibles')->insert(['district_id' => $id, 'phone' => $phone,
                'staff_id' => $phone ? DB::table('staff')->where('phone', $phone)->whereNull('deleted_at')->value('id') : null,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gastro_responsibles');
        Schema::dropIfExists('gastro_streets');
    }
};
