<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ijara shartnomasini o'chirish (keraksiz / sinov / takror): avval «o'chirilganlar»ga — tiklash mumkin; butunlay o'chirish alohida. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_contracts', fn (Blueprint $t) => $t->softDeletes());
    }

    public function down(): void
    {
        Schema::table('lease_contracts', fn (Blueprint $t) => $t->dropSoftDeletes());
    }
};
