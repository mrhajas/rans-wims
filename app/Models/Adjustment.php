<?php

namespace App\Models;

use App\Traits\OrderHelpers;
use App\Traits\HasAttachments;
use App\Traits\HasManySyncable;

class Adjustment extends Model
{
    use HasAttachments;
    use HasManySyncable;
    use OrderHelpers;

    public static $hasReference = true;

    public static $hasUser = true;

    public static $tracksDeletes = true;

    protected $fillable = [
        'date', 'reference',  'draft', 'type', 'warehouse_id', 'user_id', 'hash',
        'approved_by', 'account_id', 'details', 'extra_attributes', 'approved_at',
        'approved_by_role', 'rejected_by', 'rejected_at', 'rejection_reason',
    ];

    public const STATUS_PENDING = 'Pending';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_REJECTED = 'Rejected';

    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];
    }

    /** Pending (waiting for approval) / Approved / Rejected. A rejected record stays a draft, so it never touches stock. */
    public function getStatusAttribute(): string
    {
        if ($this->rejected_at) {
            return self::STATUS_REJECTED;
        }

        return (int) $this->draft === 1 ? self::STATUS_PENDING : self::STATUS_APPROVED;
    }

    /** Who may approve or reject: Super Admin, or any role granted "Approve / Reject Adjustments" (Admin and Project Manager by default). */
    public static function userCanApprove(?User $user): bool
    {
        return (bool) $user && ($user->hasRole('Super Admin') || $user->can('approve-adjustments'));
    }

    /** How long after approval a Super Admin may still take an approval back (reject it / set it to Pending). */
    public const REVERSAL_WINDOW_HOURS = 8;

    public static function reversalBlockedMessage(string $what = 'adjustment'): string
    {
        return __('This :what was approved more than :hours hours ago, so its approval can no longer be reversed. Inventory movements, reports and other records may already rely on it, and reversing it now would rewrite history that others have seen. If the stock is wrong, create a new Adjustment (Addition or Subtraction) to balance the inventory instead.', ['what' => $what, 'hours' => self::REVERSAL_WINDOW_HOURS]);
    }

    /** True while an Approved record is still inside the window in which its approval may be reversed. */
    public function approvalReversible(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->approved_at
            && \Illuminate\Support\Carbon::parse($this->approved_at)->addHours(self::REVERSAL_WINDOW_HOURS)->isFuture();
    }

    /** Approved and past the window: the approval is locked. */
    public function approvalLocked(): bool
    {
        return $this->status === self::STATUS_APPROVED && ! $this->approvalReversible();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function scopeStatus($query, ?string $status)
    {
        return match ($status) {
            self::STATUS_PENDING => $query->where('draft', 1)->whereNull('rejected_at'),
            self::STATUS_APPROVED => $query->where('draft', 0),
            self::STATUS_REJECTED => $query->whereNotNull('rejected_at'),
            default => $query,
        };
    }

    protected $setHash = true;

    protected $setUser = true;

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function del()
    {
        $this->items->each->delete();

        return $this->delete();
    }

    public function delP()
    {
        log_activity(__choice('delete_text', ['record' => 'Item']), $this, $this, 'Item');
        $this->items->each->forceDelete();

        return $this->forceDelete();
    }

    public function items()
    {
        return $this->hasMany(AdjustmentItem::class)->orderBy('id')->withTrashed();
    }

    public function scopeSearch($query, $s)
    {
        $query->where(
            fn ($q) => $q->where('id', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")
                ->orWhereHas('user', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('username', 'like', "%{$s}%"))
                ->orWhereHas('warehouse', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
