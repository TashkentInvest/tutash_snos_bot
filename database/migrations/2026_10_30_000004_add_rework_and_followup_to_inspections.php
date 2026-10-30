<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moderator izohi bo'yicha qayta ishlash:
 *  - rework_of: rad etilgan natijani tuzatib qayta yuborish (avvalgi rasm/videolar ko'chiriladi, keraklilari qayta olinadi);
 *  - followup_of: tasdiqlangan natija bo'yicha izoh ("oxiriga yetkazing") bajarilganini rasm/video bilan tasdiqlash;
 *  - followup: izoh holati — open (bajarilmagan), done (xodim bajardi, tekshirish kerak), closed (moderator yopdi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->foreignId('rework_of')->nullable()->constrained('inspections')->nullOnDelete();
            $table->foreignId('followup_of')->nullable()->constrained('inspections')->nullOnDelete();
            $table->string('edit_target', 20)->nullable();
            $table->boolean('rework_located')->default(false);
            $table->string('followup', 10)->nullable()->index();
            $table->timestamp('followup_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rework_of');
            $table->dropConstrainedForeignId('followup_of');
            $table->dropColumn(['edit_target', 'rework_located', 'followup', 'followup_at']);
        });
    }
};
