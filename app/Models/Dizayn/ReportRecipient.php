<?php

namespace App\Models\Dizayn;

/** Dizayn-kod hisobotlarini oluvchi rahbar (botga kirgan odam). */
class ReportRecipient extends Model
{
    protected $table = 'report_recipients';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $casts = ['daily_report' => 'boolean', 'attendance_report' => 'boolean', 'hourly_report' => 'boolean', 'created_at' => 'datetime'];
}
