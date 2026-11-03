<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tumanlar endi faqat telefon orqali biriktiriladi. Ilgari Xodimlar sahifasida qo'lda qo'shilgan bog'lanishlar
 * (tuman telefonlarida yo'q) qayta hisoblashda o'chib ketmasligi uchun «Qo'shimcha xodimlar» ro'yxatiga o'tkaziladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('district_staff')
            ->join('staff', 'staff.id', '=', 'district_staff.staff_id')
            ->join('districts', 'districts.id', '=', 'district_staff.district_id')
            ->where('staff.is_global', false)->whereNotNull('staff.phone')
            ->where(fn ($q) => $q->whereNull('districts.phone')->orWhereColumn('districts.phone', '!=', 'staff.phone'))
            ->whereNotExists(fn ($q) => $q->from('district_phones')
                ->whereColumn('district_phones.district_id', 'district_staff.district_id')
                ->whereColumn('district_phones.phone', 'staff.phone'))
            ->get(['district_staff.district_id', 'staff.phone', 'staff.full_name']);

        foreach ($rows as $r) {
            DB::table('district_phones')->insertOrIgnore([
                'district_id' => $r->district_id, 'phone' => $r->phone, 'name' => $r->full_name,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // ma'lumot ko'chirish — qaytarilmaydi
    }
};
