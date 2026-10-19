<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** One thing that happened to a staff member's employment. Rows are only ever added, never edited or removed. */
class StaffHistory extends Eloquent
{
    protected $table = 'staff_history';

    protected $guarded = [];

    protected $casts = ['occurred_on' => 'date:Y-m-d', 'details' => 'array'];

    /** Reasons offered when a contract is stopped (somebody left, or management ended it). */
    public const STOP_REASONS = [
        'Resigned' => 'Resigned',
        'Terminated by management' => 'Terminated by management',
        'Dismissed' => 'Dismissed (misconduct)',
        'Redundancy' => 'Redundancy',
        'Contract not renewed' => 'Contract not renewed',
        'Mutual agreement' => 'Mutual agreement',
        'Retired' => 'Retired',
        'Deceased' => 'Deceased',
        'Other' => 'Other',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Adds a line to a staff member's history. */
    public static function record(Staff $staff, string $event, string $title, array $o = []): self
    {
        return self::create([
            'staff_id' => $staff->id,
            'contract_id' => $o['contract_id'] ?? null,
            'event' => $event,
            'title' => $title,
            'reason_type' => $o['reason_type'] ?? null,
            'reason' => $o['reason'] ?? null,
            'occurred_on' => $o['occurred_on'] ?? today()->toDateString(),
            'from_status' => $o['from_status'] ?? null,
            'to_status' => $o['to_status'] ?? null,
            'details' => $o['details'] ?? null,
            'user_id' => $o['user_id'] ?? auth()->id(),
        ]);
    }

    public function present(): array
    {
        return [
            'id' => $this->id, 'event' => $this->event, 'title' => $this->title, 'contract_id' => $this->contract_id,
            'reason_type' => $this->reason_type, 'reason' => $this->reason, 'occurred_on' => optional($this->occurred_on)->format('Y-m-d'),
            'from_status' => $this->from_status, 'to_status' => $this->to_status, 'details' => $this->details,
            'by' => optional($this->user)->name, 'at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
