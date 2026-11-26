<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Post botdan (oluvchilar chatidan) o'chirildi: qachon, nechtasi o'chdi / o'chmadi (Telegram 48 soatdan eski xabarni o'chirmaydi). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->timestamp('recalled_at')->nullable();
            $table->unsignedInteger('recalled')->default(0);
            $table->unsignedInteger('recall_failed')->default(0);
        });
        // har oluvchida: chatidan o'chirilgan vaqti (statistika — yetkazildi/tanishdi — o'zgarmaydi)
        Schema::table('post_deliveries', fn (Blueprint $table) => $table->timestamp('recalled_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('post_deliveries', fn (Blueprint $table) => $table->dropColumn('recalled_at'));
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn(['recalled_at', 'recalled', 'recall_failed']));
    }
};
