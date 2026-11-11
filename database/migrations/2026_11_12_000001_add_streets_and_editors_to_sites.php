<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kadastr Mini App: ko'chalar ro'yxati (qidiriladi, yo'q bo'lsa xodim qo'shadi), kim qo'shgani (MFY, ko'cha)
 * va obyektni oxirgi marta kim tahrirlagani.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            $table->unique(['district_id', 'name']);
        });

        Schema::table('mahallas', function (Blueprint $table) {
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('street_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('street_id');
            $table->dropConstrainedForeignId('updated_by_staff_id');
        });
        Schema::table('mahallas', fn (Blueprint $table) => $table->dropConstrainedForeignId('created_by_staff_id'));
        Schema::dropIfExists('streets');
    }
};
