<?php

namespace App\Models;

use App\Traits\HasAttachments;

class Asset extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUSES = [
        'Active', 'Available', 'Assigned', 'In Use', 'Under Maintenance', 'Under Repair',
        'In Transit', 'Lost', 'Stolen', 'Damaged', 'Idle', 'Disposed', 'Sold', 'Written Off', 'Retired',
    ];

    public const DISPOSED_STATUSES = ['Disposed', 'Sold', 'Written Off', 'Lost', 'Stolen', 'Retired'];

    public const CONDITIONS = [
        'New', 'Excellent', 'Good', 'Fair', 'Damaged', 'Under Repair', 'Unserviceable', 'Scrapped',
    ];

    public const OWNERSHIP_TYPES = [
        'Company Owned', 'Leased', 'Rented', 'Financed', 'Government/Third-Party', 'Other',
    ];

    public const ASSIGNED_TO_TYPES = [
        'Employee', 'Department', 'Project', 'Site', 'Warehouse', 'Vehicle Operator', 'Other',
    ];

    public const VEHICLE_MACHINERY_CATEGORY_GROUPS = ['Vehicles', 'Construction Machinery', 'Plant & Equipment'];

    /**
     * Narrower than VEHICLE_MACHINERY_CATEGORY_GROUPS above — Transport
     * Management's Vehicle Register is specifically about trip-making road
     * vehicles (Cars/Pickups/Trucks/Lorries/Buses/Motorcycles/Trailers, see
     * the 'Vehicles' asset-category tree seeded in
     * 2026_08_26_100200_seed_asset_categories.php), not excavators or plant
     * equipment. See scopeVehicles() below for the query-level equivalent
     * of getIsVehicleOrMachineryAttribute()'s per-row check.
     */
    public const TRANSPORT_VEHICLE_CATEGORY_NAME = 'Vehicles';

    public const OPERATING_PERSONNEL_TYPE_STAFF = 'Staff';

    public const OPERATING_PERSONNEL_TYPE_CONTACT = 'Contact';

    public const OPERATING_PERSONNEL_TYPES = [self::OPERATING_PERSONNEL_TYPE_STAFF, self::OPERATING_PERSONNEL_TYPE_CONTACT];

    /** Which module an asset belongs to: the Asset Register or Transport Vehicles (never both). */
    public const REGISTERED_IN_ASSETS = 'assets';

    public const REGISTERED_IN_TRANSPORT = 'transport';

    public const ASSET_TYPE_FIXED = 'Fixed Asset';

    public const ASSET_TYPE_UNFIXED = 'UnFixed Asset';

    public const ASSET_TYPES = [self::ASSET_TYPE_FIXED, self::ASSET_TYPE_UNFIXED];

    public const MAX_PHOTOS = 3;

    public const FUEL_TYPES = ['Petrol', 'Diesel', 'Electric', 'Hybrid', 'CNG', 'LPG'];

    public $casts = [
        'acquisition_date' => 'date',
        'lease_start_date' => 'date',
        'lease_end_date' => 'date',
        'assignment_date' => 'date',
        'expected_return_date' => 'date',
        'depreciation_start_date' => 'date',
        'warranty_start' => 'date',
        'warranty_end' => 'date',
        'insurance_start' => 'date',
        'insurance_expiry' => 'date',
        'next_service_date' => 'date',
        'registration_expiry' => 'date',
        'inspection_expiry' => 'date',
        'license_expiry' => 'date',
        'disposed_at' => 'datetime',
        'extra_attributes' => 'array',
        'opening_balance_date' => 'date',
        'opening_balance_set_at' => 'datetime',
        'quantity' => 'float',
    ];

    public function category()
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function paymentVoucher()
    {
        return $this->belongsTo(PaymentVoucher::class);
    }

    public function currentWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'current_warehouse_id');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function openingBalanceSetBy()
    {
        return $this->belongsTo(User::class, 'opening_balance_set_by_user_id');
    }

    public function operatingPersonnel()
    {
        return $this->belongsTo(Staff::class, 'operating_personnel_staff_id');
    }

    public function operatingPersonnelContact()
    {
        return $this->belongsTo(Contact::class, 'operating_personnel_contact_id');
    }

    /** Whichever of the two is actually set, given operating_personnel_type — the one Show.vue and presentDetail() should read, since either is just a name-bearing record. */
    public function getOperatingPersonnelResolvedAttribute()
    {
        return $this->operating_personnel_type === self::OPERATING_PERSONNEL_TYPE_CONTACT
            ? $this->operatingPersonnelContact
            : $this->operatingPersonnel;
    }

    public function photos()
    {
        return $this->hasMany(AssetPhoto::class)->orderBy('position')->orderBy('id');
    }

    public function movements()
    {
        return $this->hasMany(AssetMovement::class)->orderByDesc('movement_date')->orderByDesc('id');
    }

    public function maintenanceRecords()
    {
        return $this->hasMany(AssetMaintenanceRecord::class)->orderByDesc('maintenance_date');
    }

    public function depreciationEntries()
    {
        return $this->hasMany(AssetDepreciationEntry::class)->orderByDesc('period_end');
    }

    public function appreciationEntries()
    {
        return $this->hasMany(AssetAppreciationEntry::class)->orderByDesc('period_end');
    }

    public function disposal()
    {
        return $this->hasOne(AssetDisposal::class);
    }

    public function transportRecords()
    {
        return $this->hasMany(TransportRecord::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', self::DISPOSED_STATUSES);
    }

    /**
     * Assets under the 'Vehicles' category tree (the top-level category
     * itself plus its children) — the query-level equivalent of
     * getIsVehicleOrMachineryAttribute()'s per-row parent-or-self name
     * check, scoped down to just Vehicles rather than the broader
     * VEHICLE_MACHINERY_CATEGORY_GROUPS. Used by TransportVehicleController
     * so the Transport Routes Vehicle Register only ever shows/creates
     * assets in this tree, never construction machinery.
     */
    public function scopeVehicles($query)
    {
        $categoryIds = AssetCategory::query()
            ->where('name', self::TRANSPORT_VEHICLE_CATEGORY_NAME)
            ->orWhereHas('parent', fn ($q) => $q->where('name', self::TRANSPORT_VEHICLE_CATEGORY_NAME))
            ->pluck('id');

        return $query->whereIn('asset_category_id', $categoryIds)->where($query->getModel()->getTable() . '.registered_in', self::REGISTERED_IN_TRANSPORT);
    }

    /**
     * The Asset Register's own assets: everything not registered through Transport Vehicles. (Transport vehicles show
     * under Transport Vehicles only - see scopeVehicles().)
     */
    public function scopeInAssetRegister($query)
    {
        return $query->where(fn ($q) => $q->whereNull('registered_in')->orWhere('registered_in', '!=', self::REGISTERED_IN_TRANSPORT));
    }

    public const SORTABLE = ['asset_number', 'category', 'asset_type', 'location', 'responsible', 'status', 'quantity', 'unit_price', 'total_cost', 'book_value'];

    /**
     * Column sorting for the Asset Register table (and its print/PDF, so a printout keeps the on-screen order).
     * Unknown column → the given default order. Book value mirrors getCurrentBookValueAttribute():
     * latest depreciation closing value (else total capitalised cost), never below the residual value.
     */
    public function scopeSortBy($query, ?string $sort, ?string $direction, string $defaultColumn = 'id', string $defaultDirection = 'desc')
    {
        if (! in_array($sort, self::SORTABLE, true)) {
            return $query->orderBy($defaultColumn, $defaultDirection);
        }

        $dir = strtolower((string) $direction) === 'asc' ? 'asc' : 'desc';

        switch ($sort) {
            case 'category':
                $query->orderBy(AssetCategory::query()->select('name')->whereColumn('asset_categories.id', 'assets.asset_category_id')->limit(1), $dir);
                break;
            case 'location':
                $query->orderByRaw('COALESCE((select name from warehouses where warehouses.id = assets.current_warehouse_id limit 1), assets.current_location_label) ' . $dir);
                break;
            case 'responsible':
                $query->orderBy(User::query()->select('name')->whereColumn('users.id', 'assets.assigned_to_user_id')->limit(1), $dir);
                break;
            case 'unit_price':
                $query->orderByRaw('(assets.total_capitalised_cost / GREATEST(COALESCE(assets.quantity, 1), 0.001)) ' . $dir);
                break;
            case 'total_cost':
                $query->orderBy('assets.total_capitalised_cost', $dir);
                break;
            case 'quantity':
                $query->orderBy('assets.quantity', $dir);
                break;
            case 'book_value':
                $query->orderByRaw('GREATEST(COALESCE((select closing_book_value from asset_depreciation_entries where asset_depreciation_entries.asset_id = assets.id order by period_end desc, id desc limit 1), assets.total_capitalised_cost, 0), COALESCE(assets.residual_value, 0)) ' . $dir);
                break;
            default:
                $query->orderBy($sort, $dir);
        }

        return $query->orderBy('assets.id', 'desc');
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when($filters['asset_category_id'] ?? null, fn ($q, $id) => $q->where('asset_category_id', $id))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('current_warehouse_id', $id))
            ->when($filters['assigned_to_user_id'] ?? null, fn ($q, $id) => $q->where('assigned_to_user_id', $id))
            ->when($filters['asset_type'] ?? null, fn ($q, $t) => $q->where('asset_type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['condition'] ?? null, fn ($q, $c) => $q->where('condition', $c))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['acquisition_date_from'] ?? null, fn ($q, $d) => $q->whereDate('acquisition_date', '>=', $d))
            ->when($filters['acquisition_date_to'] ?? null, fn ($q, $d) => $q->whereDate('acquisition_date', '<=', $d))
            ->when($filters['value_min'] ?? null, fn ($q, $v) => $q->where('acquisition_cost', '>=', $v))
            ->when($filters['value_max'] ?? null, fn ($q, $v) => $q->where('acquisition_cost', '<=', $v))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('asset_number', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('serial_number', 'like', "%{$search}%")
                        ->orWhere('registration_number', 'like', "%{$search}%")
                        ->orWhere('tag_number', 'like', "%{$search}%");
                });
            });
    }

    public function getCurrentBookValueAttribute()
    {
        $latest = $this->relationLoaded('depreciationEntries')
            ? $this->depreciationEntries->first()
            : $this->depreciationEntries()->first();

        $value = $latest ? (float) $latest->closing_book_value : (float) $this->total_capitalised_cost;

        return max($value, (float) $this->residual_value);
    }

    public function getCurrentAppreciatedValueAttribute()
    {
        $latest = $this->relationLoaded('appreciationEntries')
            ? $this->appreciationEntries->first()
            : $this->appreciationEntries()->first();

        return $latest ? (float) $latest->closing_value : (float) $this->total_capitalised_cost;
    }

    public function getWarrantyExpiringSoonAttribute()
    {
        return $this->warranty_end && now()->diffInDays($this->warranty_end, false) <= 30 && now()->diffInDays($this->warranty_end, false) >= 0;
    }

    public function getInsuranceExpiringSoonAttribute()
    {
        return $this->insurance_expiry && now()->diffInDays($this->insurance_expiry, false) <= 30 && now()->diffInDays($this->insurance_expiry, false) >= 0;
    }

    public function getIsVehicleOrMachineryAttribute()
    {
        $group = optional(optional($this->category)->parent)->name ?? optional($this->category)->name;

        return in_array($group, self::VEHICLE_MACHINERY_CATEGORY_GROUPS, true);
    }
}
