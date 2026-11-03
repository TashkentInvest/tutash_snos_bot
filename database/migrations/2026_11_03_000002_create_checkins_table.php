<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shartnoma asosida ishlaydigan xodimlar: ertalabki davomatdan keyin ish kuni davomida har soatda
 * joylashuv va video so'raladi (HR uchun: qachon va qayerda bo'lgani).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->boolean('is_contract')->default(false)->after('receives_reports');
        });

        Schema::create('checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->date('date');
            $table->string('slot', 5); // "10:00"
            $table->string('status', 12)->default('sent'); // sent | recording | present | missed
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('deadline_at');
            $table->timestamp('responded_at')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->double('lat')->nullable();
            $table->double('lng')->nullable();
            $table->unsignedInteger('nearest_site_m')->nullable();
            $table->json('flags')->nullable();
            $table->string('video_path')->nullable();
            $table->string('face_video_path')->nullable();
            $table->string('video_unique_id')->nullable()->unique();
            $table->unsignedSmallInteger('video_duration')->nullable();
            $table->text('device')->nullable();
            $table->timestamps();

            $table->unique(['staff_id', 'date', 'slot']);
            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkins');
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('is_contract');
        });
    }
};
