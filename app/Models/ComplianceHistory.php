<?php

namespace App\Models;

class ComplianceHistory extends Model
{
    protected $table = 'compliance_history';

    public static $hasAccount = false;

    // Append-only — no updated_at column.
    const UPDATED_AT = null;

    public const EVENT_TYPES = [
        'Created', 'Edited', 'Submitted', 'Reminder Sent', 'Email Sent', 'Notification Sent',
        'Renewal Started', 'Renewal Completed', 'Document Added', 'Document Replaced',
        'Approved', 'Rejected', 'Expired', 'Suspended', 'Cancelled', 'Escalated',
    ];

    public function record()
    {
        return $this->belongsTo(ComplianceRecord::class, 'compliance_record_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function log(ComplianceRecord $record, string $eventType, ?string $description = null, ?string $oldValue = null, ?string $newValue = null): self
    {
        return static::create([
            'compliance_record_id' => $record->id,
            'event_type' => $eventType,
            'description' => $description,
            'user_id' => auth()->id(),
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);
    }
}
