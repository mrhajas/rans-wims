<?php

namespace App\Models;

class DailySiteReportLog extends Model
{
    public static $hasAccount = false;

    public static $tracksDeletes = false;

    protected $fillable = [
        'daily_site_report_id',
        'user_id',
        'role',
        'action',
        'remarks',
        'ip_address',
        'user_agent',
    ];

    public function dailySiteReport()
    {
        return $this->belongsTo(DailySiteReport::class)->withoutGlobalScope('workflow_visibility');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
