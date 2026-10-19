<?php

namespace App\Models;

use App\Traits\HasAttachments;

/**
 * One individual vehicle trip — never combined, even when the same vehicle
 * makes several trips to the same destination on the same day. The vehicle
 * itself is a plain Asset (scoped to the 'Vehicles' category tree, see
 * TransportVehicleController) and the driver is a Contact (category =
 * 'Drivers') — this model does not duplicate either. Destination is a
 * tagged union: exactly one of warehouse_id / destination_contact_id /
 * transport_location_id is set, per destination_type (see
 * TransportRecordRequest::withValidator()).
 */
class TransportRecord extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    const DESTINATION_PROJECT = 'Project';

    const DESTINATION_WAREHOUSE = 'Warehouse';

    /** Several Main Warehouses in one trip (ids kept in departure_warehouse_ids / destination_warehouse_ids). */
    const DESTINATION_MIXED = 'Mixed Main Warehouses';

    const DESTINATION_SUPPLIER = 'Supplier';

    const DESTINATION_OFFICE = 'Office';

    const DESTINATION_CLIENT = 'Client';

    const DESTINATION_SITE = 'Site';

    const DESTINATION_WORKSHOP = 'Workshop';

    const DESTINATION_PORT = 'Port';

    const DESTINATION_AIRPORT = 'Airport';

    const DESTINATION_GOVERNMENT_OFFICE = 'Government Office';

    const DESTINATION_OTHER = 'Other Location';

    const DESTINATION_TYPES = [
        self::DESTINATION_PROJECT, self::DESTINATION_WAREHOUSE, self::DESTINATION_MIXED, self::DESTINATION_SUPPLIER,
        self::DESTINATION_OFFICE, self::DESTINATION_CLIENT, self::DESTINATION_SITE,
        self::DESTINATION_WORKSHOP, self::DESTINATION_PORT, self::DESTINATION_AIRPORT,
        self::DESTINATION_GOVERNMENT_OFFICE, self::DESTINATION_OTHER,
    ];

    /** destination_types that resolve to warehouse_id. */
    const WAREHOUSE_DESTINATION_TYPES = [self::DESTINATION_PROJECT, self::DESTINATION_WAREHOUSE];

    /** destination_types that resolve to destination_contact_id. */
    const CONTACT_DESTINATION_TYPES = [self::DESTINATION_SUPPLIER, self::DESTINATION_CLIENT];

    /** destination_types that resolve to transport_location_id. */
    const LOCATION_DESTINATION_TYPES = [
        self::DESTINATION_OFFICE, self::DESTINATION_SITE, self::DESTINATION_WORKSHOP,
        self::DESTINATION_PORT, self::DESTINATION_AIRPORT, self::DESTINATION_GOVERNMENT_OFFICE,
        self::DESTINATION_OTHER,
    ];

    /** One record may stand for 1 to this many identical trips. */
    const MAX_TRIPS = 50;

    /** "Site Campus" route: a movement inside one big site, so the departure types that are a place on a site. */
    const CAMPUS_TYPES = [self::DESTINATION_PROJECT, self::DESTINATION_SITE];

    const STATUS_UNPAID = 'Unpaid';

    const STATUS_PENDING_PAYMENT = 'Pending Payment';

    const STATUS_PARTIALLY_PAID = 'Partially Paid';

    const STATUS_PAID = 'Paid';

    const STATUS_CANCELLED = 'Cancelled';

    const PAYMENT_STATUSES = [
        self::STATUS_UNPAID, self::STATUS_PENDING_PAYMENT, self::STATUS_PARTIALLY_PAID,
        self::STATUS_PAID, self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'trip_number', 'transfer_id', 'external_movement_id', 'trip_date', 'trip_time', 'asset_id', 'driver_contact_id',
        'departure_type', 'departure_warehouse_id', 'departure_warehouse_ids', 'departure_contact_id', 'departure_transport_location_id', 'departure_label',
        'destination_type', 'warehouse_id', 'destination_warehouse_ids', 'destination_contact_id', 'transport_location_id', 'destination_label', 'is_campus',
        'departure_location', 'destination_location', 'delivery_note_ref', 'purpose',
        'default_amount', 'trips_count', 'unit_amount', 'amount', 'amount_overridden', 'amount_change_reason',
        'amount_changed_by_user_id', 'amount_changed_at',
        'verified_at', 'verified_by_user_id',
        'payment_status', 'paid_amount', 'payment_date', 'paid_by_user_id',
        'payment_reference', 'payment_method', 'payment_notes', 'payment_voucher_id',
        'notes', 'created_by_user_id',
    ];

    public $casts = [
        'trip_date' => 'date',
        'amount_overridden' => 'boolean',
        'is_campus' => 'boolean',
        'trips_count' => 'integer',
        'amount_changed_at' => 'datetime',
        'verified_at' => 'datetime',
        'payment_date' => 'date',
        'extra_attributes' => 'array',
        'departure_warehouse_ids' => 'array',
        'destination_warehouse_ids' => 'array',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function driver()
    {
        return $this->belongsTo(Contact::class, 'driver_contact_id');
    }

    /** The Transfer this trip was generated from (null for a trip that was logged on its own). */
    public function transfer()
    {
        return $this->belongsTo(Transfer::class)->withTrashed();
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function destinationContact()
    {
        return $this->belongsTo(Contact::class, 'destination_contact_id');
    }

    public function departureWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'departure_warehouse_id');
    }

    public function departureContact()
    {
        return $this->belongsTo(Contact::class, 'departure_contact_id');
    }

    public function departureTransportLocation()
    {
        return $this->belongsTo(TransportLocation::class, 'departure_transport_location_id');
    }

    public function transportLocation()
    {
        return $this->belongsTo(TransportLocation::class);
    }

    public function paymentVoucher()
    {
        return $this->belongsTo(PaymentVoucher::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function amountChangedBy()
    {
        return $this->belongsTo(User::class, 'amount_changed_by_user_id');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * Trips eligible to be selected into a new Payment Voucher — must be
     * verified first (see TransportRecordController::verify()), matching
     * the JS-side Records/Index.vue::isEligible() check, which additionally
     * requires payment_status === 'Unpaid'.
     */
    public function scopeUnpaidAvailableForVoucher($query)
    {
        return $query->whereNull('payment_voucher_id')
            ->where('payment_status', '!=', self::STATUS_CANCELLED)
            ->whereNotNull('verified_at');
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('trip_number', 'like', "%{$search}%")
                    ->orWhere('destination_label', 'like', "%{$search}%")
                    ->orWhere('departure_label', 'like', "%{$search}%")
                    ->orWhereHas('asset', fn ($q) => $q->where('registration_number', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                    ->orWhereHas('driver', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            });
        })->when($filters['asset_id'] ?? null, function ($query, $assetId) {
            $query->where('asset_id', $assetId);
        })->when($filters['driver_contact_id'] ?? null, function ($query, $driverId) {
            $query->where('driver_contact_id', $driverId);
        })->when($filters['warehouse_id'] ?? null, function ($query, $warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        })->when($filters['destination_type'] ?? null, function ($query, $type) {
            $query->where('destination_type', $type);
        })->when($filters['payment_status'] ?? null, function ($query, $status) {
            $query->where('payment_status', $status);
        })->when($filters['date_from'] ?? null, function ($query, $date) {
            $query->whereDate('trip_date', '>=', $date);
        })->when($filters['date_to'] ?? null, function ($query, $date) {
            $query->whereDate('trip_date', '<=', $date);
        });
    }

    /** Price of ONE trip (older records, saved before trips existed, are a single trip: unit = amount). */
    public function getUnitPriceAttribute(): float
    {
        return (float) ($this->unit_amount ?? $this->amount);
    }

    /** Outstanding balance for this trip — never negative. */
    public function getOutstandingAmountAttribute(): float
    {
        return max(0, (float) $this->amount - (float) $this->paid_amount);
    }

    /**
     * What payment_status should read given only paid_amount vs amount —
     * used whenever a trip is unlinked from a Payment Voucher (deleted,
     * rejected, or cancelled) so it falls back to what was actually paid
     * instead of being blindly reset to Unpaid over a trip that already
     * carried a partial (or full) payment before that voucher ever existed.
     */
    public function recomputedPaymentStatus(): string
    {
        if ((float) $this->paid_amount <= 0) {
            return self::STATUS_UNPAID;
        }

        return (float) $this->paid_amount >= (float) $this->amount - 0.001
            ? self::STATUS_PAID
            : self::STATUS_PARTIALLY_PAID;
    }

    /** The next free trip number (TRP-000001 ...). Callers that insert straight away should hold ReferenceLock::run('transport_records', ...). */
    public static function nextTripNumber(): string
    {
        $maxSuffix = (int) \Illuminate\Support\Facades\DB::table('transport_records')
            ->selectRaw('MAX(CAST(SUBSTRING(trip_number, 5) AS UNSIGNED)) as max_suffix')
            ->where('trip_number', 'like', 'TRP-%')
            ->value('max_suffix');

        return 'TRP-' . str_pad((string) ($maxSuffix + 1), 6, '0', STR_PAD_LEFT);
    }
}
