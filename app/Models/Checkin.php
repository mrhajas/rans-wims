<?php

namespace App\Models;

use App\Traits\OrderHelpers;
use App\Traits\HasAttachments;
use App\Traits\HasManySyncable;

class Checkin extends Model
{
    use HasAttachments;
    use HasManySyncable;
    use OrderHelpers;

    public static $hasReference = true;

    public static $hasUser = true;

    public static $tracksDeletes = true;

    protected $fillable = [
        'date', 'reference',  'draft', 'contact_id', 'warehouse_id', 'user_id',
        'hash', 'approved_by', 'account_id', 'details', 'extra_attributes', 'approved_at',
        'submitted_at', 'approved_by_role', 'rejected_by', 'rejected_at', 'rejection_reason',
        'grn_number', 'grn_generated_by', 'grn_generated_at',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'grn_generated_at' => 'datetime',
    ];

    protected $appends = ['status'];

    public const STATUS_DRAFT = 'Draft';

    public const STATUS_PENDING = 'Pending';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_REJECTED = 'Rejected';

    /** What the person who approved is called on a check-in that was completed before approvals existed (nobody approved it). */
    public const SYSTEM_APPROVER = 'WIMS System';

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];
    }

    /**
     * Draft (never submitted) / Pending (submitted, waiting for approval) / Approved / Rejected.
     * Stock only moves while draft = 0, so Draft, Pending and Rejected never touch it.
     */
    public function getStatusAttribute(): string
    {
        if ((int) $this->draft === 0) {
            return self::STATUS_APPROVED;
        }
        if ($this->rejected_at) {
            return self::STATUS_REJECTED;
        }

        return $this->submitted_at ? self::STATUS_PENDING : self::STATUS_DRAFT;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Approved by the system (a check-in from before approvals): no person approved it. */
    public function approvedBySystem(): bool
    {
        return $this->status === self::STATUS_APPROVED && ! $this->approved_by;
    }

    /** Who may approve or reject a check-in: Super Admin, or any role granted "Approve / Reject Checkins". */
    public static function userCanApprove(?User $user): bool
    {
        return (bool) $user && ($user->hasRole('Super Admin') || $user->can('approve-checkins'));
    }

    public function scopeStatus($query, ?string $status)
    {
        return match ($status) {
            self::STATUS_DRAFT => $query->where('draft', 1)->whereNull('submitted_at')->whereNull('rejected_at'),
            self::STATUS_PENDING => $query->where('draft', 1)->whereNotNull('submitted_at')->whereNull('rejected_at'),
            self::STATUS_APPROVED => $query->where('draft', 0),
            self::STATUS_REJECTED => $query->where('draft', 1)->whereNotNull('rejected_at'),
            default => $query,
        };
    }

    /** The next Goods Received Note number (GRN-0001...). Call it inside ReferenceLock::run('grn', ...) so two approvals never share one. */
    public static function nextGrnNumber(): string
    {
        $max = (int) \Illuminate\Support\Facades\DB::table('checkins')
            ->where('grn_number', 'like', 'GRN-%')
            ->selectRaw('MAX(CAST(SUBSTRING(grn_number, 5) AS UNSIGNED)) as m')
            ->value('m');

        return 'GRN-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function grnGenerator()
    {
        return $this->belongsTo(User::class, 'grn_generated_by');
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
        return $this->hasMany(CheckinItem::class)->orderBy('id')->withTrashed();
    }

    public function scopeSearch($query, $s)
    {
        $query->where(
            fn ($q) => $q->where('id', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")
                ->orWhereHas('contact', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"))
                ->orWhereHas('user', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('username', 'like', "%{$s}%"))
                ->orWhereHas('warehouse', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function deliveryNote()
    {
        return $this->hasOne(DeliveryNote::class, 'source_id')->where('source_type', DeliveryNote::SOURCE_CHECKIN);
    }

    public function gatePass()
    {
        return $this->hasOne(GatePass::class, 'source_id')->where('source_type', GatePass::SOURCE_CHECKIN);
    }
}
