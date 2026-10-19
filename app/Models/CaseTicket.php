<?php

namespace App\Models;

use App\Traits\HasAttachments;

/**
 * Internal WIMS "Case Tracking" record — a user-reported issue/request/
 * incident that moves through a fixed review chain to resolution. Adapted to
 * this app's existing status-string convention (LabourList, ProjectCertificate)
 * rather than a numeric/enum state machine: Draft -> Submitted -> In Review ->
 * In Progress -> Resolved -> Closed, with Returned (sent back for correction)
 * and reopening (Resolved/Closed -> In Review) as the two off-chain moves.
 * All transition rules live in CaseTicketPolicy::allowedTargetStatuses(),
 * mirroring LabourPolicy exactly.
 */
class CaseTicket extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_SUBMITTED = 'Submitted';
    public const STATUS_IN_REVIEW = 'In Review';
    public const STATUS_IN_PROGRESS = 'In Progress';
    public const STATUS_RETURNED = 'Returned';
    public const STATUS_RESOLVED = 'Resolved';
    public const STATUS_CLOSED = 'Closed';

    protected $fillable = [
        'case_number', 'subject', 'description', 'case_category_id', 'case_priority_id',
        'warehouse_id', 'module_reference', 'external_url', 'status',
        'created_by_user_id', 'assigned_to_user_id',
        'submitted_at', 'return_reason', 'resolution', 'resolved_by_user_id', 'resolved_at',
        'closed_by_user_id', 'closed_at', 'reopened_count',
    ];

    public $casts = [
        'submitted_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Every WIMS module a case can be filed against — mirrors the sidebar's
     * top-level groups (resources/js/Core/menu.js) rather than every single
     * sub-page under them, since "which module is this about" is a triage
     * category, not a deep-link. Kept here (not a DB table like
     * CaseCategory/CasePriority) since the module spec asked for a fixed,
     * comprehensive dropdown, not an admin-managed list.
     */
    public static function wimsModules(): array
    {
        return [
            'Dashboard', 'Checkins', 'Checkouts', 'Adjustments', 'Transfers', 'Issue & Return',
            'Items', 'Contacts', 'Categories', 'Units', 'Activities',
            'Material Requests', 'Material Reallocation', 'RANS Staffs', 'Assets', 'Compliance',
            'Digital Attendance', 'Payment Vouchers', 'Labour', 'Workforce Agreements', 'Case Tracking',
            'Supplier Records', 'Project Contracts', 'Site Reports', 'Site Diary', 'Project Execution',
            'Notice Board', 'Task Reminders', 'Project(s) / Warehouse(s)', 'Users', 'Roles',
            'Reports', 'Material Value', 'Movement Value Report', 'Project Material Ledger', 'Login Logs',
            'Email Notifications', 'WIMS Connect', 'Executive Intelligence Report', 'Download / Print Logs',
            'System Logs', 'Deleted Records', 'Backup', 'Security Reminders', 'Other',
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_IN_REVIEW, self::STATUS_IN_PROGRESS,
            self::STATUS_RETURNED, self::STATUS_RESOLVED, self::STATUS_CLOSED,
        ];
    }

    /** A Draft or Returned case is still owned exclusively by its creator — same "not yet acted on" idea LabourList/PaymentRequest use. */
    public static function editableStatuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_RETURNED];
    }

    public function category()
    {
        return $this->belongsTo(CaseCategory::class, 'case_category_id');
    }

    public function priority()
    {
        return $this->belongsTo(CasePriority::class, 'case_priority_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function comments()
    {
        return $this->hasMany(CaseComment::class)->orderBy('created_at');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }

    /** Open for more than 14 days with no resolution — the dashboard's "long-running" bucket. */
    public function isLongRunning(): bool
    {
        return $this->isOpen() && $this->created_at && $this->created_at->diffInDays(now()) > 14;
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('case_tickets.case_number', 'like', "%{$search}%")
                        ->orWhere('case_tickets.subject', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('case_tickets.status', $s))
            ->when($filters['case_category_id'] ?? null, fn ($q, $id) => $q->where('case_tickets.case_category_id', $id))
            ->when($filters['case_priority_id'] ?? null, fn ($q, $id) => $q->where('case_tickets.case_priority_id', $id))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('case_tickets.warehouse_id', $id))
            ->when($filters['assigned_to_user_id'] ?? null, fn ($q, $id) => $q->where('case_tickets.assigned_to_user_id', $id))
            ->when($filters['created_by_user_id'] ?? null, fn ($q, $id) => $q->where('case_tickets.created_by_user_id', $id))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('case_tickets.created_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('case_tickets.created_at', '<=', $d));
    }
}
