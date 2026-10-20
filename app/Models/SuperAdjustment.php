<?php

namespace App\Models;

use App\Traits\HasAttachments;

class SuperAdjustment extends Model
{
    use HasAttachments;

    public static $hasReference = true;

    public static $hasUser = true;

    public static $tracksDeletes = true;

    protected $setUser = true;

    const STATUS_DRAFT = 'Draft';

    const STATUS_APPROVED = 'Approved';

    const STATUS_REJECTED = 'Rejected';

    /** The first Super Admin approved; the stock changes only when the required approvals are complete. */
    const STATUS_AWAITING = 'Awaiting Phase 2';

    /** Setting key: how many different Super Admins must approve (1 or 2). */
    const RULE_KEY = 'super_adjustment_approvals';

    protected $fillable = [
        'reference', 'warehouse_id', 'adjustment_session_id', 'date', 'user_id', 'reason', 'details', 'remarks',
        'status', 'approved_by_user_id', 'approved_by_role', 'approved_at', 'rejected_by_user_id', 'rejected_at',
        'rejection_reason', 'created_addition_adjustment_id', 'created_subtraction_adjustment_id',
        'account_id',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    /** Approved and past the window in which a Super Admin may still take the approval back (same window as Adjustments). */
    public function approvalLocked(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && ! ($this->approved_at && $this->approved_at->copy()->addHours(Adjustment::REVERSAL_WINDOW_HOURS)->isFuture());
    }

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_AWAITING, self::STATUS_APPROVED, self::STATUS_REJECTED];
    }

    /** Still waiting for approval (no approval yet, or the first one given). */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_AWAITING], true);
    }

    /** How many different Super Admins must approve before the stock changes: 1 or 2 (2 until the setting says otherwise). */
    public static function requiredApprovals(): int
    {
        $v = \Illuminate\Support\Facades\DB::table('settings')->where('tec_key', self::RULE_KEY)->where('account_id', (int) (function_exists('getAccountId') ? (getAccountId() ?: 1) : 1))->value('tec_value');

        return (int) $v === 1 ? 1 : 2;
    }

    /** Setting keys of the SuperAdjustment ByPass (Dr Hajas only, under Profile): switched on, and the Super Admin who gives phase 2 for him. */
    const BYPASS_ACTIVE_KEY = 'super_adjustment_bypass_active';

    const BYPASS_APPROVER_KEY = 'super_adjustment_bypass_approver';

    private static function setting(string $key)
    {
        return \Illuminate\Support\Facades\DB::table('settings')->where('tec_key', $key)->where('account_id', (int) (function_exists('getAccountId') ? (getAccountId() ?: 1) : 1))->value('tec_value');
    }

    /** @return array{active:bool,approver_id:?int} as saved now (the approver is only used while the ByPass is active) */
    public static function bypass(): array
    {
        $id = (int) self::setting(self::BYPASS_APPROVER_KEY);

        return ['active' => self::setting(self::BYPASS_ACTIVE_KEY) === '1', 'approver_id' => $id ?: null];
    }

    /**
     * The Super Admin who gives phase 2 AUTOMATICALLY when $user (Dr Hajas, really signed in) gives phase 1, or null when the ByPass does not apply:
     * not active, not Dr Hajas, no approver chosen, the approver is no longer an active Super Admin, or the approver is the same person.
     */
    public static function bypassApproverFor(?User $user): ?User
    {
        if (! $user || ! \App\Services\Licensing\LicenceService::isManager($user)) {
            return null;
        }
        $b = self::bypass();
        if (! $b['active'] || ! $b['approver_id'] || $b['approver_id'] === $user->id) {
            return null;
        }
        $a = User::find($b['approver_id']);

        return $a && $a->is_active && $a->hasRole('Super Admin') ? $a : null;
    }

    public function approvals()
    {
        return $this->hasMany(SuperAdjustmentApproval::class)->whereNull('voided_at')->orderBy('phase');
    }

    /** Every approval ever given, including the ones that were voided. */
    public function approvalHistory()
    {
        return $this->hasMany(SuperAdjustmentApproval::class)->orderBy('id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** The Adjustment Session this count belongs to (a multi-day count of the warehouse), if any. */
    public function adjustmentSession()
    {
        return $this->belongsTo(AdjustmentSession::class, 'adjustment_session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function items()
    {
        return $this->hasMany(SuperAdjustmentItem::class)->orderBy('id');
    }

    public function additionAdjustment()
    {
        return $this->belongsTo(Adjustment::class, 'created_addition_adjustment_id');
    }

    public function subtractionAdjustment()
    {
        return $this->belongsTo(Adjustment::class, 'created_subtraction_adjustment_id');
    }

    public function scopeSearch($query, $s)
    {
        $query->where(
            fn ($q) => $q->where('id', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")
                ->orWhereHas('user', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('username', 'like', "%{$s}%"))
                ->orWhereHas('warehouse', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
        );
    }
}
