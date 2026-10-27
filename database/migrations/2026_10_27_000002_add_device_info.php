<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['attendances', 'inspections'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->text('device')->nullable(); // qurilma ma'lumoti (JSON): brauzer, Telegram versiyasi, kamera
            });
        }
    }

    public function down(): void
    {
        foreach (['attendances', 'inspections'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('device');
            });
        }
    }
};
