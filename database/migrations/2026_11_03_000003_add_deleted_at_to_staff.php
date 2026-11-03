<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Xodimni o'chirish — arxivga o'tkazish: natijalar, davomat va videolar saqlanadi, kerak bo'lsa tiklanadi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
