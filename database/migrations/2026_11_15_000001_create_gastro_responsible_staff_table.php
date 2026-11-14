<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bir tumanga bir nechta shartnoma mas'uli (xodimlarni tumanlarga biriktirgan kabi). Avvalgi yagona
 * gastro_responsibles.staff_id shu jadvalga ko'chiriladi; o'sha ustun endi ishlatilmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gastro_responsible_staff')) {
            Schema::create('gastro_responsible_staff', function (Blueprint $table) {
                $table->foreignId('gastro_responsible_id')->constrained()->cascadeOnDelete();
                $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
                $table->primary(['gastro_responsible_id', 'staff_id']);
            });
        }

        $rows = DB::table('gastro_responsibles')->whereNotNull('staff_id')->get(['id', 'staff_id']);
        foreach ($rows as $r) {
            DB::table('gastro_responsible_staff')->insertOrIgnore(['gastro_responsible_id' => $r->id, 'staff_id' => $r->staff_id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gastro_responsible_staff');
    }
};
