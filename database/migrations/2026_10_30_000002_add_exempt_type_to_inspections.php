<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            // "Buzilmaydi": court_cancelled — sud qarori bekor qilingan, other — boshqa sabab (izoh + hujjatlar ilova qilinadi)
            $table->string('exempt_type', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropColumn('exempt_type');
        });
    }
};
