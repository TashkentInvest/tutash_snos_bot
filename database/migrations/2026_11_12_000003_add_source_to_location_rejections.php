<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** «Xatolikka uchraganlar»: tekshiruvdan tashqari davomat va soatlik qaydda tumandan tashqaridan yuborilganlar ham. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_rejections', function (Blueprint $table) {
            $table->string('source', 12)->default('inspection')->index(); // inspection | attendance | checkin
            $table->string('note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('location_rejections', fn (Blueprint $table) => $table->dropColumn(['source', 'note']));
    }
};
