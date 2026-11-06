<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alohida yozuv uchun javob muddati (masalan bot xatosi tufayli davomat qayta ochilganda).
 * Bo'sh bo'lsa — umumiy muddat (config tutash.attendance.deadline).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->timestamp('deadline_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('deadline_at');
        });
    }
};
