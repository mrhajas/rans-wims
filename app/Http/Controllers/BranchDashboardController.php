<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use Inertia\Inertia;
use App\Models\Checkin;
use App\Models\Checkout;
use App\Models\Transfer;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class BranchDashboardController extends Controller
{
    // Access is scoped to the user's own warehouses inside index(); no per-action permission needed.
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $user = $request->user();

        // Warehouses the user manages (admins manage all active warehouses).
        $managed = $user->warehouses()->where('active', 1)->visible()->pluck('warehouses.id');
        if ($managed->isEmpty() && $user->hasRole(['Super Admin', 'Admin', 'Procurement Manager', 'Procurement Manger', 'Store Manager', 'Store Manger'])) {
            $managed = Warehouse::active()->visible()->pluck('id');
        }

        if ($managed->isEmpty()) {
            return Inertia::render('Branch/Index', [
                'warehouse'  => null,
                'aggregate'  => false,
                'managedBy'  => [],
                'items'      => [],
                'checkins'   => [],
                'checkouts'  => [],
                'transfers'  => [],
            ]);
        }

        // A specific warehouse is selected in the top bar, otherwise show all the
        // warehouses the user manages combined ("All Warehouses").
        $selected = active_warehouse_id();
        $single = $selected && $managed->contains($selected);
        $warehouseIds = $single ? [$selected] : $managed->all();

        if ($single) {
            $warehouse = Warehouse::with('users:id,name')->find($selected);
            $header = $warehouse->only(['id', 'code', 'name']);
            $managedBy = $warehouse->users->pluck('name')->values();
        } else {
            $header = ['id' => null, 'code' => null, 'name' => __('All Warehouses')];
            $managedBy = Warehouse::whereIn('id', $warehouseIds)->with('users:id,name')->get()
                ->pluck('users')->flatten()->pluck('name')->filter()->unique()->values();
        }

        // Items currently in these warehouses, most recently moved on top.
        $items = Stock::main()->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity', '>', 0)
            ->with('item:id,code,name,price,unit_id')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn ($s) => [
                'item_id'   => $s->item_id,
                'code'      => optional($s->item)->code,
                'name'      => optional($s->item)->name,
                'unit'      => optional(optional($s->item)->unit)->code,
                'quantity'  => $s->quantity,
                'rack'      => $s->rack_location,
                'warehouse' => optional($s->warehouse)->name,
            ])->values();

        return Inertia::render('Branch/Index', [
            'warehouse'  => $header,
            'aggregate'  => ! $single,
            'managedBy'  => $managedBy,
            'items'      => $items,
            'checkins'   => $this->transactions(
                Checkin::withoutGlobalScope('mine')->whereIn('warehouse_id', $warehouseIds)
                    ->where($this->notDraft())
                    ->with(['contact:id,name', 'user:id,name'])
            ),
            'checkouts'  => $this->transactions(
                Checkout::withoutGlobalScope('mine')->whereIn('warehouse_id', $warehouseIds)
                    ->where($this->notDraft())
                    ->with(['contact:id,name', 'user:id,name'])
            ),
            'transfers'  => $this->transactions(
                Transfer::withoutGlobalScope('mine')
                    ->where($this->notDraft())
                    ->where(fn ($q) => $q->whereIn('from_warehouse_id', $warehouseIds)->orWhereIn('to_warehouse_id', $warehouseIds))
                    ->with(['fromWarehouse:id,name', 'toWarehouse:id,name', 'user:id,name']),
                true
            ),
        ]);
    }

    // Grouped "not a draft" filter (draft is null or 0) so it composes safely with OR clauses.
    private function notDraft(): \Closure
    {
        return fn ($q) => $q->whereNull('draft')->orWhere('draft', 0);
    }

    private function transactions($query, bool $isTransfer = false)
    {
        return $query->orderByDesc('id')->limit(15)->get()->map(function ($t) use ($isTransfer) {
            return [
                'id'        => $t->id,
                'reference' => $t->reference,
                'date'      => $t->date,
                'user'      => optional($t->user)->name,
                'relation'  => $isTransfer
                    ? trim(optional($t->fromWarehouse)->name . ' → ' . optional($t->toWarehouse)->name, ' →')
                    : optional($t->contact)->name,
            ];
        })->values();
    }
}
