<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Botda boshqaruv: panelga kirmasdan botdan hisobot yuborish va xodimlarga xabar / post yozish. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', fn (Blueprint $table) => $table->boolean('bot_manager')->default(false));
    }

    public function down(): void
    {
        Schema::table('staff', fn (Blueprint $table) => $table->dropColumn('bot_manager'));
    }
};
