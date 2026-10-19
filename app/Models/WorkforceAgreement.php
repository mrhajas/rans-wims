<?php

namespace App\Models;

use App\Traits\HasAttachments;

/**
 * The standing "how much was this worker agreed for on this project"
 * record — created once, before any payment request references it. Balances
 * below are always LIVE-COMPUTED from linked LabourListItem/PaymentVoucher
 * rows, never stored, mirroring LabourList::paymentRollupStatus() exactly —
 * so they can never drift out of sync with what was actually paid.
 */
class WorkforceAgreement extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_ACTIVE = 'Active';

    public const STATUS_CANCELLED = 'Cancelled';

    public const PAYEE_TYPE_STAFF = 'Internal Staff';

    public const PAYEE_TYPE_EXTERNAL = 'External Personnel';

    protected $fillable = [
        'reference', 'warehouse_id', 'payee_type', 'contact_id', 'staff_id', 'payee_name',
        'type_of_work', 'scope_of_work', 'agreed_amount', 'opening_balance', 'agreement_reference', 'agreement_date', 'notes',
        'status', 'created_by_user_id', 'cancelled_by_user_id', 'cancelled_at',
    ];

    public $casts = [
        'agreement_date' => 'date:Y-m-d',
        'agreed_amount' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public static function statuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_CANCELLED];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function labourListItems()
    {
        return $this->hasMany(LabourListItem::class);
    }

    /** Locked once any request has been made against it — corrections after that go through a new agreement row, never an in-place edit. Mirrors LabourPolicy's own status-gated locking style. */
    public function isLocked(): bool
    {
        return $this->labourListItems()->exists();
    }

    public function totalRequested(): float
    {
        return (float) $this->labourListItems()->sum('amount');
    }

    public function totalApproved(): float
    {
        return (float) $this->labourListItems()
            ->selectRaw('COALESCE(SUM(COALESCE(approved_amount, amount)), 0) as total')
            ->value('total');
    }

    /** Only PAID vouchers count — this is the one figure that actually reduces the outstanding balance. */
    public function totalPaid(): float
    {
        return (float) PaymentVoucher::whereIn('labour_list_item_id', $this->labourListItems()->pluck('id'))
            ->whereNotNull('paid_at')
            ->sum('total_amount');
    }

    /** Agreed amount plus any carried-over opening balance from work done before this agreement/system tracked it — the true total this worker is entitled to on this project. */
    public function totalEntitlement(): float
    {
        return round((float) $this->agreed_amount + (float) $this->opening_balance, 2);
    }

    public function outstandingBalance(): float
    {
        return round($this->totalEntitlement() - $this->totalPaid(), 2);
    }

    /**
     * The slice of totalApproved()/totalRequested() that's already
     * committed to the approval pipeline (past Draft/Returned, so no longer
     * "might never happen") but not yet paid. outstandingBalance() alone
     * only ever subtracts what's actually been PAID — without also
     * reserving this "in flight" amount, two separate Labour List requests
     * against the same agreement could each independently pass the balance
     * check before either is paid, together overcommitting the agreement.
     * A list being validated through GuardsWorkforceBalance is always
     * Draft/Returned itself (LabourPolicy::update() only allows editing in
     * those statuses), so it's excluded from this sum by construction —
     * never double-counts against its own not-yet-submitted request.
     */
    public function committedUnpaidAmount(): float
    {
        $committed = (float) $this->labourListItems()
            ->whereHas('labourList', fn ($q) => $q->whereNotIn('status', [LabourList::STATUS_DRAFT, LabourList::STATUS_RETURNED]))
            ->selectRaw('COALESCE(SUM(COALESCE(approved_amount, amount)), 0) as total')
            ->value('total');

        return round(max($committed - $this->totalPaid(), 0), 2);
    }

    /** outstandingBalance() minus whatever's already reserved by other in-flight (submitted-but-unpaid) requests against this same agreement. */
    public function availableBalance(): float
    {
        return round($this->outstandingBalance() - $this->committedUnpaidAmount(), 2);
    }

    /** DERIVED, never stored — mirrors LabourList::paymentRollupStatus() exactly. */
    public function paymentStatus(): string
    {
        $paid = $this->totalPaid();

        if ($paid <= 0) {
            return 'Unpaid';
        }

        if ($paid >= $this->totalEntitlement()) {
            return 'Fully Paid';
        }

        return 'Partially Paid';
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('reference', 'like', "%{$search}%")
                        ->orWhere('payee_name', 'like', "%{$search}%")
                        ->orWhereHas('warehouse', fn ($w) => $w->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
    }
}
