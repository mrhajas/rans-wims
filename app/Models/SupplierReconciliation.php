<?php

namespace App\Models;

use App\Traits\HasAttachments;

class SupplierReconciliation extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_OPEN = 'Open';
    public const STATUS_RESOLVED = 'Resolved';
    public const STATUS_DISPUTED = 'Disputed';

    public const REASONS = [
        'Missing Invoice', 'Missing Payment', 'Unrecorded Purchase', 'Credit Note',
        'Timing Difference', 'Incorrect Allocation', 'Duplicate Transaction', 'Historical Error', 'Other',
    ];

    protected $fillable = [
        'supplier_id', 'reconciliation_date', 'statement_date', 'statement_balance', 'wims_balance',
        'difference', 'reason', 'status', 'notes', 'created_by',
        'reviewed_by', 'reviewed_on', 'approved_by', 'approved_on', 'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'reconciliation_date' => 'date:Y-m-d',
        'statement_date' => 'date:Y-m-d',
        'reviewed_on' => 'datetime',
        'approved_on' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
