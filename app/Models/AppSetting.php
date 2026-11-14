<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Admin paneldan o'zgartiriladigan sozlama (kalit => qiymat). */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'json'];
}
