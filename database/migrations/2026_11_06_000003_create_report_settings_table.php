<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hisobotlar sozlamasi (admin panel → Sozlamalar → Hisobotlar): har bir hisobot yoqiladi/o'chiriladi va
 * oluvchilari alohida belgilanadi. Avvalgi «Hisobot oladi» (staff.receives_reports) belgilari ko'chiriladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->boolean('enabled')->default(true);
            $table->json('recipients')->nullable(); // staff id lari
            $table->timestamps();
        });

        $old = DB::table('staff')->where('receives_reports', true)->whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        foreach (['svod', 'attendance', 'checkin_hourly', 'checkin_daily'] as $key) {
            DB::table('report_settings')->insert([
                'key' => $key, 'enabled' => true, 'recipients' => json_encode($old), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_settings');
    }
};
