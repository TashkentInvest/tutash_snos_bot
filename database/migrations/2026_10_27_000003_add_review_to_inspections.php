<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->string('review_status', 10)->nullable()->index(); // pending | approved | rejected (faqat "buzilgan" natijalari uchun)
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
        });

        // Avvalgi "buzilgan" natijalar allaqachon hisobga olingan — tasdiqlangan deb belgilanadi.
        DB::table('inspections')->where('status', 'submitted')->where('is_demolished', true)
            ->update(['review_status' => 'approved', 'reviewed_at' => DB::raw('submitted_at')]);
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropColumn(['review_status', 'reviewed_by', 'reviewed_at', 'review_note']);
        });
    }
};
