<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ijara shartnomalari (gastronomik ko'chalardagi tutash hudud): shartnoma mas'uli botda to'ldiradi, tizim namunadan
 * Word (va imkon bo'lsa PDF) yasaydi — chop etilib, tomonlar o'zlari imzolaydi (tasdiq yo'q, faqat hisob uchun saqlanadi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained();
            $table->foreignId('gastro_street_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('party', 12); // legal | individual
            $table->string('tenant_name');
            $table->string('tenant_address', 500)->nullable();
            $table->string('director', 150)->nullable();       // yuridik: direktor F.I.SH
            $table->string('account', 40)->nullable();         // x/r
            $table->text('inn')->nullable();                    // STIR (shifrlangan)
            $table->string('mfo', 10)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('passport')->nullable();               // jismoniy (shifrlangan)
            $table->string('passport_issuer', 200)->nullable();
            $table->string('passport_year', 10)->nullable();
            $table->text('jshshir')->nullable();                // shifrlangan
            $table->string('mfy', 150);
            $table->string('house', 100)->nullable();
            $table->string('cadastre', 60);
            $table->decimal('area_m2', 10, 2);
            $table->string('activity', 150);
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('annual_amount', 16, 2);
            $table->string('application_number', 40)->nullable();
            $table->date('application_date')->nullable();
            $table->double('lat');
            $table->double('lng');
            $table->integer('street_distance_m')->nullable();
            $table->string('docx_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamps();
            $table->index(['district_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_contracts');
    }
};
