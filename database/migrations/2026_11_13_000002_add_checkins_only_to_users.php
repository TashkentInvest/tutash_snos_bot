<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Web panel foydalanuvchisi faqat «Soatlik qayd» jadvalini (va qaydlar ro'yxatini) ko'radi — boshqa bo'limlar yopiq. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('checkins_only')->default(false)->after('is_viewer'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('checkins_only'));
    }
};
