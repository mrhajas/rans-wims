<?php

namespace App\Services\AiTools;

use App\Models\Asset;
use App\Models\TransportRecord;
use App\Models\User;
use App\Services\AiTools\Concerns\ResolvesEntities;

class TransportTool
{
    use ResolvesEntities;

    public function tripCountForVehicle(array $input, User $user): array
    {
        $vehicle = $this->resolveVehicle($input['vehicle_name'] ?? null);
        if (! $vehicle) {
            return ['error' => 'not_found', 'message' => 'I could not find a vehicle matching that name/registration.'];
        }

        $query = TransportRecord::where('asset_id', $vehicle->id);
        $this->applyDateRange($query, $input);

        // trip_count covers every trip regardless of status; total_amount
        // excludes Cancelled and not-yet-verified trips, the same rule
        // every money aggregate elsewhere in this module applies (a voided
        // trip never incurred a real cost, an unverified one isn't
        // confirmed yet) — see TransportReportController::totals().
        $activeQuery = (clone $query)->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)->whereNotNull('verified_at');

        return [
            'found' => true,
            'vehicle' => $vehicle->registration_number ?? $vehicle->name,
            'trip_count' => (int) (clone $query)->sum('trips_count'),
            'total_amount' => (float) $activeQuery->sum('amount'),
        ];
    }

    public function mostActiveVehicle(array $input, User $user): array
    {
        $query = TransportRecord::query();
        $this->applyDateRange($query, $input);

        // Same Cancelled/unverified exclusion as every other money
        // aggregate in this module — a cancelled or unconfirmed trip
        // shouldn't count toward "most active" or its reported spend.
        $top = (clone $query)
            ->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)
            ->whereNotNull('verified_at')
            ->selectRaw('asset_id, SUM(trips_count) as trip_count, SUM(amount) as total_amount')
            ->whereNotNull('asset_id')
            ->groupBy('asset_id')
            ->orderByDesc('trip_count')
            ->with('asset:id,name,registration_number')
            ->limit(5)
            ->get();

        if ($top->isEmpty()) {
            return ['found' => false, 'message' => 'No transport trips recorded for that period.'];
        }

        return [
            'found' => true,
            'vehicles' => $top->map(fn ($row) => [
                'vehicle' => optional($row->asset)->registration_number ?? optional($row->asset)->name,
                'trip_count' => (int) $row->trip_count,
                'total_amount' => (float) $row->total_amount,
            ])->values()->all(),
        ];
    }

    /**
     * "How much transport is outstanding for Project A?" / "How much was
     * spent on transport to Warehouse B?" — a Project and a Warehouse are
     * the same underlying model in this app (see Warehouse::scopeMainWarehouses()),
     * so one resolver covers both destination types.
     */
    public function transportCostByProjectOrWarehouse(array $input, User $user): array
    {
        $warehouse = $this->resolveWarehouse($input['project_or_warehouse_name'] ?? null);
        if (! $warehouse) {
            return ['error' => 'not_found', 'message' => 'I could not find a project or warehouse matching that name.'];
        }

        $scope = $this->resolveWarehouseScope($warehouse);
        if ($scope['denied']) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to that project/warehouse.'];
        }

        $query = TransportRecord::where('warehouse_id', $warehouse->id)
            ->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)
            ->whereNotNull('verified_at');
        $this->applyDateRange($query, $input);

        return [
            'found' => true,
            'project_or_warehouse' => $warehouse->name,
            'trip_count' => (int) (clone $query)->sum('trips_count'),
            'total_amount' => (float) (clone $query)->sum('amount'),
            'total_paid' => (float) (clone $query)->sum('paid_amount'),
            'total_outstanding' => (float) (clone $query)->sum(\Illuminate\Support\Facades\DB::raw('amount - paid_amount')),
        ];
    }

    /** "Show unpaid transport records for Project X." — omit the name for a company-wide unpaid list. */
    public function unpaidTransportRecords(array $input, User $user): array
    {
        $query = TransportRecord::where('payment_status', TransportRecord::STATUS_UNPAID)
            ->with(['asset:id,name,registration_number', 'warehouse:id,name']);

        if (! empty($input['project_or_warehouse_name'])) {
            $warehouse = $this->resolveWarehouse($input['project_or_warehouse_name']);
            if (! $warehouse) {
                return ['error' => 'not_found', 'message' => 'I could not find a project or warehouse matching that name.'];
            }
            $scope = $this->resolveWarehouseScope($warehouse);
            if ($scope['denied']) {
                return ['error' => 'not_permitted', 'message' => 'You do not have access to that project/warehouse.'];
            }
            $query->where('warehouse_id', $warehouse->id);
        }

        $rows = $query->orderByDesc('trip_date')->limit(15)->get();

        return [
            'found' => true,
            'count' => $rows->count(),
            'trips' => $rows->map(fn (TransportRecord $r) => [
                'trip_number' => $r->trip_number,
                'date' => optional($r->trip_date)->format('Y-m-d'),
                'vehicle' => optional($r->asset)->registration_number,
                'destination' => $r->destination_label,
                'amount' => (float) $r->amount,
            ])->values()->all(),
        ];
    }

    private function resolveVehicle(?string $name): ?Asset
    {
        if (! $name) {
            return null;
        }
        $name = trim($name);

        return Asset::vehicles()
            ->where(fn ($q) => $q->where('registration_number', 'like', '%' . $name . '%')->orWhere('name', 'like', '%' . $name . '%'))
            ->first();
    }

    private function applyDateRange($query, array $input): void
    {
        if (! empty($input['date_from'])) {
            $from = $this->parseDate($input['date_from']);
            if ($from) {
                $query->whereDate('trip_date', '>=', $from->toDateString());
            }
        }
        if (! empty($input['date_to'])) {
            $to = $this->parseDate($input['date_to']);
            if ($to) {
                $query->whereDate('trip_date', '<=', $to->toDateString());
            }
        }
        if (! empty($input['this_month']) && $input['this_month']) {
            $query->whereYear('trip_date', now()->year)->whereMonth('trip_date', now()->month);
        }
    }
}
