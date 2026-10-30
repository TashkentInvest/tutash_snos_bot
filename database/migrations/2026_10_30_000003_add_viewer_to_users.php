<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Kuzatuvchi: admin panelda faqat obyektlar ma'lumotini ko'radi (tahrirlash, tasdiqlash, davomat yo'q)
            $table->boolean('is_viewer')->default(false)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_viewer');
        });
    }
};
