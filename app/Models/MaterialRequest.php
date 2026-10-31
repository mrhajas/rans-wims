<?php

namespace App\Models;

use App\Traits\HasAttachments;

class MaterialRequest extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_PENDING_APPROVAL = 'Pending Approval';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_PARTIALLY_ISSUED = 'Partially Issued';
    public const STATUS_ISSUED = 'Issued';
    public const STATUS_CLOSED = 'Closed';
    /** Final state of the current workflow: Super Admin has cleared / completed the request and everything is locked. */
    public const STATUS_CLEARED = 'Cleared';

    public const DELIVERY_PARTIAL = 'Partially Delivered';
    public const DELIVERY_FULL = 'Fully Delivered';
    /** Audit-log statuses written when Site saves a delivery update ("Delivery Update" = remarks only, nothing ticked). */
    public const DELIVERY_LOG_STATUSES = ['Delivery Update', 'Partially Delivered', 'Fully Delivered'];

    public const PROCEDURE_SUPPLIER_PROCURE = 'Supplier Procure';
    public const PROCEDURE_SUPPLIER_CREDIT = 'Supplier Credit';
    public const PROCEDURE_MAIN_WAREHOUSE = 'MainWarehouse';
    public const PROCEDURE_TRANSFER_FROM_SITE = 'Transfer from Site';
    public const PROCEDURE_OTHER = 'Other';

    protected $fillable = [
        'date_requested',
        'reference',
        'warehouse_id',
        'user_id',
        'status',
        'quick',
        'reason',
        'comments',
        'approval_remarks',
        'cleared_by_user_id',
        'cleared_at',
        'force_cleared',
        'extra_attributes',
    ];

    public $casts = [
        'date_requested' => 'date:Y-m-d',
        'cleared_at' => 'datetime',
        'force_cleared' => 'boolean',
        'quick' => 'boolean',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_PARTIALLY_ISSUED,
            self::STATUS_ISSUED,
            self::STATUS_CLEARED,
            self::STATUS_CLOSED,
        ];
    }

    /** The status filter's choices, in workflow order. Partially / Fully Delivered are delivery progress (see deliveryState()), filterable like a status. */
    public static function filterStatuses(): array
    {
        return [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_PARTIALLY_ISSUED,
            self::STATUS_ISSUED,
            self::DELIVERY_PARTIAL,
            self::DELIVERY_FULL,
            self::STATUS_CLEARED,
        ];
    }

    public static function deliveryStatuses(): array
    {
        return [self::DELIVERY_PARTIAL, self::DELIVERY_FULL];
    }

    /** The status is stored as 'Issued' (it predates 'Partially Issued'); people know it as "Fully Issued". */
    public static function statusLabel(?string $status): string
    {
        return $status === self::STATUS_ISSUED ? 'Fully Issued' : (string) $status;
    }

    public static function procedures(): array
    {
        return [
            self::PROCEDURE_SUPPLIER_PROCURE,
            self::PROCEDURE_SUPPLIER_CREDIT,
            self::PROCEDURE_MAIN_WAREHOUSE,
            self::PROCEDURE_TRANSFER_FROM_SITE,
            self::PROCEDURE_OTHER,
        ];
    }

    public static function lockedStatuses(): array
    {
        return [
            self::STATUS_APPROVED,
            self::STATUS_PARTIALLY_ISSUED,
            self::STATUS_ISSUED,
            self::STATUS_CLEARED,
            self::STATUS_CLOSED,
        ];
    }

    /** Cleared (and legacy Closed) requests are read-only for everybody. */
    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_CLEARED, self::STATUS_CLOSED], true);
    }

    /**
     * Statuses a request must already be in before Procurement's procedures
     * (and therefore clearance — the first save of procedures is what sets
     * cleared_by_user_id) can even be looked at. A Pending Approval request
     * hasn't had an approve/reject decision yet, so it's excluded — without
     * this, a Super Admin (who can also clear) would see both the Approval
     * Workflow and the Procedures panel on the same Pending request, with
     * nothing stopping them from clearing it before it's actually approved.
     * Shared by MaterialRequestResource (hides the panel) and
     * MaterialRequestController::updateProcedures() (rejects the request).
     */
    public static function procedureEligibleStatuses(): array
    {
        return [
            self::STATUS_APPROVED,
            self::STATUS_PARTIALLY_ISSUED,
            self::STATUS_ISSUED,
        ];
    }

    /** Statuses in which already-set procedures are still shown (read-only once the request is final). */
    public static function procedureVisibleStatuses(): array
    {
        return array_merge(self::procedureEligibleStatuses(), [self::STATUS_CLEARED, self::STATUS_CLOSED]);
    }

    /** Statuses in which Site Engineers confirm what has arrived on site. */
    public static function deliveryEligibleStatuses(): array
    {
        return [self::STATUS_PARTIALLY_ISSUED, self::STATUS_ISSUED];
    }

    public function canBeEditedBySiteEngineer(): bool
    {
        return ! in_array($this->status, self::lockedStatuses(), true);
    }

    /** Overall delivery state: Fully Delivered (every item Procurement dealt with), Partially Delivered (something received), or null (nothing yet). */
    public function deliveryState(): ?string
    {
        $this->loadMissing('items');
        $deliverable = $this->items->whereNotNull('procedure');

        if ($deliverable->isEmpty()) {
            return null;
        }
        if ($deliverable->every(fn ($i) => $i->delivery_status === self::DELIVERY_FULL)) {
            return self::DELIVERY_FULL;
        }

        return $deliverable->whereNotNull('delivery_status')->isNotEmpty() ? self::DELIVERY_PARTIAL : null;
    }

    /**
     * Items Procurement has dealt with (they have a procedure) that Site has not marked Fully Delivered yet, as
     * "CODE name - Partially Delivered / Awaiting delivery" lines.
     */
    public function undeliveredItems(): array
    {
        $this->loadMissing('items.item:id,name,code');

        return $this->items
            ->filter(fn ($i) => ! empty($i->procedure) && $i->delivery_status !== self::DELIVERY_FULL)
            ->map(fn ($i) => trim((optional($i->item)->code ?? '') . ' ' . (optional($i->item)->name ?? '')) . ' - ' . ($i->delivery_status ?: 'Awaiting delivery'))
            ->values()
            ->all();
    }

    /** The transfers made for this request (a request can have several). */
    public function transfersMade()
    {
        return $this->hasMany(Transfer::class, 'material_request_id')->withoutGlobalScopes();
    }

    public function items()
    {
        return $this->hasMany(MaterialRequestItem::class)->orderBy('id');
    }

    public function logs()
    {
        return $this->hasMany(MaterialRequestStatusLog::class)->orderBy('id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function clearedBy()
    {
        return $this->belongsTo(User::class, 'cleared_by_user_id');
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->search($s))
            ->when($filters['status'] ?? null, function ($q, $s) {
                // Delivery progress is not a stored status: Fully = every item Procurement dealt with is Fully Delivered,
                // Partially = something was received but not everything.
                $notFull = fn ($i) => $i->whereNotNull('procedure')->where(fn ($w) => $w->whereNull('delivery_status')->orWhere('delivery_status', '!=', self::DELIVERY_FULL));
                if ($s === self::DELIVERY_FULL) {
                    return $q->whereHas('items', fn ($i) => $i->whereNotNull('procedure'))->whereDoesntHave('items', $notFull);
                }
                if ($s === self::DELIVERY_PARTIAL) {
                    return $q->whereHas('items', fn ($i) => $i->whereNotNull('delivery_status'))->whereHas('items', $notFull);
                }

                return $q->where('status', $s);
            })
            ->when(! empty($filters['quick']), fn ($q) => $q->where('quick', true))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['start_date'] ?? null, fn ($q, $d) => $q->whereDate('date_requested', '>=', $d))
            ->when($filters['end_date'] ?? null, fn ($q, $d) => $q->whereDate('date_requested', '<=', $d));
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('reference', 'like', "%{$search}%")
                ->orWhere('reason', 'like', "%{$search}%")
                ->orWhereHas('warehouse', fn ($w) => $w->where('name', 'like', "%{$search}%"))
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
        });
    }

    public function reminders()
    {
        return $this->hasMany(MaterialRequestReminder::class, 'material_request_id');
    }
}
