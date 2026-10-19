<?php

namespace App\Models;

class ComplianceRenewalHistory extends Model
{
    protected $table = 'compliance_renewal_history';

    public static $hasAccount = false;

    public const STATUSES = ['Renewal in Progress', 'Submitted', 'Pending Approval', 'Renewed', 'Rejected', 'Cancelled'];

    public $casts = [
        'previous_issue_date' => 'date',
        'previous_expiry_date' => 'date',
        'new_issue_date' => 'date',
        'new_expiry_date' => 'date',
        'renewal_started_at' => 'datetime',
        'renewal_submitted_at' => 'datetime',
        'expected_completion_date' => 'date',
        'renewed_at' => 'datetime',
    ];

    public function record()
    {
        return $this->belongsTo(ComplianceRecord::class, 'compliance_record_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
