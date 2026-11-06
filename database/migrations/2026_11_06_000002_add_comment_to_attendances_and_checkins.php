<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Davomat va soatlik qayd: joylashuv → video → izoh (majburiy). Izoh — xodim qayerda va nima qilayotgani
 * (masalan "ofisdaman, yig'ilishdaman"), moderator videoni tushunishi uchun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->text('comment')->nullable();
        });
        Schema::table('checkins', function (Blueprint $table) {
            $table->text('comment')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('comment');
        });
        Schema::table('checkins', function (Blueprint $table) {
            $table->dropColumn('comment');
        });
    }
};
