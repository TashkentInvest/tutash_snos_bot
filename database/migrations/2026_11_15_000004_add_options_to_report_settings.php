<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Hisobotning qo'shimcha sozlamalari (masalan gastronomik svod: yuborish vaqti, kunlari, faqat joyida tuzilganlar). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_settings', fn (Blueprint $t) => $t->json('options')->nullable());
    }

    public function down(): void
    {
        Schema::table('report_settings', fn (Blueprint $t) => $t->dropColumn('options'));
    }
};
