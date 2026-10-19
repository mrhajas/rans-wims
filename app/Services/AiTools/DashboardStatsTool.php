<?php

namespace App\Services\AiTools;

use App\Models\User;
use App\Models\Warehouse;
use App\Services\AiTools\Concerns\ResolvesEntities;
use Illuminate\Support\Facades\DB;

/**
 * Company-wide/portfolio KPIs — formulas mirror
 * ExecutiveReportMetricsService exactly (that service's query methods are
 * `protected`, so these are independent equivalent queries rather than
 * calls into it). Every result is scoped to the asking user's accessible
 * warehouses, same as every other AI tool.
 */
class DashboardStatsTool
{
    use ResolvesEntities;

    protected const PROJECT_STATUSES = ['Private', 'Government', 'Directors', 'General'];

    public function activeProjectsCount(array $input, User $user): array
    {
        $accessible = accessible_warehouse_ids();

        $count = Warehouse::ofAccount()
            ->where('active', 1)
            ->visible()
            ->whereIn('status', self::PROJECT_STATUSES)
            ->when($accessible !== null, fn ($q) => $q->whereIn('id', $accessible))
            ->count();

        return ['found' => true, 'count' => $count];
    }

    public function totalStockValue(array $input, User $user): array
    {
        $warehouse = $this->resolveWarehouse($input['warehouse_name'] ?? null);
        $scope = $this->resolveWarehouseScope($warehouse);

        if ($scope['denied']) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to that warehouse.'];
        }

        $query = DB::table('stocks')
            ->join('items', 'stocks.item_id', '=', 'items.id')
            ->where('stocks.account_id', getAccountId());

        if ($scope['ids'] !== null) {
            $query->whereIn('stocks.warehouse_id', $scope['ids']);
        }

        $value = (float) $query->selectRaw('SUM(stocks.quantity * COALESCE(items.price, 0)) as total_value')->value('total_value');

        return [
            'found' => true,
            'scope' => $warehouse->name ?? 'All warehouses (company-wide)',
            'total_value' => $value,
        ];
    }

    public function topConsumingProjects(array $input, User $user): array
    {
        $accessible = accessible_warehouse_ids();
        [$start, $end] = $this->recentWindow();

        $rows = DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->join('items', 'checkout_items.item_id', '=', 'items.id')
            ->join('warehouses', 'checkouts.warehouse_id', '=', 'warehouses.id')
            ->where('checkouts.account_id', getAccountId())
            ->whereNull('checkouts.deleted_at')
            ->where(fn ($q) => $q->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0))
            ->whereBetween('checkouts.date', [$start, $end])
            ->when($accessible !== null, fn ($q) => $q->whereIn('checkouts.warehouse_id', $accessible))
            ->groupBy('checkouts.warehouse_id', 'warehouses.name')
            ->orderByDesc('value')
            ->limit(5)
            ->select(
                'warehouses.name as warehouse',
                DB::raw('SUM(checkout_items.quantity * COALESCE(checkout_items.price, items.price, 0)) as value')
            )
            ->get();

        return [
            'found' => true,
            'period' => "{$start->toDateString()} to {$end->toDateString()}",
            'count' => $rows->count(),
            'projects' => $rows->map(fn ($r) => ['warehouse' => $r->warehouse, 'value' => (float) $r->value])->all(),
        ];
    }

    public function topUsedMaterials(array $input, User $user): array
    {
        $accessible = accessible_warehouse_ids();
        [$start, $end] = $this->recentWindow();

        $rows = DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->join('items', 'checkout_items.item_id', '=', 'items.id')
            ->where('checkouts.account_id', getAccountId())
            ->whereNull('checkouts.deleted_at')
            ->where(fn ($q) => $q->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0))
            ->whereBetween('checkouts.date', [$start, $end])
            ->when($accessible !== null, fn ($q) => $q->whereIn('checkouts.warehouse_id', $accessible))
            ->groupBy('items.id', 'items.name')
            ->orderByDesc('quantity')
            ->limit(5)
            ->select(
                'items.name as item',
                DB::raw('SUM(checkout_items.quantity) as quantity')
            )
            ->get();

        return [
            'found' => true,
            'period' => "{$start->toDateString()} to {$end->toDateString()}",
            'count' => $rows->count(),
            'materials' => $rows->map(fn ($r) => ['item' => $r->item, 'quantity' => (float) $r->quantity])->all(),
        ];
    }

    protected function recentWindow(): array
    {
        return [now()->subDays(6)->startOfDay(), now()->endOfDay()];
    }
}
