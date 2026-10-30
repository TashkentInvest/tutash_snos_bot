<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            // "Jarayonda": obyekt qisman buzilgan, buzish davom etmoqda (is_demolished = false)
            $table->boolean('in_progress')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropColumn('in_progress');
        });
    }
};
