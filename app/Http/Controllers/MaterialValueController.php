<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Total stock value (quantity x item price — the same trusted formula used
 * by the Dashboard's warehouse stock-value summary and the Executive
 * Report) for a chosen set of materials/categories, broken down per
 * warehouse. A single `index` action handles the page render and both
 * output formats via `?output=print|pdf`, matching
 * ItemCategoryReportController's convention — print/download logging is
 * automatic via the global LogDownloadPrintActions middleware, so nothing
 * here calls DownloadPrintLog directly.
 */
class MaterialValueController extends Controller
{
    public const WAREHOUSE_STATUSES = ['Main Warehouse', 'Private', 'Government', 'Directors', 'Rans Warehouse'];

    public function index(Request $request)
    {
        $user = $request->user();
        $warehouses = $this->accessibleWarehouses($user);
        $allowedWarehouseIds = $warehouses->pluck('id')->all();

        $filters = [
            'item_ids' => $this->parseIds($request->input('item_ids')),
            'category_ids' => $this->parseIds($request->input('category_ids')),
            'warehouse_ids' => array_values(array_intersect(
                $this->parseIds($request->input('warehouse_ids')),
                $allowedWarehouseIds
            )),
        ];

        $report = $this->buildReport($filters, $user);

        $output = $request->query('output');

        if ($output === 'print' || $output === 'pdf') {
            $viewData = [
                'title' => __('Material Value Report'),
                'companyName' => optional($user->account)->name,
                'generatedBy' => $user->name,
                'generatedAt' => now(),
                'autoPrint' => $output === 'print',
                'report' => $report,
            ];

            if ($output === 'pdf') {
                return Pdf::loadView('reports.material-value-pdf', $viewData)
                    ->setPaper('a4')
                    ->download('material-value-report-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.material-value-pdf', $viewData);
        }

        return Inertia::render('MaterialValue/Index', [
            'filters' => $filters,
            'report' => $report,
            'warehouses' => $warehouses->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'status' => $w->status,
                'completed_at' => $w->completed_at,
            ])->values(),
            'warehouseStatuses' => self::WAREHOUSE_STATUSES,
            'availableItems' => $this->availableItems($allowedWarehouseIds),
            'availableCategories' => $this->availableCategories($allowedWarehouseIds),
        ]);
    }

    public function itemDetails(Request $request, Item $item)
    {
        $user = $request->user();
        abort_unless($user->can('read-material-value'), 403);

        $item->load('unit');

        $allowedWarehouseIds = $this->accessibleWarehouses($user)->pluck('id')->all();

        $stocks = DB::table('stocks')
            ->join('warehouses', 'stocks.warehouse_id', '=', 'warehouses.id')
            ->where('stocks.item_id', $item->id)
            ->where('stocks.account_id', $user->account_id)
            ->where('stocks.quantity', '>', 0)
            ->whereIn('stocks.warehouse_id', $allowedWarehouseIds)
            ->select('warehouses.name as warehouse_name', 'stocks.quantity')
            ->get();

        $totalQuantity = (float) $stocks->sum('quantity');

        $warehouseDetails = $stocks->map(fn ($s) => [
            'warehouse' => $s->warehouse_name,
            'quantity' => (float) $s->quantity,
        ])->sortBy('warehouse')->values();

        return response()->json([
            'item_id' => $item->id,
            'item_name' => $item->name,
            'item_code' => $item->code,
            'unit' => $item->unit?->name ?? $item->unit?->code ?? '-',
            'price' => (float) ($item->price ?? 0),
            'total_quantity' => $totalQuantity,
            'total_value' => $totalQuantity * (float) ($item->price ?? 0),
            'warehouse_details' => $warehouseDetails,
        ]);
    }

    private function parseIds($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_unique(array_filter(array_map('intval', $raw))));
        }

        if (is_string($raw) && $raw !== '') {
            return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
        }

        return [];
    }

    private function accessibleWarehouses(User $user)
    {
        $ids = accessible_warehouse_ids();

        $query = Warehouse::where('account_id', $user->account_id)
            ->where('active', 1)
            ->visible()
            ->orderBy('status')
            ->orderBy('name');

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->get(['id', 'name', 'status', 'completed_at']);
    }

    private function availableItems(array $warehouseIds)
    {
        return Item::ofAccount()
            ->whereHas('allStock', fn ($q) => $q->whereIn('warehouse_id', $warehouseIds)->where('quantity', '>', 0))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    private function availableCategories(array $warehouseIds)
    {
        return Category::ofAccount()
            ->whereHas('items.allStock', fn ($q) => $q->whereIn('warehouse_id', $warehouseIds)->where('quantity', '>', 0))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function buildReport(array $filters, User $user): array
    {
        $empty = ['grand_total' => 0.0, 'item_count' => 0, 'warehouse_count' => 0, 'warehouses' => []];

        $hasMaterialSelection = ! empty($filters['item_ids']) || ! empty($filters['category_ids']);
        if (! $hasMaterialSelection || empty($filters['warehouse_ids'])) {
            return $empty;
        }

        $itemIds = collect($filters['item_ids']);

        if (! empty($filters['category_ids'])) {
            $categoryItemIds = Item::ofAccount()
                ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $filters['category_ids']))
                ->pluck('id');
            $itemIds = $itemIds->merge($categoryItemIds);
        }

        $itemIds = $itemIds->unique()->values()->all();

        if (empty($itemIds)) {
            return $empty;
        }

        $rows = DB::table('stocks')
            ->join('items', 'stocks.item_id', '=', 'items.id')
            ->leftJoin('units', 'items.unit_id', '=', 'units.id')
            ->where('stocks.account_id', $user->account_id)
            ->whereIn('stocks.warehouse_id', $filters['warehouse_ids'])
            ->whereIn('stocks.item_id', $itemIds)
            ->where('stocks.quantity', '>', 0)
            ->select(
                'stocks.warehouse_id',
                'items.id as item_id',
                'items.name as item_name',
                'items.code as item_code',
                'stocks.quantity',
                DB::raw("COALESCE(units.name, units.code, '-') as unit"),
                DB::raw('COALESCE(items.price, 0) as unit_price'),
                DB::raw('stocks.quantity * COALESCE(items.price, 0) as value')
            )
            ->get();

        if ($rows->isEmpty()) {
            return $empty;
        }

        $warehouses = Warehouse::whereIn('id', $filters['warehouse_ids'])->visible()->get(['id', 'name', 'status'])->keyBy('id');

        $warehouseRows = [];
        $grandTotal = 0.0;
        $distinctItemIds = [];

        foreach ($rows->groupBy('warehouse_id') as $warehouseId => $group) {
            $warehouse = $warehouses->get($warehouseId);
            if (! $warehouse) {
                continue;
            }

            $items = $group->map(function ($r) use (&$distinctItemIds) {
                $distinctItemIds[$r->item_id] = true;

                return [
                    'item_id' => (int) $r->item_id,
                    'name' => $r->item_name,
                    'code' => $r->item_code,
                    'quantity' => (float) $r->quantity,
                    'unit' => $r->unit,
                    'unit_price' => (float) $r->unit_price,
                    'value' => (float) $r->value,
                ];
            })->sortBy('name')->values()->all();

            $subtotal = array_sum(array_column($items, 'value'));
            $grandTotal += $subtotal;

            $warehouseRows[] = [
                'warehouse_id' => (int) $warehouseId,
                'warehouse_name' => $warehouse->name,
                'warehouse_status' => $warehouse->status,
                'items' => $items,
                'subtotal' => $subtotal,
            ];
        }

        usort($warehouseRows, fn ($a, $b) => $b['subtotal'] <=> $a['subtotal']);

        return [
            'grand_total' => $grandTotal,
            'item_count' => count($distinctItemIds),
            'warehouse_count' => count($warehouseRows),
            'warehouses' => $warehouseRows,
        ];
    }
}
