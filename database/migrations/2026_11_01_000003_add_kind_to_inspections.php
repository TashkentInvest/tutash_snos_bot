<?php

use App\Models\Inspection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tekshiruv turi: before (oldingi holat), process (buzish jarayoni), after (buzilgandan keyin),
 * court (sud qarori bekor), found (kelganda buzilgan). Eski natijalarda bo'sh — natija maydonlaridan aniqlanadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->string('kind', 10)->nullable()->after('status');
        });

        // eski oqimdagi tugallanmagan qoralamalar yangi bosqichlarga mos emas — model orqali o'chiriladi (fayllar ham)
        Inspection::where('status', Inspection::DRAFT)->get()->each->delete();
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
