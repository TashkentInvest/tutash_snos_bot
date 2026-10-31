<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bitta tumanga bir nechta xodim: asosiy mas'ul telefonidan tashqari qo'shimcha telefonlar (admin kiritadi). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('district_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 9)->index();
            $table->string('name', 100)->nullable();
            $table->timestamps();
            $table->unique(['district_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('district_phones');
    }
};
