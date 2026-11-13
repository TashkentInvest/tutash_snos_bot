<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tumanlarning haqiqiy chegaralari (poligon): davomat «faqat o'z tumanidan» shu bo'yicha tekshiriladi va xaritada chiziladi.
 * Manba: database/data/district-boundaries.json — 10 tuman foydalanuvchi bergan KMZ, Olmazor va Yashnobod NGIS xaritasidan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('districts', fn (Blueprint $table) => $table->json('boundary')->nullable());

        $file = database_path('data/district-boundaries.json');
        if (! is_file($file)) {
            return;
        }
        foreach (json_decode(file_get_contents($file), true) as $name => $data) {
            DB::table('districts')->where('name', $name)->update(['boundary' => json_encode($data['rings'])]);
        }
    }

    public function down(): void
    {
        Schema::table('districts', fn (Blueprint $table) => $table->dropColumn('boundary'));
    }
};
