<?php

namespace App\Models\Dizayn;

/** Ro'yxatdan o'tishda fuqaro yuborgan obyekt rasmi (fayl — bot papkasida: uploads/...). */
class UserPhoto extends Model
{
    protected $table = 'user_photos';

    protected $casts = ['created_at' => 'datetime'];
}
