<?php

namespace App\Models;

class FundAllocation extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_PENDING = 'Pending Approval';
    public const STATUS_APPROVED = 'Approved';       // approved, waiting for the payer to start
    public const STATUS_IN_PROGRESS = 'In Progress'; // payer has ticked at least one payment
    public const STATUS_COMPLETED = 'Completed';     // every payment ticked: the sheet reconciles
    public const STATUS_CLEARED = 'Cleared';         // payer closed the fully paid sheet: locked until a Super Admin reopens it
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_CANCELLED = 'Cancelled';

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CLEARED, self::STATUS_REJECTED, self::STATUS_CANCELLED];
    }

    protected $fillable = [
        'reference', 'allocation_date', 'title', 'notes', 'status', 'prepared_by_user_id', 'submitted_at',
        'approved_by_user_id', 'approved_at', 'rejection_reason', 'assigned_to_user_id', 'completed_at',
        'cancelled_by_user_id', 'cancelled_at', 'cancel_reason', 'completed_by_user_id', 'cleared_by_user_id', 'cleared_at',
    ];

    public $casts = [
        'allocation_date' => 'date:Y-m-d',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'cleared_at' => 'datetime',
    ];

    public function balances()
    {
        return $this->hasMany(FundAllocationBalance::class)->orderBy('sort_order')->orderBy('id');
    }

    public function items()
    {
        return $this->hasMany(FundAllocationItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function preparedBy()
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** Every payer of the sheet (assigned_to_user_id is the first, main one). */
    public function payers()
    {
        return $this->belongsToMany(User::class, 'fund_allocation_payers', 'fund_allocation_id', 'user_id')->withTimestamps();
    }

    public function hasPayer(?int $userId): bool
    {
        if (! $userId) {
            return false;
        }
        if ((int) $this->assigned_to_user_id === $userId) {
            return true;
        }

        return $this->relationLoaded('payers') ? $this->payers->contains('id', $userId) : $this->payers()->where('users.id', $userId)->exists();
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function clearedBy()
    {
        return $this->belongsTo(User::class, 'cleared_by_user_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /** Editable only while it is still being prepared (or after a rejection, to fix and resubmit). */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    /** Payments can be ticked only once approved and until completed. */
    public function isPayable(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_IN_PROGRESS], true);
    }

    public static function nextReference(): string
    {
        $prefix = 'FA-'.now()->format('Y').'-';
        $max = (int) \Illuminate\Support\Facades\DB::table('fund_allocations')
            ->where('reference', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(reference, '.(strlen($prefix) + 1).') AS UNSIGNED)) as m')
            ->value('m');

        return $prefix.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('reference', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('allocation_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('allocation_date', '<=', $d))
            ->when($filters['payer'] ?? null, fn ($q, $u) => $q->where(fn ($w) => $w->where('assigned_to_user_id', $u)->orWhereHas('payers', fn ($p) => $p->where('users.id', $u))));
    }
}
