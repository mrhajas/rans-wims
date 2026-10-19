<?php

namespace App\Models;

class ComplianceReminderLog extends Model
{
    protected $table = 'compliance_reminder_log';

    public static $hasAccount = false;

    public $casts = [
        'sent_at' => 'datetime',
    ];

    public function record()
    {
        return $this->belongsTo(ComplianceRecord::class, 'compliance_record_id');
    }

    public function renewalHistory()
    {
        return $this->belongsTo(ComplianceRenewalHistory::class, 'renewal_history_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
