<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cheklangan panel foydalanuvchilari: faqat ertalabki davomat (ko'rish) va faqat ijara shartnomalari (ko'rish, tuzatish; o'chirmaydi). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('attendance_only')->default(false);
            $t->boolean('leases_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['attendance_only', 'leases_only']);
        });
    }
};
