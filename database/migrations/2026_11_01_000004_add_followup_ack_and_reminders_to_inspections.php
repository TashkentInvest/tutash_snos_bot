<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moderator izohi nazorati: xodim "Tushundim" bosgan vaqt va eslatmalar (har kuni, izoh bajarilmaguncha).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->timestamp('followup_ack_at')->nullable();
            $table->timestamp('followup_reminded_at')->nullable();
            $table->unsignedSmallInteger('followup_reminders')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropColumn(['followup_ack_at', 'followup_reminded_at', 'followup_reminders']);
        });
    }
};
