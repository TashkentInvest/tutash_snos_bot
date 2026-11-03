<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Shartnoma" — alohida rol: faqat ertalabki davomat va har soatlik qayd (obyektlar bilan ishlamaydi).
 * Admin xodimni oldindan qo'shadi (taklif): shu telefon bilan botga kirgan odam avtomatik shu rolni oladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_invites', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 9)->unique();
            $table->string('full_name', 150)->nullable();
            $table->string('role', 16); // employee | contract
            $table->json('district_ids')->nullable(); // Xodim uchun tumanlar
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete(); // kim ro'yxatdan o'tdi
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        // avval belgi bilan belgilanganlar — endi rol
        DB::table('staff')->where('is_contract', true)->update(['role' => 'contract']);
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('is_contract');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->boolean('is_contract')->default(false);
        });
        DB::table('staff')->where('role', 'contract')->update(['role' => 'employee', 'is_contract' => true]);
        Schema::dropIfExists('staff_invites');
    }
};
