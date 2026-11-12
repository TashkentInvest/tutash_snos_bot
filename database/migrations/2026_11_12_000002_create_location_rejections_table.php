<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tekshiruvda joylashuvi qabul qilinmagan urinishlar (obyektdan uzoq yoki GPS aniqligi past):
 * xodim yuborgan nuqta va o'sha paytdagi obyekt nuqtasi — admin xaritada farqini ko'radi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inspection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 16); // too_far | low_accuracy
            $table->double('lat');
            $table->double('lng');
            $table->double('accuracy')->nullable();
            $table->double('site_lat')->nullable();
            $table->double('site_lng')->nullable();
            $table->integer('distance_m')->nullable();
            $table->text('device')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_rejections');
    }
};
