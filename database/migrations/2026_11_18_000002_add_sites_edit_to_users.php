<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cheklangan foydalanuvchiga qo'shimcha: obyektlarni ko'rish va tahrirlash (qo'shish / o'chirish — yo'q). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('sites_edit')->default(false));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('sites_edit'));
    }
};
