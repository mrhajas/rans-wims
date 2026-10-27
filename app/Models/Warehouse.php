<?php

namespace App\Models;

use App\Traits\HasAttachments;

class Warehouse extends Model
{
    use HasAttachments;

    /**
     * A new project / warehouse is given to the Super Admins of its account who already have a list of warehouses, so nobody has to tick it for
     * them later. A Super Admin with NO list sees every warehouse (accessible_warehouse_ids() treats an empty list as "no limit"): giving that
     * person the new warehouse would put a list of ONE warehouse on them and every page (Items, Check-ins, Check-outs, Transfers ...) would show
     * only that project, which looked like all the data had disappeared.
     */
    protected static function booted(): void
    {
        parent::booted(); // keeps the account scope and the account_id / deleted_by hooks of the base Model

        static::created(function (Warehouse $warehouse) {
            try {
                $ids = User::withoutGlobalScopes()->role('Super Admin')
                    ->when($warehouse->account_id, fn ($q) => $q->where('account_id', $warehouse->account_id))
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('user_warehouse')->whereColumn('user_warehouse.user_id', 'users.id'))
                    ->pluck('users.id');
                if ($ids->isNotEmpty()) {
                    $warehouse->users()->syncWithoutDetaching($ids->all());
                }
                \App\Support\AllWarehouseRoles::attachNew($warehouse);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Could not attach the new warehouse to Super Admins', ['warehouse_id' => $warehouse->id, 'error' => $e->getMessage()]);
            }
        });
    }

    public $casts = [
        'extra_attributes' => 'array',
        'start_date' => 'date',
        'expected_end_date' => 'date',
        'site_budget' => 'decimal:2',
        'estimated_workforce' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'completed_at' => 'datetime',
    ];

    public function checkins()
    {
        return $this->hasMany(Checkin::class);
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function certificateContract()
    {
        return $this->hasOne(\App\Models\ProjectCertificateContract::class);
    }

    public function getIsCompletedAttribute()
    {
        return ! is_null($this->completed_at);
    }

    public function checkouts()
    {
        return $this->hasMany(Checkout::class);
    }

    /**
     * material_requests.warehouse_id and project_certificates/
     * project_certificate_contracts.warehouse_id all use cascadeOnDelete() —
     * without this guard, deleting a warehouse with no checkins/checkouts
     * (plausible for a project managed purely through certificates, or one
     * whose Material Requests were never issued against) silently hard-
     * deletes every Material Request and every issued certificate/payment/
     * addendum for that project, with no warning and no recovery path.
     */
    public function hasFinancialHistory(): bool
    {
        return $this->materialRequests()->exists()
            || $this->certificateContract()->exists()
            || \App\Models\ProjectCertificate::where('warehouse_id', $this->id)->exists();
    }

    public function del()
    {
        if ($this->checkins()->exists() || $this->checkouts()->exists() || $this->hasFinancialHistory()) {
            return false;
        }

        $this->stock()->delete();

        return $this->delete();
    }

    public function delP()
    {
        if ($this->checkins()->exists() || $this->checkouts()->exists() || $this->hasFinancialHistory()) {
            return false;
        }

        log_activity(__choice('delete_text', ['record' => 'Warehouse']), $this, $this, 'Warehouse');
        $this->stock()->forceDelete();

        return $this->forceDelete();
    }

    public function items()
    {
        return $this->hasManyThrough(Item::class, Stock::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    public function scopeMainWarehouses($query)
    {
        return $query->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses']);
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('code', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('stage', 'like', '%' . $search . '%')
                    ->orWhere('address', 'like', '%' . $search . '%')
                    ->orWhere('for_who', 'like', '%' . $search . '%');
            });
        })->when($filters['category'] ?? null, fn ($q, $v) => $q->where('status', $v)
        )->when($filters['stage'] ?? null, fn ($q, $v) => $v === '__none' ? $q->where(fn ($w) => $w->whereNull('stage')->orWhere('stage', '')) : $q->where('stage', $v)
        )->when($filters['state'] ?? null, function ($q, $v) {
            if ($v === 'active') {
                $q->where('active', 1);
            } elseif ($v === 'inactive') {
                $q->where('active', 0);
            } elseif ($v === 'completed') {
                $q->whereNotNull('completed_at');
            }
        })->when($filters['trashed'] ?? null, function ($query, $trashed) {
            if ($trashed === 'with') {
                $query->withTrashed();
            } elseif ($trashed === 'only') {
                $query->onlyTrashed();
            }
        });
    }

    public function scopeOfAccount($query, $account = null)
    {
        return $query->where('account_id', $account ?? auth()->user()->account_id);
    }

    public function scopeVisible($query)
    {
        $user = auth()->user();
        if ($user && $user->hasRole('Super Admin')) {
            return $query;
        }

        return $query->whereNull('completed_at');
    }

    public function stock()
    {
        return $this->hasMany(Stock::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_warehouse');
    }

    public function materialRequests()
    {
        return $this->hasMany(MaterialRequest::class);
    }

    public function materialReallocationsAsSource()
    {
        return $this->hasMany(MaterialReallocation::class, 'source_warehouse_id');
    }

    public function workPackages()
    {
        return $this->hasMany(WorkPackage::class);
    }

    public function variationStock()
    {
        return $this->hasMany(VariationStock::class);
    }
}
