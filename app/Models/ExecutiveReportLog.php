<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExecutiveReportLog extends Model
{
    protected $fillable = [
        'week_start', 'week_end', 'recipient_count', 'recipients', 'status', 'notes', 'sent_at', 'account_id',
    ];

    protected $casts = [
        'recipients' => 'array',
        'sent_at'    => 'datetime:Y-m-d H:i',
        'week_start' => 'date:Y-m-d',
        'week_end'   => 'date:Y-m-d',
    ];
}
