<?php

namespace App\Models;

use App\Traits\HasAttachments;

class ComplianceRecord extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUSES = [
        'Draft', 'Active', 'Due Soon', 'Renewal in Progress', 'Submitted', 'Pending Approval',
        'Renewed', 'Expired', 'Cancelled', 'Suspended', 'Rejected', 'Completed',
    ];

    // Statuses driven by the renewal workflow (or a terminal manual action)
    // rather than the daily date-based recalculation — the scheduled
    // command leaves these alone.
    public const WORKFLOW_DRIVEN_STATUSES = [
        'Renewal in Progress', 'Submitted', 'Pending Approval', 'Renewed',
        'Cancelled', 'Suspended', 'Rejected', 'Completed',
    ];

    public const PRIORITIES = ['Critical', 'High', 'Medium', 'Low', 'Normal'];

    public const SCOPES = ['Company', 'Project', 'Site', 'Asset', 'Vehicle', 'Employee', 'Supplier', 'Contract', 'Other'];

    public const TYPES = [
        'Business Licence', 'Contractor Licence', 'Tax Certificate', 'Insurance', 'Bank Guarantee',
        'Performance Guarantee', 'Advance Payment Guarantee', 'Bid Bond', 'Loan', 'Work Permit',
        'Environmental Permit', 'Fire Certificate', 'Building Permit', 'Professional Certificate',
        'Vehicle Licence', 'Vehicle Insurance', 'Employee Certificate', 'Contract', 'Lease',
        'Government Registration', 'Statutory Filing', 'Tax Return', 'Audit Requirement',
        'Safety Certification', 'Equipment Certification',
    ];

    public const GUARANTEE_TYPES = ['Performance', 'Advance Payment', 'Bid Bond', 'Retention', 'Payment', 'Other'];

    public const DEFAULT_REMINDER_INTERVALS = [90, 60, 30, 14, 7, 3, 1, 0];

    public $casts = [
        'issue_date' => 'date',
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'renewal_date' => 'date',
        'submission_date' => 'date',
        'next_review_date' => 'date',
        'guarantee_claim_expiry' => 'date',
        'loan_start_date' => 'date',
        'loan_maturity_date' => 'date',
        'loan_next_payment_date' => 'date',
        'priority_is_manual_override' => 'boolean',
        'is_recurring' => 'boolean',
        'reminder_intervals' => 'array',
        'additional_recipient_user_ids' => 'array',
        'extra_attributes' => 'array',
    ];

    public function category()
    {
        return $this->belongsTo(ComplianceCategory::class, 'compliance_category_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function responsibleUser()
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function escalationRecipient()
    {
        return $this->belongsTo(User::class, 'escalation_recipient_user_id');
    }

    public function renewalHistory()
    {
        return $this->hasMany(ComplianceRenewalHistory::class)->orderByDesc('id');
    }

    public function activeRenewal()
    {
        return $this->hasOne(ComplianceRenewalHistory::class)
            ->whereIn('status', ['Renewal in Progress', 'Submitted', 'Pending Approval'])
            ->latest('id');
    }

    public function documentVersions()
    {
        return $this->hasMany(ComplianceDocumentVersion::class);
    }

    public function reminderLog()
    {
        return $this->hasMany(ComplianceReminderLog::class)->orderByDesc('sent_at');
    }

    public function history()
    {
        return $this->hasMany(ComplianceHistory::class)->orderByDesc('created_at');
    }

    public function scopeDueWithin($query, int $days)
    {
        return $query->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString())
            ->whereDate('expiry_date', '>=', now()->toDateString());
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now()->toDateString());
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['compliance_category_id'] ?? null, fn ($q, $id) => $q->where('compliance_category_id', $id))
            ->when($filters['compliance_type'] ?? null, fn ($q, $t) => $q->where('compliance_type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['responsible_user_id'] ?? null, fn ($q, $id) => $q->where('responsible_user_id', $id))
            ->when($filters['department'] ?? null, fn ($q, $d) => $q->where('department', $d))
            ->when($filters['issuing_authority'] ?? null, fn ($q, $a) => $q->where('issuing_authority', 'like', "%{$a}%"))
            ->when($filters['bank_name'] ?? null, fn ($q, $b) => $q->where('bank_name', 'like', "%{$b}%"))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['asset_id'] ?? null, fn ($q, $id) => $q->where('asset_id', $id))
            ->when($filters['scope'] ?? null, fn ($q, $s) => $q->where('scope', $s))
            ->when($filters['expiry_from'] ?? null, fn ($q, $d) => $q->whereDate('expiry_date', '>=', $d))
            ->when($filters['expiry_to'] ?? null, fn ($q, $d) => $q->whereDate('expiry_date', '<=', $d))
            ->when($filters['expired'] ?? null, fn ($q) => $q->expired())
            ->when($filters['due_soon'] ?? null, fn ($q) => $q->where('status', 'Due Soon'))
            ->when($filters['renewal_in_progress'] ?? null, fn ($q) => $q->where('status', 'Renewal in Progress'))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('compliance_number', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhere('reference_number', 'like', "%{$search}%")
                        ->orWhere('guarantee_number', 'like', "%{$search}%")
                        ->orWhere('loan_reference', 'like', "%{$search}%")
                        ->orWhere('insurance_policy_number', 'like', "%{$search}%")
                        ->orWhere('contract_number', 'like', "%{$search}%")
                        ->orWhere('issuing_authority', 'like', "%{$search}%");
                });
            });
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->expiry_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expiry_date->copy()->startOfDay(), false);
    }

    public function getDaysRemainingLabelAttribute(): string
    {
        $days = $this->days_remaining;

        if ($days === null) {
            return __('No expiry');
        }
        if ($days === 0) {
            return __('TODAY');
        }
        if ($days < 0) {
            return __('EXPIRED :days DAYS AGO', ['days' => abs($days)]);
        }

        return __(':days days', ['days' => $days]);
    }

    public function getComputedPriorityAttribute(): string
    {
        $days = $this->days_remaining;

        if ($days === null) {
            return 'Normal';
        }
        if ($days <= 7) {
            return 'Critical';
        }
        if ($days <= 30) {
            return 'High';
        }
        if ($days <= 60) {
            return 'Medium';
        }
        if ($days <= 90) {
            return 'Low';
        }

        return 'Normal';
    }

    public function getDueSoonThresholdAttribute(): int
    {
        $intervals = $this->reminder_intervals ?: self::DEFAULT_REMINDER_INTERVALS;

        return max($intervals);
    }

    /**
     * Auto status/priority recalculation (spec §7/§31). Only touches
     * records NOT currently in a workflow-driven or terminal state —
     * renewal progress and manual dispositions (Cancelled/Suspended/
     * Rejected/Completed) are left alone here.
     */
    public function recalculateStatusAndPriority(): bool
    {
        $dirty = false;

        if (! in_array($this->status, self::WORKFLOW_DRIVEN_STATUSES, true)) {
            $days = $this->days_remaining;
            $newStatus = $this->status;

            if ($days !== null) {
                if ($days < 0) {
                    $newStatus = 'Expired';
                } elseif ($days <= $this->due_soon_threshold) {
                    $newStatus = 'Due Soon';
                } elseif ($this->status !== 'Draft') {
                    $newStatus = 'Active';
                }
            }

            if ($newStatus !== $this->status) {
                $this->status = $newStatus;
                $dirty = true;
            }
        }

        if (! $this->priority_is_manual_override) {
            $newPriority = $this->computed_priority;
            if ($newPriority !== $this->priority) {
                $this->priority = $newPriority;
                $dirty = true;
            }
        }

        if ($dirty) {
            $this->saveQuietly();
        }

        return $dirty;
    }
}
