<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\BoqMaterialCompliance;
use App\Models\DailySiteReport;
use App\Models\MaterialReallocation;
use App\Models\MaterialRequest;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\ExecutiveReportConfig;

/**
 * Computes the "insight" sections of the weekly Executive Intelligence Report:
 * workflow pipeline status/staleness, site report compliance, BOQ variance
 * flags, adjustment/discrepancy spikes, and dispatch/logistics status.
 *
 * Unlike ExecutiveReportMetricsService (trend numbers), these are point-in-time
 * snapshots plus threshold-based flags — the low-volume modules (Material
 * Reallocation, Daily Site Reports) don't have enough weekly data yet to chart
 * trends, but "what's stuck right now" is meaningful regardless of volume.
 */
class ExecutiveReportInsightsService
{
    protected const STALE_DAYS = 5;

    protected ExecutiveReportConfig $cfg;

    public function build(int $accountId, Carbon $weekStart, Carbon $weekEnd, ?ExecutiveReportConfig $cfg = null): array
    {
        $this->cfg = $cfg ?? ExecutiveReportConfig::make([]);

        return [
            'material_requests_pipeline' => $this->materialRequestsPipeline($accountId),
            'material_reallocations_pipeline' => $this->materialReallocationsPipeline($accountId),
            'site_report_compliance' => $this->siteReportCompliance($accountId, $weekStart, $weekEnd),
            'boq_variance_flags' => $this->boqVarianceFlags($accountId, $weekStart, $weekEnd),
            'adjustment_watch' => $this->adjustmentWatch($accountId, $weekStart, $weekEnd),
            'dispatch_in_transit' => $this->dispatchInTransit($accountId),
            'stock_health' => $this->stockHealth($accountId),
        ];
    }

    /**
     * Items sitting at zero (or negative) stock at a warehouse that actually
     * checked that item out at least once in the last 30 days — i.e. items
     * genuinely in active use that have just run out, not the thousands of
     * item/warehouse pairs that simply never stocked that item there.
     *
     * Note: `stocks.alert_quantity` exists in the schema but is 0/null across
     * every row in this system (never configured by any user), so a configured-
     * threshold approach would surface pure noise — this recent-usage filter
     * is the meaningful proxy available today.
     */
    protected function stockHealth(int $accountId): array
    {
        $recentlyUsedItemWarehousePairs = DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->where('checkouts.account_id', $accountId)
            ->whereNull('checkouts.deleted_at')
            ->where(fn ($q) => $q->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0))
            ->where('checkouts.date', '>=', now()->subDays(30)->toDateString())
            ->select('checkouts.warehouse_id', 'checkout_items.item_id')
            ->distinct();

        $outOfStock = DB::table('stocks')
            ->joinSub($recentlyUsedItemWarehousePairs, 'recent_usage', function ($join) {
                $join->on('stocks.warehouse_id', '=', 'recent_usage.warehouse_id')
                    ->on('stocks.item_id', '=', 'recent_usage.item_id');
            })
            ->join('items', 'stocks.item_id', '=', 'items.id')
            ->join('warehouses', 'stocks.warehouse_id', '=', 'warehouses.id')
            ->where('stocks.account_id', $accountId)
            ->whereNull('stocks.deleted_at')
            ->whereNull('stocks.variation_id')
            ->where('stocks.quantity', '<=', 0)
            ->when($this->cfg->projectIds(), fn ($q, $ids) => $q->whereIn('stocks.warehouse_id', $ids))
            ->when($this->cfg->itemIds(), fn ($q, $ids) => $q->whereIn('stocks.item_id', $ids))
            ->select('items.name as item_name', 'items.code as item_code', 'warehouses.name as warehouse_name', 'stocks.quantity')
            ->orderBy('stocks.quantity')
            ->get();

        return [
            'out_of_stock_count' => $outOfStock->count(),
            'critical_items' => $outOfStock->take($this->cfg->limit('critical_items'))->map(fn ($row) => [
                'item_name' => $row->item_name,
                'item_code' => $row->item_code,
                'warehouse_name' => $row->warehouse_name,
                'quantity' => (float) $row->quantity,
            ])->values()->all(),
        ];
    }

    protected function materialRequestsPipeline(int $accountId): array
    {
        $open = MaterialRequest::whereHas('warehouse', fn ($q) => $q->where('account_id', $accountId))
            ->when($this->cfg->projectIds(), fn ($q, $ids) => $q->whereIn('warehouse_id', $ids))
            ->whereNotIn('status', [MaterialRequest::STATUS_CLOSED, MaterialRequest::STATUS_REJECTED])
            ->get(['id', 'status', 'updated_at', 'warehouse_id']);

        $byStatus = $open->groupBy('status')->map->count();

        $stale = $open
            ->filter(fn ($r) => $r->updated_at && $r->updated_at->lt(now()->subDays($this->cfg->limit('stale_days'))))
            ->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'days_stuck' => (int) $r->updated_at->diffInDays(now()),
            ])
            ->sortByDesc('days_stuck')
            ->values()
            ->all();

        return ['by_status' => $byStatus, 'stale' => $stale];
    }

    protected function materialReallocationsPipeline(int $accountId): array
    {
        $open = MaterialReallocation::whereHas('sourceWarehouse', fn ($q) => $q->where('account_id', $accountId))
            ->when($this->cfg->projectIds(), fn ($q, $ids) => $q->whereIn('source_warehouse_id', $ids))
            ->whereNotIn('status', [
                MaterialReallocation::STATUS_COMPLETED,
                MaterialReallocation::STATUS_REJECTED,
                MaterialReallocation::STATUS_CANCELLED,
            ])
            ->get(['id', 'reference', 'status', 'updated_at']);

        $byStatus = $open->groupBy('status')->map->count();

        $stale = $open
            ->filter(fn ($r) => $r->updated_at && $r->updated_at->lt(now()->subDays($this->cfg->limit('stale_days'))))
            ->map(fn ($r) => [
                'id' => $r->id,
                'reference' => $r->reference,
                'status' => $r->status,
                'days_stuck' => (int) $r->updated_at->diffInDays(now()),
            ])
            ->sortByDesc('days_stuck')
            ->values()
            ->all();

        return ['by_status' => $byStatus, 'stale' => $stale];
    }

    protected function siteReportCompliance(int $accountId, Carbon $weekStart, Carbon $weekEnd): array
    {
        $pairs = SiteReportAssignments::pairs();
        if ($ids = $this->cfg->projectIds()) {
            $pairs = $pairs->filter(fn ($p) => in_array((int) $p->warehouse_id, $ids, true))->values();
        }
        if ($pairs->isEmpty()) {
            return ['expected' => 0, 'submitted' => 0, 'compliance_percent' => null, 'non_compliant_sites' => []];
        }

        $submittedReports = DailySiteReport::whereHas('warehouse', fn ($q) => $q->where('account_id', $accountId))
            ->whereBetween('report_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get(['submitted_by', 'warehouse_id']);

        $submittedPairs = $submittedReports
            ->map(fn ($r) => $r->submitted_by . ':' . $r->warehouse_id)
            ->unique();

        $expected = $pairs->count();
        $submitted = $pairs->filter(fn ($pair) => $submittedPairs->contains($pair->user_id . ':' . $pair->warehouse_id))->count();

        $nonCompliant = $pairs
            ->reject(fn ($pair) => $submittedPairs->contains($pair->user_id . ':' . $pair->warehouse_id))
            ->map(fn ($pair) => ['user_name' => $pair->user_name, 'warehouse_name' => $pair->warehouse_name])
            ->values()
            ->all();

        return [
            'expected' => $expected,
            'submitted' => $submitted,
            'compliance_percent' => $expected > 0 ? round(($submitted / $expected) * 100, 1) : null,
            'non_compliant_sites' => $nonCompliant,
        ];
    }

    protected function boqVarianceFlags(int $accountId, Carbon $weekStart, Carbon $weekEnd): array
    {
        $projectIds = BoqMaterialCompliance::where('account_id', $accountId)
            ->when($this->cfg->projectIds(), fn ($q, $ids) => $q->whereIn('project_warehouse_id', $ids))
            ->when($this->cfg->itemIds(), fn ($q, $ids) => $q->whereIn('item_id', $ids))
            ->distinct()
            ->pluck('project_warehouse_id');

        if ($projectIds->isEmpty()) {
            return [];
        }

        $flags = [];

        foreach ($projectIds as $projectId) {
            $boq = BoqMaterialCompliance::where('account_id', $accountId)
                ->where('project_warehouse_id', $projectId)
                ->get(['item_id', 'boq_quantity', 'unit_price']);

            if ($boq->isEmpty()) {
                continue;
            }

            $issuedThisWeek = DB::table('checkouts')
                ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
                ->where('checkouts.account_id', $accountId)
                ->where('checkouts.warehouse_id', $projectId)
                ->whereNull('checkouts.deleted_at')
                ->where(fn ($q) => $q->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0))
                ->whereBetween('checkouts.date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                ->whereIn('checkout_items.item_id', $boq->pluck('item_id'))
                ->selectRaw('checkout_items.item_id, SUM(checkout_items.quantity) as qty')
                ->groupBy('checkout_items.item_id')
                ->pluck('qty', 'item_id');

            if ($issuedThisWeek->isEmpty()) {
                continue;
            }

            $totalBoq = (float) $boq->sum('boq_quantity');
            $totalIssuedToDate = (float) DB::table('checkouts')
                ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
                ->where('checkouts.account_id', $accountId)
                ->where('checkouts.warehouse_id', $projectId)
                ->whereNull('checkouts.deleted_at')
                ->where(fn ($q) => $q->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0))
                ->whereIn('checkout_items.item_id', $boq->pluck('item_id'))
                ->sum('checkout_items.quantity');

            if ($totalBoq <= 0) {
                continue;
            }

            $ratio = $totalIssuedToDate / $totalBoq;
            if ($ratio > 1.0) {
                $flags[] = [
                    'project_name' => optional(Warehouse::find($projectId))->name ?? "Project #{$projectId}",
                    'issued_to_date' => $totalIssuedToDate,
                    'boq_baseline' => $totalBoq,
                    'consumption_percent' => round($ratio * 100, 1),
                    'issued_this_week' => (float) $issuedThisWeek->sum(),
                ];
            }
        }

        usort($flags, fn ($a, $b) => $b['consumption_percent'] <=> $a['consumption_percent']);

        return array_slice($flags, 0, $this->cfg->limit('boq_projects'));
    }

    protected function adjustmentWatch(int $accountId, Carbon $weekStart, Carbon $weekEnd): array
    {
        $thisWeek = Adjustment::where('account_id', $accountId)
            ->when($this->cfg->projectIds(), fn ($q, $ids) => $q->whereIn('warehouse_id', $ids))
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('draft')->orWhere('draft', 0))
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->where('type', 'Damage')
            ->get(['warehouse_id']);

        if ($thisWeek->isEmpty()) {
            return [];
        }

        $byWarehouse = $thisWeek->groupBy('warehouse_id')->map->count();

        $flags = [];
        foreach ($byWarehouse as $warehouseId => $count) {
            $trailingAvgWeekly = (float) Adjustment::where('account_id', $accountId)
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('draft')->orWhere('draft', 0))
                ->where('warehouse_id', $warehouseId)
                ->where('type', 'Damage')
                ->where('date', '<', $weekStart->toDateString())
                ->where('date', '>=', $weekStart->copy()->subWeeks(4)->toDateString())
                ->count() / 4;

            if ($count > max(2, $trailingAvgWeekly * 2)) {
                $flags[] = [
                    'warehouse_name' => optional(Warehouse::find($warehouseId))->name ?? "Warehouse #{$warehouseId}",
                    'damage_adjustments_this_week' => (int) $count,
                    'trailing_4week_average' => round($trailingAvgWeekly, 1),
                ];
            }
        }

        return $flags;
    }

    protected function dispatchInTransit(int $accountId): array
    {
        return MaterialReallocation::whereHas('sourceWarehouse', fn ($q) => $q->where('account_id', $accountId))
            ->when($this->cfg->projectIds(), fn ($q, $ids) => $q->whereIn('source_warehouse_id', $ids))
            ->whereIn('status', [MaterialReallocation::STATUS_DISPATCH_PREPARATION, MaterialReallocation::STATUS_WAREHOUSE_RECEIVING])
            ->with('driverContact:id,name')
            ->get(['id', 'reference', 'status', 'vehicle', 'driver_contact_id', 'dispatch_date_time'])
            ->map(fn ($r) => [
                'reference' => $r->reference,
                'status' => $r->status,
                'vehicle' => $r->vehicle,
                'driver_name' => optional($r->driverContact)->name,
                'dispatch_date_time' => optional($r->dispatch_date_time)->format('Y-m-d H:i'),
            ])
            ->values()
            ->all();
    }
}
