<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable(); // rad etilgan natija arxivlanadi: obyekt sahifasida ko'rinmaydi, arxivda saqlanadi
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
