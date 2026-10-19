<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Warehouse;
use App\Services\ExecutiveReportConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Computes the "Key Metrics at a Glance" section of the weekly Executive
 * Intelligence Report: inventory value, week-over-week transaction volume,
 * and the top consuming projects / most-used materials for the period.
 *
 * Reuses the same query shapes as ReportController::index(), just re-windowed
 * to a 7-day period (this week vs. the prior 7 days) instead of a full year.
 */
class ExecutiveReportMetricsService
{
    /**
     * Warehouse "type" statuses that count as an actual project/site for
     * this report — excludes storage-only types like Main Warehouse / Rans
     * Warehouse, which aren't projects in the sense management cares about here.
     */
    protected const PROJECT_STATUSES = ['Private', 'Government', 'Directors', 'General'];

    /** What the report covers (projects, items, transaction types, list sizes); every project / item / type unless set in Executive Report > Report Content. */
    protected ExecutiveReportConfig $cfg;

    public function build(int $accountId, Carbon $weekStart, Carbon $weekEnd, ?ExecutiveReportConfig $cfg = null): array
    {
        $this->cfg = $cfg ?? ExecutiveReportConfig::make([]);
        $priorStart = $weekStart->copy()->subDays(7);
        $priorEnd = $weekStart->copy()->subSecond();

        return [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'total_inventory_value' => $this->totalInventoryValue($accountId),
            'active_projects' => (int) Warehouse::where('account_id', $accountId)
                ->where('active', 1)
                ->visible()
                ->whereIn('status', self::PROJECT_STATUSES)
                ->when($this->cfg->projectIds(), fn ($q, $ids) => $q->whereIn('id', $ids))
                ->count(),
            'transaction_counts' => $this->transactionCounts($accountId, $weekStart, $weekEnd, $priorStart, $priorEnd),
            'consumption_value' => $this->consumptionValue($accountId, $weekStart, $weekEnd, $priorStart, $priorEnd),
            'month_to_date' => $this->monthToDate($accountId, $weekEnd),
            'top_consuming_projects' => $this->topConsumingProjects($accountId, $weekStart, $weekEnd),
            'top_used_materials' => $this->topUsedMaterials($accountId, $weekStart, $weekEnd),
            'portfolio_overview' => $this->portfolioOverview($accountId),
        ];
    }

    protected function totalInventoryValue(int $accountId): float
    {
        return (float) (DB::table('stocks')
            ->join('items', 'stocks.item_id', '=', 'items.id')
            ->where('stocks.account_id', $accountId)
            ->when($this->cfg->projectIds(), fn ($q, $ids) => $q->whereIn('stocks.warehouse_id', $ids))
            ->when($this->cfg->itemIds(), fn ($q, $ids) => $q->whereIn('stocks.item_id', $ids))
            ->selectRaw('SUM(stocks.quantity * COALESCE(items.price, 0)) as total_value')
            ->value('total_value') ?? 0);
    }

    protected function transactionCounts(int $accountId, Carbon $weekStart, Carbon $weekEnd, Carbon $priorStart, Carbon $priorEnd): array
    {
        $counts = [];

        foreach ($this->cfg->transactions() as $table) {
            $thisWeek = (int) DB::table($table)
                ->where('account_id', $accountId)
                ->when($this->cfg->projectIds(), fn ($q, $ids) => $this->scopeToProjects($q, $table, $ids))
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('draft')->orWhere('draft', 0))
                ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                ->count();

            $priorWeek = (int) DB::table($table)
                ->where('account_id', $accountId)
                ->when($this->cfg->projectIds(), fn ($q, $ids) => $this->scopeToProjects($q, $table, $ids))
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('draft')->orWhere('draft', 0))
                ->whereBetween('date', [$priorStart->toDateString(), $priorEnd->toDateString()])
                ->count();

            $counts[$table] = [
                'this_week' => $thisWeek,
                'prior_week' => $priorWeek,
                'change_percent' => $this->percentChange($priorWeek, $thisWeek),
            ];
        }

        return $counts;
    }

    protected function consumptionValue(int $accountId, Carbon $weekStart, Carbon $weekEnd, Carbon $priorStart, Carbon $priorEnd): array
    {
        $thisWeek = $this->checkoutValueBetween($accountId, $weekStart, $weekEnd);
        $priorWeek = $this->checkoutValueBetween($accountId, $priorStart, $priorEnd);

        return [
            'this_week' => $thisWeek,
            'prior_week' => $priorWeek,
            'change_percent' => $this->percentChange($priorWeek, $thisWeek),
        ];
    }

    protected function checkoutValueBetween(int $accountId, Carbon $start, Carbon $end): float
    {
        return (float) (DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->join('items', 'checkout_items.item_id', '=', 'items.id')
            ->where('checkouts.account_id', $accountId)
            ->whereNull('checkouts.deleted_at')
            ->where(fn ($q) => $q->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0))
            ->whereBetween('checkouts.date', [$start->toDateString(), $end->toDateString()])
            ->when($this->cfg->projectIds(), fn ($q, $ids) => $q->whereIn('checkouts.warehouse_id', $ids))
            ->when($this->cfg->itemIds(), fn ($q, $ids) => $q->whereIn('checkout_items.item_id', $ids))
            ->selectRaw('SUM(checkout_items.quantity * COALESCE(checkout_items.price, items.price, 0)) as total_value')
            ->value('total_value') ?? 0);
    }

    /**
     * Month-to-date consumption value vs. the same number of days into the
     * previous month — gives management a trajectory read, not just a single
     * week's snapshot which can be noisy for a low-transaction-volume company.
     */
    protected function monthToDate(int $accountId, Carbon $asOf): array
    {
        $monthStart = $asOf->copy()->startOfMonth();
        $daysElapsed = $monthStart->diffInDays($asOf) + 1;

        $priorMonthStart = $monthStart->copy()->subMonthNoOverflow();
        $priorMonthComparable = $priorMonthStart->copy()->addDays($daysElapsed - 1);

        $thisMonth = $this->checkoutValueBetween($accountId, $monthStart, $asOf);
        $priorMonthSamePoint = $this->checkoutValueBetween($accountId, $priorMonthStart, $priorMonthComparable);

        return [
            'month_label' => $asOf->format('F Y'),
            'this_month_to_date' => $thisMonth,
            'prior_month_same_point' => $priorMonthSamePoint,
            'change_percent' => $this->percentChange($priorMonthSamePoint, $thisMonth),
        ];
    }

    /**
     * Full active-project listing (stock value + completion %) — the "top 3"
     * elsewhere in this report is a highlight, this is the complete picture
     * management needs to make a portfolio-wide call rather than guessing
     * about anything not in the top 3.
     */
    protected function portfolioOverview(int $accountId): array
    {
        $sub = DB::table('stocks')
            ->join('items', 'stocks.item_id', '=', 'items.id')
            ->where('stocks.account_id', $accountId)
            ->when($this->cfg->itemIds(), fn ($q, $ids) => $q->whereIn('stocks.item_id', $ids))
            ->groupBy('stocks.warehouse_id')
            ->selectRaw('stocks.warehouse_id, SUM(stocks.quantity * COALESCE(items.price, 0)) as stock_value');

        return Warehouse::where('account_id', $accountId)
            ->where('active', 1)
            ->visible()
            ->whereIn('status', self::PROJECT_STATUSES)
            ->when($this->cfg->projectIds(), fn ($q, $ids) => $q->whereIn('warehouses.id', $ids))
            ->leftJoinSub($sub, 'stock_totals', fn ($join) => $join->on('warehouses.id', '=', 'stock_totals.warehouse_id'))
            ->select('warehouses.name', 'warehouses.completion_percentage')
            ->selectRaw('COALESCE(stock_totals.stock_value, 0) as stock_value')
            ->orderByDesc('stock_value')
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->name,
                'stock_value' => (float) $row->stock_value,
                'completion_percentage' => $row->completion_percentage !== null ? (int) $row->completion_percentage : null,
            ])
            ->values()
            ->all();
    }

    protected function topConsumingProjects(int $accountId, Carbon $weekStart, Carbon $weekEnd): array
    {
        $sub = DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->join('items', 'checkout_items.item_id', '=', 'items.id')
            ->where('checkouts.account_id', $accountId)
            ->whereNull('checkouts.deleted_at')
            ->where(fn ($q) => $q->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0))
            ->whereBetween('checkouts.date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereNotNull('checkouts.warehouse_id')
            ->when($this->cfg->projectIds(), fn ($q, $ids) => $q->whereIn('checkouts.warehouse_id', $ids))
            ->when($this->cfg->itemIds(), fn ($q, $ids) => $q->whereIn('checkout_items.item_id', $ids))
            ->groupBy('checkouts.warehouse_id')
            ->selectRaw('checkouts.warehouse_id')
            ->selectRaw('SUM(checkout_items.quantity * COALESCE(checkout_items.price, items.price, 0)) as total_value');

        return DB::table('warehouses')
            ->joinSub($sub, 'consumption', fn ($join) => $join->on('warehouses.id', '=', 'consumption.warehouse_id'))
            ->where('warehouses.account_id', $accountId)
            ->select('warehouses.name')
            ->selectRaw('consumption.total_value')
            ->orderByDesc('consumption.total_value')
            ->limit($this->cfg->limit('top_projects'))
            ->get()
            ->map(fn ($row) => ['name' => (string) $row->name, 'value' => (float) $row->total_value])
            ->values()
            ->all();
    }

    protected function topUsedMaterials(int $accountId, Carbon $weekStart, Carbon $weekEnd): array
    {
        return DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->join('items', 'checkout_items.item_id', '=', 'items.id')
            ->where('checkouts.account_id', $accountId)
            ->whereNull('checkouts.deleted_at')
            ->where(fn ($q) => $q->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0))
            ->whereBetween('checkouts.date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->when($this->cfg->projectIds(), fn ($q, $ids) => $q->whereIn('checkouts.warehouse_id', $ids))
            ->when($this->cfg->itemIds(), fn ($q, $ids) => $q->whereIn('checkout_items.item_id', $ids))
            ->groupBy('items.id', 'items.name', 'items.code')
            ->selectRaw('items.name, items.code, SUM(checkout_items.quantity) as total_quantity')
            ->orderByDesc('total_quantity')
            ->limit($this->cfg->limit('top_materials'))
            ->get()
            ->map(fn ($row) => ['name' => (string) $row->name, 'code' => (string) $row->code, 'quantity' => (float) $row->total_quantity])
            ->values()
            ->all();
    }

    /** A transaction table's own project column (transfers have two: the project it left and the one it went to). */
    protected function scopeToProjects($query, string $table, array $ids)
    {
        return $table === 'transfers'
            ? $query->where(fn ($q) => $q->whereIn('from_warehouse_id', $ids)->orWhereIn('to_warehouse_id', $ids))
            : $query->whereIn('warehouse_id', $ids);
    }

    protected function percentChange(float $before, float $after): ?float
    {
        if ($before <= 0) {
            return $after > 0 ? 100.0 : null;
        }

        return round((($after - $before) / $before) * 100, 1);
    }
}
