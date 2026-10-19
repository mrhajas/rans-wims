<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Contact;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Two movement views over the same value formula (quantity x price — see
 * MaterialValueController for the formula this mirrors):
 *  - "warehouse" mode: Main Warehouse(s) -> Project/site warehouse(s), sourced
 *    from Transfer/TransferItem, valued at the item's current price (transfer
 *    lines don't carry their own price).
 *  - "supplier" mode: Contact/supplier -> Project/site warehouse(s), sourced
 *    from Checkin/CheckinItem, valued at that checkin line's own recorded
 *    price when present (falls back to the item's current price), since a
 *    checkin is a real purchase-receiving event with its own documented cost.
 */
class MovementValueReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $filters = [
            'mode' => in_array($request->input('mode'), ['warehouse', 'supplier'], true) ? $request->input('mode') : 'warehouse',
            'item_ids' => $this->parseIds($request->input('item_ids')),
            'category_ids' => $this->parseIds($request->input('category_ids')),
            'source_warehouse_ids' => $this->parseIds($request->input('source_warehouse_ids')),
            'contact_ids' => $this->parseIds($request->input('contact_ids')),
            'destination_warehouse_ids' => $this->parseIds($request->input('destination_warehouse_ids')),
            'date_from' => $request->input('date_from') ?: null,
            'date_to' => $request->input('date_to') ?: null,
            'entire_project' => filter_var($request->input('entire_project'), FILTER_VALIDATE_BOOLEAN),
        ];

        $accessibleIds = accessible_warehouse_ids();
        $mainWarehouses = $this->scopedWarehouses($user, $accessibleIds, true);
        $projectWarehouses = $this->scopedWarehouses($user, $accessibleIds, false);
        $allowedMainIds = $mainWarehouses->pluck('id')->all();
        $allowedProjectIds = $projectWarehouses->pluck('id')->all();

        $filters['source_warehouse_ids'] = array_values(array_intersect($filters['source_warehouse_ids'], $allowedMainIds));
        $filters['destination_warehouse_ids'] = array_values(array_intersect($filters['destination_warehouse_ids'], $allowedProjectIds));

        $report = $this->buildReport($filters, $user, $allowedMainIds, $allowedProjectIds);

        $output = $request->query('output');

        if ($output === 'print' || $output === 'pdf') {
            $viewData = [
                'title' => __('Movement Value Report'),
                'modeLabel' => $filters['mode'] === 'supplier' ? __('Supplier to Project') : __('Warehouse to Project'),
                'companyName' => optional($user->account)->name,
                'generatedBy' => $user->name,
                'generatedAt' => now(),
                'autoPrint' => $output === 'print',
                'report' => $report,
            ];

            if ($output === 'pdf') {
                return Pdf::loadView('reports.movement-value-pdf', $viewData)
                    ->setPaper('a4')
                    ->download('movement-value-report-'.now()->format('Ymd-His').'.pdf');
            }

            return view('reports.movement-value-pdf', $viewData);
        }

        return Inertia::render('MovementValue/Index', [
            'filters' => $filters,
            'report' => $report,
            'mainWarehouses' => $mainWarehouses,
            'projectWarehouses' => $projectWarehouses,
            'projectWarehouseStatuses' => $projectWarehouses->pluck('status')->unique()->values(),
            'suppliers' => $this->availableSuppliers($user),
            'availableItems' => $this->availableItems($user),
            'availableCategories' => $this->availableCategories($user),
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

    private function scopedWarehouses(User $user, ?array $accessibleIds, bool $mainOnly)
    {
        $query = Warehouse::where('account_id', $user->account_id)->where('active', 1)->visible();

        $query = $mainOnly ? $query->mainWarehouses() : $query->whereNotIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses']);

        if ($accessibleIds !== null) {
            $query->whereIn('id', $accessibleIds);
        }

        return $query->orderBy('status')->orderBy('name')->get(['id', 'name', 'status', 'completed_at']);
    }

    private function availableSuppliers(User $user)
    {
        return Contact::where('account_id', $user->account_id)
            ->whereHas('checkins')
            ->orderBy('name')
            ->get(['id', 'name', 'category']);
    }

    private function availableItems(User $user)
    {
        return Item::ofAccount()->orderBy('name')->get(['id', 'name', 'code']);
    }

    private function availableCategories(User $user)
    {
        return Category::ofAccount()->orderBy('name')->get(['id', 'name']);
    }

    private function resolveItemIds(array $filters): array
    {
        $itemIds = collect($filters['item_ids']);

        if (! empty($filters['category_ids'])) {
            $categoryItemIds = Item::ofAccount()
                ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $filters['category_ids']))
                ->pluck('id');
            $itemIds = $itemIds->merge($categoryItemIds);
        }

        return $itemIds->unique()->values()->all();
    }

    private function buildReport(array $filters, User $user, array $allowedMainIds, array $allowedProjectIds): array
    {
        $empty = ['grand_total' => 0.0, 'item_count' => 0, 'group_count' => 0, 'groups' => []];

        $hasMaterialSelection = ! empty($filters['item_ids']) || ! empty($filters['category_ids']);
        if (! $hasMaterialSelection || empty($filters['destination_warehouse_ids'])) {
            return $empty;
        }

        $itemIds = $this->resolveItemIds($filters);
        if (empty($itemIds)) {
            return $empty;
        }

        $rows = $filters['mode'] === 'supplier'
            ? $this->supplierMovementRows($filters, $user, $itemIds, $allowedProjectIds)
            : $this->warehouseMovementRows($filters, $user, $itemIds, $allowedMainIds, $allowedProjectIds);

        if ($rows->isEmpty()) {
            return $empty;
        }

        $warehouses = Warehouse::whereIn('id', $filters['destination_warehouse_ids'])->visible()->get(['id', 'name', 'status'])->keyBy('id');

        $groupRows = [];
        $grandTotal = 0.0;
        $distinctItemIds = [];

        foreach ($rows->groupBy('group_warehouse_id') as $warehouseId => $group) {
            $warehouse = $warehouses->get($warehouseId);
            if (! $warehouse) {
                continue;
            }

            // One row per movement line (not aggregated across dates) so the
            // Date column means something — a single row can only ever have
            // one date. distinctItemIds still counts unique items, not rows.
            $items = $group->sortByDesc('date')->values()->map(function ($line) use (&$distinctItemIds) {
                $distinctItemIds[$line->item_id] = true;

                return [
                    'item_id' => (int) $line->item_id,
                    'name' => $line->item_name,
                    'code' => $line->item_code,
                    'transaction_id' => (int) $line->transaction_id,
                    'reference' => $line->reference,
                    'date' => $line->date,
                    'quantity' => (float) $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => (float) $line->unit_price,
                    'value' => (float) $line->value,
                ];
            })->all();

            $subtotal = array_sum(array_column($items, 'value'));
            $grandTotal += $subtotal;

            $groupRows[] = [
                'warehouse_id' => (int) $warehouseId,
                'warehouse_name' => $warehouse->name,
                'warehouse_status' => $warehouse->status,
                'items' => $items,
                'subtotal' => $subtotal,
            ];
        }

        usort($groupRows, fn ($a, $b) => $b['subtotal'] <=> $a['subtotal']);

        return [
            'grand_total' => $grandTotal,
            'item_count' => count($distinctItemIds),
            'group_count' => count($groupRows),
            'groups' => $groupRows,
        ];
    }

    private function warehouseMovementRows(array $filters, User $user, array $itemIds, array $allowedMainIds, array $allowedProjectIds)
    {
        $sourceIds = ! empty($filters['source_warehouse_ids']) ? $filters['source_warehouse_ids'] : $allowedMainIds;

        if (empty($sourceIds)) {
            return collect();
        }

        return DB::table('transfer_items')
            ->join('transfers', 'transfer_items.transfer_id', '=', 'transfers.id')
            ->join('items', 'transfer_items.item_id', '=', 'items.id')
            ->leftJoin('units', 'transfer_items.unit_id', '=', 'units.id')
            ->where('transfer_items.account_id', $user->account_id)
            ->where('transfers.draft', 0)
            ->whereNull('transfers.deleted_at')
            ->whereIn('transfer_items.from_warehouse_id', $sourceIds)
            ->whereIn('transfer_items.to_warehouse_id', $filters['destination_warehouse_ids'])
            ->whereIn('transfer_items.item_id', $itemIds)
            ->when(! $filters['entire_project'] && $filters['date_from'], fn ($q, $v) => $q->whereDate('transfers.date', '>=', $filters['date_from']))
            ->when(! $filters['entire_project'] && $filters['date_to'], fn ($q, $v) => $q->whereDate('transfers.date', '<=', $filters['date_to']))
            ->select(
                'transfer_items.to_warehouse_id as group_warehouse_id',
                'items.id as item_id',
                'items.name as item_name',
                'items.code as item_code',
                'transfers.id as transaction_id',
                'transfers.reference',
                'transfers.date',
                'transfer_items.quantity',
                DB::raw("COALESCE(units.name, units.code, '-') as unit"),
                DB::raw('COALESCE(items.price, 0) as unit_price'),
                DB::raw('transfer_items.quantity * COALESCE(items.price, 0) as value')
            )
            ->get();
    }

    private function supplierMovementRows(array $filters, User $user, array $itemIds, array $allowedProjectIds)
    {
        $contactIds = ! empty($filters['contact_ids'])
            ? $filters['contact_ids']
            : Contact::where('account_id', $user->account_id)->whereHas('checkins')->pluck('id')->all();

        if (empty($contactIds)) {
            return collect();
        }

        return DB::table('checkin_items')
            ->join('checkins', 'checkin_items.checkin_id', '=', 'checkins.id')
            ->join('items', 'checkin_items.item_id', '=', 'items.id')
            ->leftJoin('units', 'checkin_items.unit_id', '=', 'units.id')
            ->where('checkin_items.account_id', $user->account_id)
            ->where('checkins.draft', 0)
            ->whereNull('checkins.deleted_at')
            ->whereIn('checkins.contact_id', $contactIds)
            ->whereIn('checkin_items.warehouse_id', $filters['destination_warehouse_ids'])
            ->whereIn('checkin_items.item_id', $itemIds)
            ->when(! $filters['entire_project'] && $filters['date_from'], fn ($q, $v) => $q->whereDate('checkins.date', '>=', $filters['date_from']))
            ->when(! $filters['entire_project'] && $filters['date_to'], fn ($q, $v) => $q->whereDate('checkins.date', '<=', $filters['date_to']))
            ->select(
                'checkin_items.warehouse_id as group_warehouse_id',
                'items.id as item_id',
                'items.name as item_name',
                'items.code as item_code',
                'checkins.id as transaction_id',
                'checkins.reference',
                'checkins.date',
                'checkin_items.quantity',
                DB::raw("COALESCE(units.name, units.code, '-') as unit"),
                DB::raw('COALESCE(NULLIF(checkin_items.price, 0), items.price, 0) as unit_price'),
                DB::raw('checkin_items.quantity * COALESCE(NULLIF(checkin_items.price, 0), items.price, 0) as value')
            )
            ->get();
    }
}
