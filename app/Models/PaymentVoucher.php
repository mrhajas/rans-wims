<?php

namespace App\Models;

use App\Traits\HasAttachments;

class PaymentVoucher extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    // Shown when a voucher has no project/warehouse — i.e. it's a company-wide expense.
    public const COMPANY_NAME = 'RANS Company Limited';

    // Every line item's stored particulars text is prefixed with this — the
    // Form only ever collects/edits the remainder after it (see
    // PaymentVoucherController::validated()/rememberParticular()).
    public const PARTICULARS_PREFIX = 'Being Paid for : ';

    // payment_voucher_items.particulars is a varchar(255) that holds the prefix plus the typed text, so the typed text may be 255 - 17 long.
    public const PARTICULARS_MAX = 238;

    // payment_vouchers.total_amount is decimal(15,2), and one line may not be more than this.
    public const MAX_LINE_AMOUNT = '999999999999.99';
    public const MAX_TOTAL_AMOUNT = '9999999999999.99';

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_SUBMITTED = 'Submitted';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_AUTHORISED = 'Authorised';
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_CANCELLED = 'Cancelled';

    /** Display-only status for an Authorised voucher that has been paid. */
    public const DISPLAY_PAID = 'Paid';

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED,
            self::STATUS_AUTHORISED, self::STATUS_REJECTED, self::STATUS_CANCELLED,
        ];
    }

    public const PAYEE_TYPE_STAFF = 'Internal Staff';
    public const PAYEE_TYPE_EXTERNAL = 'External Personnel';

    protected $fillable = [
        'pv_number', 'payment_voucher_batch_id', 'type', 'payee_type', 'payee_contact_id', 'staff_id', 'payee_name', 'warehouse_id', 'asset_id', 'voucher_date',
        'total_amount', 'amount_in_words', 'status',
        'prepared_by_user_id', 'approved_by_user_id', 'approved_at',
        'authorised_by_user_id', 'authorised_at', 'approval_remarks', 'cancelled_by_user_id', 'cancelled_at', 'cancel_reason',
        'recipient_name', 'recipient_date', 'internal_notes', 'remarks',
        'purchase_order_id', 'supplier_invoice_id', 'payment_request_id',
        'labour_list_id', 'labour_list_item_id', 'subcontract_payment_id', 'subcontract_claim_id',
        'paid_at', 'payment_reference', 'payment_method', 'paid_by_user_id',
        'printed_count', 'first_printed_at', 'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'voucher_date' => 'date:Y-m-d',
        'recipient_date' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'authorised_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'paid_at' => 'datetime',
        'first_printed_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(PaymentVoucherItem::class)->orderBy('line_number');
    }

    /** The Funds Allocation line this voucher pays (fund_allocation_items.payment_voucher_id), if any. */
    public function fundAllocationItem()
    {
        return $this->hasOne(FundAllocationItem::class, 'payment_voucher_id');
    }

    public function batch()
    {
        return $this->belongsTo(PaymentVoucherBatch::class, 'payment_voucher_batch_id');
    }

    public function payeeContact()
    {
        return $this->belongsTo(Contact::class, 'payee_contact_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function preparedBy()
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function authorisedBy()
    {
        return $this->belongsTo(User::class, 'authorised_by_user_id');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplierInvoice()
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function labourList()
    {
        return $this->belongsTo(LabourList::class)->withoutGlobalScope('workflow_visibility');
    }

    public function labourListItem()
    {
        return $this->belongsTo(LabourListItem::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    /**
     * What people should see as the status: a voucher stays "Authorised" in the database once paid (paid_at is a separate
     * stamp, see PaymentVoucherController::recordPayment), but it is shown as "Paid".
     */
    public function getDisplayStatusAttribute(): string
    {
        return $this->status === self::STATUS_AUTHORISED && $this->paid_at ? self::DISPLAY_PAID : $this->status;
    }

    /** The text stored on a voucher line: the constant prefix plus the typed text, cut so it always fits the column. */
    public static function particularsText(string $remainder): string
    {
        return self::PARTICULARS_PREFIX . mb_substr(trim($remainder), 0, self::PARTICULARS_MAX);
    }

    /**
     * The projects (warehouses) a person works in, or null when they are not limited to any (Super Admin, view-all, an Admin without an assignment, or nobody assigned).
     * Computed fresh each time (no static cache) so it is right for every person in the same process.
     */
    public static function projectIdsFor(?User $u): ?array
    {
        if (! $u || $u->hasRole('Super Admin') || $u->view_all) {
            return null;
        }
        $assigned = $u->warehouses()->pluck('warehouses.id')->map(fn ($i) => (int) $i)->all();
        if ($assigned) {
            return $assigned;
        }
        if ($u->hasRole(['Super Admin', 'Admin'])) {
            return null;
        }

        return $u->warehouse_id ? [(int) $u->warehouse_id] : null;
    }

    /**
     * Who sees which vouchers: everybody who is not limited to projects sees all; a person assigned to projects sees the vouchers of those projects, the
     * company-wide ones (no project) and the ones they prepared themselves.
     */
    public function scopeVisibleTo($query, ?User $u)
    {
        $ids = self::projectIdsFor($u);
        if ($ids === null) {
            return $query;
        }

        return $query->where(fn ($w) => $w->whereIn($w->getModel()->getTable() . '.warehouse_id', $ids)->orWhereNull($w->getModel()->getTable() . '.warehouse_id')->orWhere($w->getModel()->getTable() . '.prepared_by_user_id', $u->id));
    }

    /** Whether this person may open / act on this voucher (the same rule as visibleTo). */
    public function visibleToUser(?User $u): bool
    {
        $ids = self::projectIdsFor($u);

        return $ids === null || $this->warehouse_id === null || in_array((int) $this->warehouse_id, $ids, true) || (int) $this->prepared_by_user_id === (int) $u->id;
    }

    public function scopeFilter($query, array $filters)
    {
        // only plain values are filters: an array (?search[]=x) would otherwise reach the query builder as an array and fail
        $filters = array_map(fn ($value) => is_scalar($value) ? (string) $value : null, $filters);

        return $query
            // "Paid" = Authorised with a payment recorded; "Authorised" therefore means authorised and still waiting to be paid.
            ->when($filters['status'] ?? null, fn ($q, $s) => match ($s) {
                self::DISPLAY_PAID => $q->where('status', self::STATUS_AUTHORISED)->whereNotNull('paid_at'),
                self::STATUS_AUTHORISED => $q->where('status', self::STATUS_AUTHORISED)->whereNull('paid_at'),
                default => $q->where('status', $s),
            })
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['asset_id'] ?? null, fn ($q, $id) => $q->where('asset_id', $id))
            ->when($filters['payee_contact_id'] ?? null, fn ($q, $id) => $q->where('payee_contact_id', $id))
            ->when($filters['staff_id'] ?? null, fn ($q, $id) => $q->where('staff_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['payment_voucher_batch_id'] ?? null, fn ($q, $id) => $q->where('payment_voucher_batch_id', $id))
            ->when($filters['source'] ?? null, fn ($q, $s) => $s === 'labour' ? $q->whereNotNull('labour_list_id') : $q)
            ->when($filters['batch_number'] ?? null, fn ($q, $number) => $q->whereHas('batch', fn ($bq) => $bq->where('batch_number', 'like', "%{$number}%")))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('voucher_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('voucher_date', '<=', $d))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('pv_number', 'like', "%{$search}%")
                        ->orWhere('payee_name', 'like', "%{$search}%");
                });
            });
    }
}
