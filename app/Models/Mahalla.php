<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mahalla extends Model
{
    protected $fillable = ['district_id', 'name', 'created_by_staff_id'];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id')->withTrashed();
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
