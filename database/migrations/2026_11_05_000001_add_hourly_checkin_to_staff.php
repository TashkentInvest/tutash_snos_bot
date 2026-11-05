<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ikkinchi vazifa: oddiy xodim (obyektlar bilan ishlaydi) ham har soatlik qaydga qo'shilishi mumkin.
 * Shartnoma xodimi uchun soatlik qayd har doim yoqilgan (rol bo'yicha), bu belgi faqat "Xodim" roli uchun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->boolean('hourly_checkin')->default(false);
        });
        Schema::table('staff_invites', function (Blueprint $table) {
            $table->boolean('hourly_checkin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('hourly_checkin');
        });
        Schema::table('staff_invites', function (Blueprint $table) {
            $table->dropColumn('hourly_checkin');
        });
    }
};
