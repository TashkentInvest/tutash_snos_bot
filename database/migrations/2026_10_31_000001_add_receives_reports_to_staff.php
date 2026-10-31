<?php

use App\Models\Staff;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Kunlik hisobotlarni oluvchilar admin paneldan belgilanadi (avval ATTENDANCE_REPORT_PHONES orqali edi). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->boolean('receives_reports')->default(false)->after('attendance_required');
        });

        // hozirgi oluvchilar saqlanadi: .env dagi telefonlar (config kesh bo'lsa ham config() orqali o'qiladi)
        $phones = array_values(array_filter(array_map([Staff::class, 'normalizePhone'], explode(',', (string) config('tutash.attendance.report_phones')))));
        $phones && DB::table('staff')->whereIn('phone', $phones)->update(['receives_reports' => true]);
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('receives_reports');
        });
    }
};
