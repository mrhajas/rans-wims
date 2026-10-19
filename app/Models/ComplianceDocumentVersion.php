<?php

namespace App\Models;

class ComplianceDocumentVersion extends Model
{
    public static $hasAccount = false;

    public $casts = [
        'is_current' => 'boolean',
    ];

    public function record()
    {
        return $this->belongsTo(ComplianceRecord::class, 'compliance_record_id');
    }

    public function attachment()
    {
        return $this->belongsTo(\Tecdiary\Laravel\Attachments\Attachment::class, 'attachment_id');
    }

    public function renewalHistory()
    {
        return $this->belongsTo(ComplianceRenewalHistory::class, 'renewal_history_id');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }
}
