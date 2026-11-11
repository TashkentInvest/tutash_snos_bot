<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kadastr xodimi botdan obyekt qo'shadi: admin tasdiqlaguncha `approval = pending` (ro'yxat, svod va boshqa xodimlarga ko'rinmaydi),
 * rad etilsa — `rejected` + sabab. Tasdiqlangan va avvalgi barcha obyektlar: `approval = null`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('approval', 12)->nullable()->index();
            $table->foreignId('added_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->text('approval_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_by_staff_id');
            $table->dropColumn(['approval', 'approval_note']);
        });
    }
};
