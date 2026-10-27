<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('role', 16)->nullable()->after('status'); // employee | citizen | null (hali tanlanmagan)
            $table->boolean('attendance_required')->default(true)->after('is_global');
        });

        // Hozirgi barcha foydalanuvchilar — xodim; barcha tumanlarni ko'radigan (admin) raqamlarda davomat yuritilmaydi.
        DB::table('staff')->update(['role' => 'employee']);
        DB::table('staff')->where('is_global', true)->update(['attendance_required' => false]);

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->date('date');
            // sent -> locating -> recording -> present | sent -> reasoning -> absent | missed
            $table->string('status', 16)->default('sent');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->double('lat')->nullable();
            $table->double('lng')->nullable();
            $table->text('reason')->nullable();
            $table->string('video_file_id')->nullable();
            $table->string('video_unique_id')->nullable()->unique();
            $table->unsignedSmallInteger('video_duration')->nullable();
            $table->string('video_path')->nullable();
            $table->timestamp('video_downloaded_at')->nullable();
            $table->json('flags')->nullable();
            $table->timestamps();

            $table->unique(['staff_id', 'date']);
            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['role', 'attendance_required']);
        });
    }
};
