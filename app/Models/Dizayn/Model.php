<?php

namespace App\Models\Dizayn;

use Illuminate\Database\Eloquent\Model as Base;

/**
 * Dizayn-kod boti bazasidagi jadvallar (o'sha serverdagi «tutash» bazasi). Jadvallar botniki: sxemani bot o'zi yaratadi
 * (bot/db.py), SNOS paneli faqat o'qiydi va bot kutadigan ustunlarni yozadi (murojaat javobi, post, holatlar).
 * Vaqtlar UTC'da saqlanadi.
 */
abstract class Model extends Base
{
    protected $connection = 'dizayn';

    public $timestamps = false;

    protected $guarded = [];

    /** «Dizayn-kod boti» bo'limi ishlaydimi (sozlamada yoqilgan va bazaga ulanish bor). Ulanish natijasi so'rov davomida eslab qolinadi. */
    public static function available(): bool
    {
        if (! config('services.dizayn_bot.enabled')) {
            return false;
        }
        if (app()->bound('dizayn.available')) {
            return app('dizayn.available');
        }
        try {
            \Illuminate\Support\Facades\DB::connection('dizayn')->table('users')->limit(1)->exists();
            $ok = true;
        } catch (\Throwable) {
            $ok = false; // ruxsat yo'q yoki baza topilmadi — bo'lim yashiriladi, panel buzilmaydi
        }
        app()->instance('dizayn.available', $ok);

        return $ok;
    }
}
