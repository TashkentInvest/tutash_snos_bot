<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Botdagi postlar (bosh sahifa → «Post yuborish»): rasm / video va matn, tanlangan bo'limlar (ixtiyoriy aholi ham).
 * Har oluvchi bo'yicha: yetkazildimi, «Tanishdim» bosgan vaqti — panelda statistika.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->text('caption')->nullable();
            $table->string('media_type', 10)->nullable(); // photo | video
            $table->string('media_path')->nullable();
            $table->string('file_id')->nullable(); // Telegram'ga bir marta yuklanadi, keyin qayta ishlatiladi
            $table->json('audience')->nullable(); // bo'limlar, tumanlar
            $table->boolean('ack_button')->default(true);
            $table->string('status', 12)->default('sending'); // sending | sent
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('delivered')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('post_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('status', 10)->default('pending'); // pending | sent | failed
            $table->bigInteger('message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('ack_at')->nullable(); // «Tanishdim»
            $table->string('error', 255)->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'staff_id']);
            $table->index(['post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_deliveries');
        Schema::dropIfExists('posts');
    }
};
