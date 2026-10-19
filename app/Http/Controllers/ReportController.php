<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\BoqMaterialCompliance;
use Inertia\Inertia;
use App\Models\Checkin;
use App\Models\Contact;
use App\Models\Category;
use App\Models\Checkout;
use App\Models\Transfer;
use App\Models\Warehouse;
use App\Models\Adjustment;
use App\Models\Stock;
use App\Models\MaterialReallocation;
use App\Models\MaterialReallocationItem;
use Illuminate\Http\Request;
use App\Http\Resources\CheckinCollection;
use App\Http\Resources\CheckoutCollection;
use App\Http\Resources\TransferCollection;
use App\Http\Resources\AdjustmentCollection;
use App\Http\Resources\CheckinResource;
use App\Http\Resources\CheckoutResource;
use App\Http\Resources\TransferResource;
use App\Mail\ReportMail;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rap2hpoutre\FastExcel\FastExcel;

class ReportController extends Controller
{
    public function timelyMaterialUsage(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canViewTimelyMaterialUsage($user), 403, 'You are not allowed to view this report.');

        $showCostColumns = $this->canSeeTimelyUsageCosts($user);
        $canViewAllProjects = $this->canSelectAllProjects($user);

        $warehouses = $this->reportWarehousesForUser($user, $canViewAllProjects);
        $selectedWarehouseId = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;

        if ($selectedWarehouseId && ! $warehouses->contains('id', $selectedWarehouseId)) {
            abort(403, 'You are not assigned to the selected project/warehouse.');
        }

        if (! $selectedWarehouseId && $warehouses->count() === 1) {
            $selectedWarehouseId = (int) $warehouses->first()->id;
        }

        $filters = [
            'warehouse_id' => $selectedWarehouseId,
            'start_date'   => $request->input('start_date') ?: now()->startOfMonth()->toDateString(),
            'end_date'     => $request->input('end_date') ?: now()->toDateString(),
            'search'       => $request->input('search'),
        ];

        $rows = DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->join('items', 'checkout_items.item_id', '=', 'items.id')
            ->leftJoin('units', 'checkout_items.unit_id', '=', 'units.id')
            ->where('checkouts.account_id', getAccountId(1))
            ->whereNull('checkouts.deleted_at')
            ->where(function ($query) {
                $query->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0);
            })
            ->when($selectedWarehouseId, fn ($query) => $query->where('checkouts.warehouse_id', $selectedWarehouseId))
            ->whereDate('checkouts.date', '>=', $filters['start_date'])
            ->whereDate('checkouts.date', '<=', $filters['end_date'])
            ->when(filled($filters['search']), function ($query) use ($filters) {
                $search = trim((string) $filters['search']);
                $query->where(function ($inner) use ($search) {
                    $inner->where('items.name', 'like', "%{$search}%")
                        ->orWhere('items.code', 'like', "%{$search}%");
                });
            })
            ->groupBy('checkout_items.item_id', 'items.name', 'units.name', 'units.code')
            ->orderBy('items.name')
            ->select(
                'checkout_items.item_id',
                'items.name as item_name',
                DB::raw("COALESCE(units.name, units.code, '-') as unit"),
                DB::raw('SUM(checkout_items.quantity) as accumulated_quantity'),
                DB::raw('SUM(checkout_items.quantity * COALESCE(checkout_items.price, items.price, 0)) as total_price')
            )
            ->get()
            ->map(function ($row) {
                $quantity = (float) $row->accumulated_quantity;
                $totalPrice = (float) $row->total_price;

                return [
                    'item_id'              => (int) $row->item_id,
                    'item_name'            => $row->item_name,
                    'unit'                 => $row->unit,
                    'accumulated_quantity' => $quantity,
                    'total_price'          => $totalPrice,
                    'unit_price'           => $quantity > 0 ? ($totalPrice / $quantity) : 0,
                ];
            })
            ->values();

        $totalTableCost = (float) $rows->sum('total_price');

        if ($request->query('output') === 'excel') {
            $exportRows = $rows->values()->map(fn ($row, $index) => [
                'No' => $index + 1,
                'Material' => $row['item_name'],
                'Unit' => $row['unit'],
                'Qty Used' => (float) $row['accumulated_quantity'],
                'Unit Price' => (float) $row['unit_price'],
                'Total Price' => (float) $row['total_price'],
            ]);

            return (new FastExcel($exportRows))->download('timely-material-usage-' . now()->format('Ymd-His') . '.xlsx');
        }

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $columns = ['No', 'Material', 'Unit', 'Qty Used'];
            if ($showCostColumns) {
                $columns[] = 'Unit Price';
                $columns[] = 'Total Price';
            }

            $printRows = $rows->values()->map(function ($row, $index) use ($showCostColumns) {
                $base = [
                    $index + 1,
                    $row['item_name'],
                    $row['unit'],
                    number_format((float) $row['accumulated_quantity'], 2),
                ];

                if ($showCostColumns) {
                    $base[] = number_format((float) $row['unit_price'], 2);
                    $base[] = number_format((float) $row['total_price'], 2);
                }

                return $base;
            })->all();

            $meta = [
                'Project / Warehouse' => $selectedWarehouseId
                    ? optional($warehouses->firstWhere('id', $selectedWarehouseId))->name
                    : 'All Accessible',
                'From' => $filters['start_date'],
                'To' => $filters['end_date'],
                'Search' => $filters['search'] ?: null,
            ];

            if ($showCostColumns) {
                $meta['Total Cost'] = number_format($totalTableCost, 2);
            }

            $data = [
                'title'       => 'Timely Material Usage Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns'     => $columns,
                'rows'        => $printRows,
                'meta'        => array_filter($meta, fn ($value) => filled($value)),
                'autoPrint'   => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)
                    ->download('timely-material-usage-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        return Inertia::render('Report/TimelyMaterialUsage', [
            'filters'          => $filters,
            'warehouses'       => $warehouses,
            'rows'             => $rows,
            'showCostColumns'  => $showCostColumns,
            'canViewAllProjects' => $canViewAllProjects,
            'totalTableCost'   => $totalTableCost,
        ]);
    }

    public function usageMaterialPerSiteCost(Request $request)
    {
        abort_unless($this->canViewUsageMaterialPerSiteCost($request), 403, 'Only Admin, Project Manager, and Procurement Manager can view this report.');

        $user = $request->user();
        $warehouses = $this->getAccessibleWarehousesForUser($user);

        $warehouseIds = collect($request->input('warehouse_ids', []))
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->values();

        // Restrict warehouse_ids to only accessible warehouses for non-admin users
        $allowedWarehouseIds = $warehouses->pluck('id');
        if ($warehouseIds->isNotEmpty()) {
            $warehouseIds = $warehouseIds->intersect($allowedWarehouseIds)->values();
        }

        $filters = [
            'warehouse_ids' => $warehouseIds->all(),
            'start_date'    => $request->input('start_date'),
            'end_date'      => $request->input('end_date'),
            'entire_period' => $request->boolean('entire_period'),
            'item_search'   => $request->input('item_search'),
        ];

        $query = DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->join('items', 'checkout_items.item_id', '=', 'items.id')
            ->leftJoin('units', 'checkout_items.unit_id', '=', 'units.id')
            ->where('checkouts.account_id', getAccountId(1))
            ->whereNull('checkouts.deleted_at')
            ->where(function ($q) {
                $q->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0);
            })
            ->when($warehouseIds->isNotEmpty(), fn ($q) => $q->whereIn('checkouts.warehouse_id', $warehouseIds))
            ->when(! $filters['entire_period'] && filled($filters['start_date']), fn ($q) => $q->whereDate('checkouts.date', '>=', $filters['start_date']))
            ->when(! $filters['entire_period'] && filled($filters['end_date']), fn ($q) => $q->whereDate('checkouts.date', '<=', $filters['end_date']))
            ->select(
                'checkout_items.item_id',
                'items.name as item_name',
                'checkout_items.unit_id',
                DB::raw("COALESCE(units.name, units.code, '-') as unit"),
                DB::raw('SUM(checkout_items.quantity) as qty_used'),
                DB::raw('SUM(checkout_items.quantity * COALESCE(checkout_items.price, items.price, 0)) as total_cost')
            )
            ->groupBy('checkout_items.item_id', 'items.name', 'checkout_items.unit_id', 'units.name', 'units.code')
            ->orderBy('items.name');

        $rows = $query->get()->map(function ($row) {
            return [
                'item_id'     => (int) $row->item_id,
                'item_name'   => $row->item_name,
                'qty_used'    => (float) $row->qty_used,
                'unit'        => $row->unit,
                'total_cost'  => (float) $row->total_cost,
            ];
        })->values();

        $totalValue = (float) $rows->sum('total_cost');

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $filteredRows = $rows;
            if (filled($filters['item_search'])) {
                $needle = mb_strtolower((string) $filters['item_search']);
                $filteredRows = $rows->filter(fn ($row) => str_contains(mb_strtolower((string) $row['item_name']), $needle))->values();
            }

            $data = [
                'title'       => 'Usage Material per Site and Cost Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns'     => ['Material / Item', 'Qty Used', 'Unit', 'Total Cost'],
                'rows'        => $filteredRows->map(fn ($row) => [
                    $row['item_name'],
                    number_format((float) $row['qty_used'], 2),
                    $row['unit'],
                    number_format((float) $row['total_cost'], 2),
                ])->values()->all(),
                'meta'        => array_filter([
                    'Warehouses'     => $warehouseIds->isNotEmpty()
                        ? $warehouses->whereIn('id', $warehouseIds)->pluck('name')->implode(', ')
                        : 'All',
                    'From'           => ! $filters['entire_period'] ? ($filters['start_date'] ?? null) : null,
                    'To'             => ! $filters['entire_period'] ? ($filters['end_date'] ?? null) : null,
                    'Entire Period'  => $filters['entire_period'] ? 'Yes' : 'No',
                    'Item Search'    => $filters['item_search'] ?? null,
                    'Total Value'    => number_format((float) $filteredRows->sum('total_cost'), 2),
                ], fn ($v) => filled($v)),
                'autoPrint'   => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)
                    ->download('usage-material-per-site-cost-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        return Inertia::render('Report/UsageMaterialPerSiteCost', [
            'filters'    => $filters,
            'warehouses' => $warehouses,
            'rows'       => $rows,
            'totalValue' => $totalValue,
        ]);
    }

    public function mostUsedMaterial(Request $request)
    {
        $defaultStart = now()->startOfMonth()->toDateString();
        $defaultEnd = now()->toDateString();
        $accountId = getAccountId(1);

        $filters = [
            'start_date' => $request->filled('start_date') ? $request->input('start_date') : $defaultStart,
            'end_date'   => $request->filled('end_date') ? $request->input('end_date') : $defaultEnd,
        ];

        $baseQuery = DB::table('checkouts')
            ->where(function ($query) {
                $query->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0);
            })
            ->whereBetween('checkouts.date', [$filters['start_date'], $filters['end_date']])
            ->whereNull('checkouts.deleted_at')
            ->where('checkouts.account_id', $accountId);

        $topItems = (clone $baseQuery)
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->select('checkout_items.item_id', DB::raw('SUM(checkout_items.quantity) as total_quantity'))
            ->groupBy('checkout_items.item_id')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get();

        $itemIds = $topItems->pluck('item_id')->values();
        $warehouses = Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name']);

        $siteUsage = collect();
        if ($itemIds->isNotEmpty()) {
            $siteUsage = (clone $baseQuery)
                ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
                ->join('warehouses', 'checkouts.warehouse_id', '=', 'warehouses.id')
                ->whereIn('checkout_items.item_id', $itemIds)
                ->select(
                    'checkout_items.item_id',
                    'checkouts.warehouse_id',
                    'warehouses.name as warehouse_name',
                    DB::raw('SUM(checkout_items.quantity) as quantity')
                )
                ->groupBy('checkout_items.item_id', 'checkouts.warehouse_id', 'warehouses.name')
                ->get();
        }

        $items = Item::with('unit:id,name,code')->whereIn('id', $itemIds)->get()->keyBy('id');

        $rows = $topItems->map(function ($row) use ($siteUsage, $warehouses, $items) {
            $perSite = [];

            foreach ($warehouses as $warehouse) {
                $siteQuantity = $siteUsage
                    ->first(fn ($usage) => (int) $usage->item_id === (int) $row->item_id && (int) $usage->warehouse_id === (int) $warehouse->id);

                $perSite[] = [
                    'warehouse_id'   => $warehouse->id,
                    'warehouse_name' => $warehouse->name,
                    'quantity'       => (float) ($siteQuantity->quantity ?? 0),
                ];
            }

            $item = $items->get($row->item_id);

            return [
                'item_id'        => (int) $row->item_id,
                'item_name'      => $item->name ?? ('Item #' . $row->item_id),
                'unit'           => optional($item?->unit)->name ?? optional($item?->unit)->code,
                'total_quantity' => (float) $row->total_quantity,
                'per_site'       => $perSite,
            ];
        })->values();

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $data = [
                'title'       => 'Material Used Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'filters'     => $filters,
                'warehouses'  => $warehouses,
                'rows'        => $rows,
                'autoPrint'   => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.material-used', $data)
                    ->setPaper('a4', 'landscape')
                    ->download('material-used-report-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.material-used', $data);
        }

        return Inertia::render('Report/MostUsedMaterial', [
            'filters'    => $filters,
            'warehouses' => $warehouses,
            'rows'       => $rows,
        ]);
    }

    public function itemTransactional(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canViewItemTransactional($user), 403, 'You are not allowed to view this report.');
        $canViewAllProjects = $this->canSelectAllProjects($user);
        $warehouses = $this->reportWarehousesForUser($user, $canViewAllProjects);
        $allowedWarehouseIds = $warehouses->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $selectedWarehouseId = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;

        if ($selectedWarehouseId && ! $warehouses->contains('id', $selectedWarehouseId)) {
            abort(403, 'You are not assigned to the selected warehouse.');
        }

        $items = Item::ofAccount()->orderBy('name')->get(['id', 'name', 'code']);

        $filters = [
            'item_id'      => $request->filled('item_id') ? (int) $request->input('item_id') : null,
            'category_id'  => $request->filled('category_id') ? (int) $request->input('category_id') : null,
            'warehouse_id' => $selectedWarehouseId,
            'start_date'   => $request->input('start_date') ?: null,
            'end_date'     => $request->input('end_date') ?: null,
            'transaction_type' => $request->input('transaction_type') ?: null,
            'search'       => $request->input('search') ?: null,
        ];

        $filteredItemIds = collect();
        if ($filters['item_id']) {
            $filteredItemIds = collect([$filters['item_id']]);
        } elseif ($filters['category_id']) {
            $filteredItemIds = Item::ofAccount()
                ->whereHas('categories', fn ($query) => $query->where('categories.id', $filters['category_id']))
                ->pluck('id');
        }

        $rows = collect();
        if ($filteredItemIds->isNotEmpty()) {
            $checkinQuery = DB::table('checkins')
                ->join('checkin_items', 'checkins.id', '=', 'checkin_items.checkin_id')
                ->join('items', 'checkin_items.item_id', '=', 'items.id')
                ->leftJoin('units', 'checkin_items.unit_id', '=', 'units.id')
                ->leftJoin('warehouses as wh', 'checkins.warehouse_id', '=', 'wh.id')
                ->leftJoin('contacts as ct', 'checkins.contact_id', '=', 'ct.id')
                ->leftJoin('users as usr', 'checkins.user_id', '=', 'usr.id')
                ->where('checkins.account_id', getAccountId(1))
                ->whereIn('checkin_items.item_id', $filteredItemIds->all())
                ->whereNull('checkins.deleted_at')
                ->where(function ($query) {
                    $query->whereNull('checkins.draft')->orWhere('checkins.draft', 0);
                })
                ->when(! $canViewAllProjects, fn ($query) => $query->whereIn('checkins.warehouse_id', $allowedWarehouseIds))
                ->when($filters['warehouse_id'], fn ($query) => $query->where('checkins.warehouse_id', $filters['warehouse_id']))
                ->when($filters['start_date'], fn ($query) => $query->whereDate('checkins.date', '>=', $filters['start_date']))
                ->when($filters['end_date'], fn ($query) => $query->whereDate('checkins.date', '<=', $filters['end_date']))
                ->selectRaw(
                    "checkins.id as tx_id,
                    checkins.reference as transaction_no,
                    items.name as item_name,
                    DATE(checkins.date) as tx_date,
                    checkin_items.quantity as quantity,
                    COALESCE(units.name, units.code, '-') as unit,
                    'Checkin' as transaction_type,
                    'checkin' as transaction_key,
                    'IN' as movement,
                    COALESCE(wh.name, '-') as warehouse_name,
                    COALESCE(ct.name, '-') as contact_name,
                    COALESCE(usr.name, '-') as responsible_person,
                    checkins.details as details"
                );

            $checkoutQuery = DB::table('checkouts')
                ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
                ->join('items', 'checkout_items.item_id', '=', 'items.id')
                ->leftJoin('units', 'checkout_items.unit_id', '=', 'units.id')
                ->leftJoin('warehouses as wh', 'checkouts.warehouse_id', '=', 'wh.id')
                ->leftJoin('contacts as ct', 'checkouts.contact_id', '=', 'ct.id')
                ->leftJoin('users as usr', 'checkouts.user_id', '=', 'usr.id')
                ->where('checkouts.account_id', getAccountId(1))
                ->whereIn('checkout_items.item_id', $filteredItemIds->all())
                ->whereNull('checkouts.deleted_at')
                ->where(function ($query) {
                    $query->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0);
                })
                ->when(! $canViewAllProjects, fn ($query) => $query->whereIn('checkouts.warehouse_id', $allowedWarehouseIds))
                ->when($filters['warehouse_id'], fn ($query) => $query->where('checkouts.warehouse_id', $filters['warehouse_id']))
                ->when($filters['start_date'], fn ($query) => $query->whereDate('checkouts.date', '>=', $filters['start_date']))
                ->when($filters['end_date'], fn ($query) => $query->whereDate('checkouts.date', '<=', $filters['end_date']))
                ->selectRaw(
                    "checkouts.id as tx_id,
                    checkouts.reference as transaction_no,
                    items.name as item_name,
                    DATE(checkouts.date) as tx_date,
                    checkout_items.quantity as quantity,
                    COALESCE(units.name, units.code, '-') as unit,
                    'Checkout' as transaction_type,
                    'checkout' as transaction_key,
                    'OUT' as movement,
                    COALESCE(wh.name, '-') as warehouse_name,
                    COALESCE(ct.name, '-') as contact_name,
                    COALESCE(usr.name, '-') as responsible_person,
                    checkouts.details as details"
                );

            $transferQuery = DB::table('transfers')
                ->join('transfer_items', 'transfers.id', '=', 'transfer_items.transfer_id')
                ->join('items', 'transfer_items.item_id', '=', 'items.id')
                ->leftJoin('units', 'transfer_items.unit_id', '=', 'units.id')
                ->leftJoin('warehouses as from_wh', 'transfers.from_warehouse_id', '=', 'from_wh.id')
                ->leftJoin('warehouses as to_wh', 'transfers.to_warehouse_id', '=', 'to_wh.id')
                ->leftJoin('users as usr', 'transfers.user_id', '=', 'usr.id')
                ->where('transfers.account_id', getAccountId(1))
                ->whereIn('transfer_items.item_id', $filteredItemIds->all())
                ->whereNull('transfers.deleted_at')
                ->where(function ($query) {
                    $query->whereNull('transfers.draft')->orWhere('transfers.draft', 0);
                })
                ->when(! $canViewAllProjects, function ($query) use ($allowedWarehouseIds) {
                    $query->where(function ($inner) use ($allowedWarehouseIds) {
                        $inner->whereIn('transfers.from_warehouse_id', $allowedWarehouseIds)
                            ->orWhereIn('transfers.to_warehouse_id', $allowedWarehouseIds);
                    });
                })
                ->when($filters['warehouse_id'], function ($query) use ($filters) {
                    $query->where(function ($inner) use ($filters) {
                        $inner->where('transfers.from_warehouse_id', $filters['warehouse_id'])
                            ->orWhere('transfers.to_warehouse_id', $filters['warehouse_id']);
                    });
                })
                ->when($filters['start_date'], fn ($query) => $query->whereDate('transfers.date', '>=', $filters['start_date']))
                ->when($filters['end_date'], fn ($query) => $query->whereDate('transfers.date', '<=', $filters['end_date']))
                ->selectRaw(
                    "transfers.id as tx_id,
                    transfers.reference as transaction_no,
                    items.name as item_name,
                    DATE(transfers.date) as tx_date,
                    transfer_items.quantity as quantity,
                    COALESCE(units.name, units.code, '-') as unit,
                    'Transfer' as transaction_type,
                    'transfer' as transaction_key,
                    CASE
                        WHEN " . ($filters['warehouse_id'] ?: 'NULL') . " IS NULL THEN 'TRANSFER'
                        WHEN transfers.to_warehouse_id = " . ($filters['warehouse_id'] ?: 'NULL') . " THEN 'IN'
                        ELSE 'OUT'
                    END as movement,
                    CASE
                        WHEN " . ($filters['warehouse_id'] ?: 'NULL') . " IS NULL THEN CONCAT(COALESCE(from_wh.name, '-'), ' -> ', COALESCE(to_wh.name, '-'))
                        WHEN transfers.to_warehouse_id = " . ($filters['warehouse_id'] ?: 'NULL') . " THEN COALESCE(to_wh.name, '-')
                        ELSE COALESCE(from_wh.name, '-')
                    END as warehouse_name,
                    '-' as contact_name,
                    COALESCE(usr.name, '-') as responsible_person,
                    transfers.details as details"
                );

            $union = $checkinQuery
                ->unionAll($checkoutQuery)
                ->unionAll($transferQuery);

            $rows = DB::query()
                ->fromSub($union, 'tx')
                ->when($filters['transaction_type'], function ($query) use ($filters) {
                    $normalized = mb_strtolower((string) $filters['transaction_type']);
                    if (in_array($normalized, ['checkin', 'checkout', 'transfer'], true)) {
                        $query->where('tx.transaction_key', $normalized);
                    }
                })
                ->when($filters['search'], function ($query) use ($filters) {
                    $search = trim((string) $filters['search']);
                    $query->where(function ($inner) use ($search) {
                        $inner->where('tx.transaction_no', 'like', "%{$search}%")
                            ->orWhere('tx.item_name', 'like', "%{$search}%")
                            ->orWhere('tx.details', 'like', "%{$search}%")
                            ->orWhere('tx.transaction_type', 'like', "%{$search}%")
                            ->orWhere('tx.warehouse_name', 'like', "%{$search}%")
                            ->orWhere('tx.contact_name', 'like', "%{$search}%")
                            ->orWhere('tx.responsible_person', 'like', "%{$search}%");
                    });
                })
                ->orderByDesc('tx_date')
                ->orderByDesc('tx_id')
                ->limit(3000)
                ->get()
                ->map(fn ($row) => [
                    'transaction_id'  => (int) $row->tx_id,
                    'transaction_no'  => (string) $row->transaction_no,
                    'item_name'       => (string) $row->item_name,
                    'date'            => (string) $row->tx_date,
                    'quantity'        => (float) $row->quantity,
                    'unit'            => (string) $row->unit,
                    'transaction_type'=> (string) $row->transaction_type,
                    'transaction_key' => (string) $row->transaction_key,
                    'movement'        => (string) $row->movement,
                    'warehouse_name'  => (string) $row->warehouse_name,
                    'contact_name'    => (string) ($row->contact_name ?? '-'),
                    'responsible_person' => (string) ($row->responsible_person ?? '-'),
                    'details'         => (string) ($row->details ?? ''),
                ])
                ->values();
        }

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $itemName = optional($items->firstWhere('id', $filters['item_id']))->name;
            $warehouseName = $filters['warehouse_id']
                ? optional($warehouses->firstWhere('id', $filters['warehouse_id']))->name
                : null;
            $categoryName = $filters['category_id']
                ? optional(Category::query()->find($filters['category_id']))->name
                : null;

            $data = [
                'title'       => 'Item Transactional Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns'     => ['No', 'Transaction No', 'Item Name', 'Date', 'Qty', 'Unit', 'Type', 'Movement', 'Warehouse', 'Contact', 'Responsible Person'],
                'rows'        => $rows->values()->map(fn ($row, $index) => [
                    $index + 1,
                    $row['transaction_no'],
                    $row['item_name'],
                    $row['date'],
                    number_format((float) $row['quantity'], 2),
                    $row['unit'],
                    $row['transaction_type'],
                    $row['movement'],
                    $row['warehouse_name'],
                    $row['contact_name'] ?: '-',
                    $row['responsible_person'],
                ])->all(),
                'meta'        => array_filter([
                    'Item'       => $itemName,
                    'Category'   => $categoryName,
                    'Warehouse'  => $warehouseName,
                    'From'       => $filters['start_date'],
                    'To'         => $filters['end_date'],
                    'Type'       => $filters['transaction_type'],
                    'Search'     => $filters['search'],
                ], fn ($value) => filled($value)),
                'autoPrint'   => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)
                    ->setPaper('a4', 'landscape')
                    ->download('item-transactional-report-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        return Inertia::render('Report/ItemTransactional', [
            'filters'    => $filters,
            'items'      => $items,
            'categories' => Category::ofAccount()->orderBy('name')->get(['id', 'name']),
            'warehouses' => $warehouses,
            'rows'       => $rows,
        ]);
    }

    public function itemTransactionalForm(Request $request, string $transactionKey, int $transactionId)
    {
        $user = $request->user();
        abort_unless($this->canViewItemTransactional($user), 403, 'You are not allowed to view this report.');

        $canViewAllProjects = $this->canSelectAllProjects($user);
        $warehouses = $this->reportWarehousesForUser($user, $canViewAllProjects);
        $allowedWarehouseIds = $warehouses->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $transactionKey = mb_strtolower(trim($transactionKey));

        if ($transactionKey === 'checkin') {
            $checkin = Checkin::query()
                ->where('id', $transactionId)
                ->where('account_id', getAccountId(1))
                ->whereNull('deleted_at')
                ->when(! $canViewAllProjects, fn ($query) => $query->whereIn('warehouse_id', $allowedWarehouseIds))
                ->firstOrFail();

            $checkin->load(['items.variations', 'items.item:id,code,name,track_quantity,track_weight,photo', 'contact', 'warehouse', 'items.unit:id,code,name', 'user:id,name,username', 'attachments']);

            return Inertia::render('Report/ItemTransactionalForm', [
                'transactionType' => 'checkin',
                'transaction'     => new CheckinResource($checkin),
            ]);
        }

        if ($transactionKey === 'checkout') {
            $checkout = Checkout::query()
                ->where('id', $transactionId)
                ->where('account_id', getAccountId(1))
                ->whereNull('deleted_at')
                ->when(! $canViewAllProjects, fn ($query) => $query->whereIn('warehouse_id', $allowedWarehouseIds))
                ->firstOrFail();

            $checkout->load(['items.variations', 'items.item:id,code,name,track_quantity,track_weight,photo', 'contact', 'warehouse', 'workActivity', 'items.unit:id,code,name', 'user:id,name,username', 'attachments']);

            return Inertia::render('Report/ItemTransactionalForm', [
                'transactionType' => 'checkout',
                'transaction'     => new CheckoutResource($checkout),
            ]);
        }

        if ($transactionKey === 'transfer') {
            $transfer = Transfer::query()
                ->where('id', $transactionId)
                ->where('account_id', getAccountId(1))
                ->whereNull('deleted_at')
                ->when(! $canViewAllProjects, function ($query) use ($allowedWarehouseIds) {
                    $query->where(function ($inner) use ($allowedWarehouseIds) {
                        $inner->whereIn('from_warehouse_id', $allowedWarehouseIds)
                            ->orWhereIn('to_warehouse_id', $allowedWarehouseIds);
                    });
                })
                ->firstOrFail();

            $transfer->load(['items.variations', 'items.item:id,code,name,track_quantity,track_weight,price', 'fromWarehouse', 'toWarehouse', 'items.unit:id,code,name', 'user:id,name,username', 'attachments']);

            return Inertia::render('Report/ItemTransactionalForm', [
                'transactionType' => 'transfer',
                'transaction'     => new TransferResource($transfer),
            ]);
        }

        abort(404);
    }

    public function boqMaterialCompliance(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canViewBoqMaterialCompliance($user), 403, 'You are not allowed to view this report.');

        $canViewAllProjects = $this->canSelectAllProjects($user);
        $canViewManagementInsights = (bool) $user && $user->hasRole([
            'Super Admin',
            'Project Manager',
            'Project Managers',
        ]);
        $projects = $this->reportWarehousesForUser($user, $canViewAllProjects)
            ->filter(function ($warehouse) {
                $status = mb_strtolower(trim((string) data_get($warehouse, 'status', '')));
                return $status !== 'main warehouse';
            })
            ->values();
        $projectIds = $projects->pluck('id')->map(fn ($id) => (int) $id)->values();

        $selectedProjectId = $request->filled('project_warehouse_id') ? (int) $request->input('project_warehouse_id') : null;
        $selectedWarehouseId = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;

        if ($selectedProjectId && ! $projects->contains('id', $selectedProjectId)) {
            abort(403, 'You are not assigned to the selected project.');
        }

        if ($selectedWarehouseId && ! $projects->contains('id', $selectedWarehouseId)) {
            abort(403, 'You are not assigned to the selected warehouse.');
        }

        if (! $selectedProjectId && $projects->count() === 1) {
            $selectedProjectId = (int) $projects->first()->id;
        }

        $additionalItemIds = collect($request->input('additional_item_ids', []));
        if (! is_array($request->input('additional_item_ids'))) {
            $additionalItemIds = collect(explode(',', (string) $request->input('additional_item_ids')));
        }
        $additionalItemIds = $additionalItemIds
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $filters = [
            'project_warehouse_id' => $selectedProjectId,
            'warehouse_id' => $selectedWarehouseId,
            'start_date' => $request->input('start_date') ?: null,
            'end_date' => $request->input('end_date') ?: null,
            'search' => $request->input('search') ?: null,
            'additional_item_ids' => $additionalItemIds->all(),
        ];

        $issuedRows = collect();
        if ($selectedProjectId) {
            $issuedRows = DB::table('checkouts')
                ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
                ->join('items', 'checkout_items.item_id', '=', 'items.id')
                ->leftJoin('units', 'checkout_items.unit_id', '=', 'units.id')
                ->where('checkouts.account_id', getAccountId(1))
                ->whereNull('checkouts.deleted_at')
                ->where(function ($query) {
                    $query->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0);
                })
                ->where('checkouts.warehouse_id', $selectedProjectId)
                ->when($selectedWarehouseId, fn ($query) => $query->where('checkouts.warehouse_id', $selectedWarehouseId))
                ->when($filters['start_date'], fn ($query) => $query->whereDate('checkouts.date', '>=', $filters['start_date']))
                ->when($filters['end_date'], fn ($query) => $query->whereDate('checkouts.date', '<=', $filters['end_date']))
                ->groupBy('checkout_items.item_id', 'items.name', 'units.name', 'units.code')
                ->orderBy('items.name')
                ->select(
                    'checkout_items.item_id',
                    'items.name as item_name',
                    DB::raw("COALESCE(units.name, units.code, '-') as unit"),
                    DB::raw('SUM(checkout_items.quantity) as issued_quantity'),
                    DB::raw('SUM(checkout_items.quantity * COALESCE(checkout_items.price, items.price, 0)) as issued_cost')
                )
                ->get();
        }

        $savedBoq = collect();
        $boqTableReady = Schema::hasTable('boq_material_compliances')
            && Schema::hasColumn('boq_material_compliances', 'deleted_at');
        if ($selectedProjectId && $boqTableReady) {
            $savedBoq = BoqMaterialCompliance::query()
                ->ofAccount()
                ->where('project_warehouse_id', $selectedProjectId)
                ->when($selectedWarehouseId, fn ($query) => $query->where('warehouse_id', $selectedWarehouseId))
                ->get(['item_id', 'boq_quantity', 'remarks', 'unit_price'])
                ->keyBy('item_id');
        }

        $issuedItemIds = $issuedRows->pluck('item_id')->map(fn ($id) => (int) $id);
        $savedItemIds = $savedBoq->keys()->map(fn ($id) => (int) $id);
        $itemIds = $issuedItemIds->merge($savedItemIds)->merge($additionalItemIds)->unique()->values();

        $itemMeta = $itemIds->isNotEmpty()
            ? Item::query()
                ->whereIn('id', $itemIds)
                ->with('unit:id,name,code')
                ->get(['id', 'name', 'price'])
                ->keyBy('id')
            : collect();

        $issuedByItem = $issuedRows->keyBy('item_id');

        $rows = $itemIds->map(function ($itemId) use ($itemMeta, $issuedByItem, $savedBoq) {
            $item = $itemMeta->get($itemId);
            $issued = $issuedByItem->get($itemId);
            $saved = $savedBoq->get($itemId);

            $issuedQty = (float) ($issued->issued_quantity ?? 0);
            $issuedCost = (float) ($issued->issued_cost ?? 0);
            $boqQty = (float) ($saved->boq_quantity ?? 0);

            $derivedUnitPrice = $issuedQty > 0 ? ($issuedCost / $issuedQty) : (float) ($item->price ?? 0);
            $unitPrice = (float) ($saved->unit_price ?? $derivedUnitPrice);

            $varianceQty = $issuedQty - $boqQty;
            $boqTotalCost = $boqQty * $unitPrice;
            $issuedTotalCost = $issuedQty * $unitPrice;
            $varianceCost = $issuedTotalCost - $boqTotalCost;
            $consumptionPct = $boqQty > 0 ? (($issuedQty / $boqQty) * 100) : 0;

            $status = 'Not Set';
            if ($boqQty > 0) {
                $ratio = $issuedQty / $boqQty;
                if ($ratio < 0.80) {
                    $status = 'Under Usage';
                } elseif ($ratio <= 1.00) {
                    $status = 'On Track';
                } elseif ($ratio <= 1.10) {
                    $status = 'Slight Overuse';
                } else {
                    $status = 'Overused';
                }
            }

            return [
                'item_id' => (int) $itemId,
                'item_name' => (string) ($item->name ?? ('Item #' . $itemId)),
                'unit' => (string) (optional($item?->unit)->name ?: optional($item?->unit)->code ?: ($issued->unit ?? '-')),
                'issued_quantity' => $issuedQty,
                'boq_quantity' => $boqQty,
                'variance_quantity' => $varianceQty,
                'consumption_percent' => $consumptionPct,
                'unit_price' => $unitPrice,
                'boq_total_cost' => $boqTotalCost,
                'issued_total_cost' => $issuedTotalCost,
                'variance_cost' => $varianceCost,
                'status' => $status,
                'remarks' => (string) ($saved->remarks ?? ''),
            ];
        })
            ->filter(function ($row) use ($filters) {
                if (! $filters['search']) {
                    return true;
                }

                $needle = mb_strtolower(trim((string) $filters['search']));
                return str_contains(mb_strtolower($row['item_name']), $needle)
                    || str_contains(mb_strtolower($row['status']), $needle)
                    || str_contains(mb_strtolower($row['remarks']), $needle);
            })
            ->sortBy('item_name')
            ->values();

        $statusSummary = $rows->groupBy('status')->map->count();

        $totalIssuedQty = (float) $rows->sum('issued_quantity');
        $totalBoqQty = (float) $rows->sum('boq_quantity');
        $projectCost = (float) $rows->sum('issued_total_cost');
        $totalBoqCost = (float) $rows->sum('boq_total_cost');
        $totalVarianceCost = (float) $rows->sum('variance_cost');

        $overview = [
            'rows_count' => (int) $rows->count(),
            'total_issued_quantity' => $totalIssuedQty,
            'total_boq_quantity' => $totalBoqQty,
            'total_issued_cost' => $projectCost,
            'project_cost' => $projectCost,
            'total_boq_cost' => $totalBoqCost,
            'total_variance_cost' => $totalVarianceCost,
            'overall_compliance_percent' => (float) (($totalBoqQty > 0)
                ? (($totalIssuedQty / $totalBoqQty) * 100)
                : 0),
            'budget_utilization_percent' => (float) (($totalBoqCost > 0)
                ? (($projectCost / $totalBoqCost) * 100)
                : 0),
            'cost_variance_percent' => (float) (($totalBoqCost > 0)
                ? (($totalVarianceCost / $totalBoqCost) * 100)
                : 0),
            'materials_with_boq_count' => (int) $rows->filter(fn ($row) => (float) ($row['boq_quantity'] ?? 0) > 0)->count(),
            'on_track_count' => (int) ($statusSummary->get('On Track', 0)),
            'under_usage_count' => (int) ($statusSummary->get('Under Usage', 0)),
            'slight_overuse_count' => (int) ($statusSummary->get('Slight Overuse', 0)),
            'overused_count' => (int) ($statusSummary->get('Overused', 0)),
            'not_set_count' => (int) ($statusSummary->get('Not Set', 0)),
        ];

        $chart = [
            'status_labels' => ['On Track', 'Under Usage', 'Slight Overuse', 'Overused', 'Not Set'],
            'status_series' => [
                (int) ($statusSummary->get('On Track', 0)),
                (int) ($statusSummary->get('Under Usage', 0)),
                (int) ($statusSummary->get('Slight Overuse', 0)),
                (int) ($statusSummary->get('Overused', 0)),
                (int) ($statusSummary->get('Not Set', 0)),
            ],
            'top_variance_materials' => $rows
                ->sortByDesc(fn ($row) => abs((float) $row['variance_cost']))
                ->take(10)
                ->map(fn ($row) => [
                    'name' => $row['item_name'],
                    'value' => round((float) $row['variance_cost'], 2),
                ])
                ->values()
                ->all(),
        ];

        $materialOptions = Item::ofAccount()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($item) => ['id' => (int) $item->id, 'name' => (string) $item->name])
            ->values();

        $boqNote = 'To keep compliance and cost results accurate, enter BOQ Qty for every listed material. Missing BOQ Qty values can distort compliance percentages and variance calculations.';

        if ($request->boolean('json')) {
            return response()->json([
                'rows' => $rows,
                'overview' => $overview,
                'chart' => $chart,
            ]);
        }

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $projectName = $selectedProjectId ? optional($projects->firstWhere('id', $selectedProjectId))->name : 'N/A';
            $warehouseName = $selectedWarehouseId ? optional($projects->firstWhere('id', $selectedWarehouseId))->name : 'All';

            $data = [
                'title' => 'BOQ Material Compliance Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns' => ['No', 'Material', 'Unit', 'BOQ Qty', 'Issued Qty', 'Variance Qty', 'Compliance %', 'Unit Price', 'BOQ Cost', 'Issued Cost', 'Variance Cost', 'Status', 'Remarks'],
                'rows' => $rows->values()->map(fn ($row, $index) => [
                    $index + 1,
                    $row['item_name'],
                    $row['unit'],
                    number_format((float) $row['boq_quantity'], 2),
                    number_format((float) $row['issued_quantity'], 2),
                    number_format((float) $row['variance_quantity'], 2),
                    number_format((float) $row['consumption_percent'], 2),
                    number_format((float) $row['unit_price'], 2),
                    number_format((float) $row['boq_total_cost'], 2),
                    number_format((float) $row['issued_total_cost'], 2),
                    number_format((float) $row['variance_cost'], 2),
                    $row['status'],
                    $row['remarks'] ?: '-',
                ])->all(),
                'meta' => array_filter([
                    'Project' => $projectName,
                    'Warehouse' => $warehouseName,
                    'From' => $filters['start_date'],
                    'To' => $filters['end_date'],
                    'Search' => $filters['search'],
                    'Total BOQ Cost' => number_format((float) $overview['total_boq_cost'], 2),
                    'Project Cost' => number_format((float) $overview['project_cost'], 2),
                    'Overall Compliance %' => number_format((float) $overview['overall_compliance_percent'], 2),
                    'Total Variance Cost' => number_format((float) $overview['total_variance_cost'], 2),
                    'Important Note' => $boqNote,
                ], fn ($value) => filled($value)),
                'autoPrint' => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)
                    ->setPaper('a4', 'landscape')
                    ->download('boq-material-compliance-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        if ($request->query('output') === 'excel') {
            $projectName = $selectedProjectId ? optional($projects->firstWhere('id', $selectedProjectId))->name : 'N/A';
            $warehouseName = $selectedWarehouseId ? optional($projects->firstWhere('id', $selectedWarehouseId))->name : 'All';
            $displayCompanyName = preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) (optional(auth()->user()->account ?? null)->name ?? 'RANS WIMS'));
            $headerRow = ['No', 'Material', 'Unit', 'BOQ Qty', 'Issued Qty', 'Variance Qty', 'Compliance %', 'Unit Price', 'BOQ Cost', 'Issued Cost', 'Variance Cost', 'Status', 'Remarks'];
            $blankRow = array_fill(0, count($headerRow), '');

            $exportRows = collect([
                ['', 'BOQ Material Compliance Report', '', '', '', '', '', '', '', '', '', '', ''],
                ['', $displayCompanyName, '', '', '', '', '', '', '', '', '', '', ''],
                ['', 'Project: ' . $projectName, 'Warehouse: ' . $warehouseName, 'From: ' . ($filters['start_date'] ?: '-'), 'To: ' . ($filters['end_date'] ?: '-'), '', '', '', '', '', '', '', ''],
                ['', 'Total BOQ Cost: ' . number_format((float) $overview['total_boq_cost'], 2), 'Project Cost: ' . number_format((float) $overview['project_cost'], 2), 'Overall Compliance %: ' . number_format((float) $overview['overall_compliance_percent'], 2), 'Total Variance Cost: ' . number_format((float) $overview['total_variance_cost'], 2), '', '', '', '', '', '', '', ''],
                ['', 'Important Note: ' . $boqNote, '', '', '', '', '', '', '', '', '', '', ''],
                $blankRow,
                $headerRow,
            ])->concat($rows->values()->map(fn ($row, $index) => [
                $index + 1,
                $row['item_name'],
                $row['unit'],
                (float) $row['boq_quantity'],
                (float) $row['issued_quantity'],
                (float) $row['variance_quantity'],
                (float) $row['consumption_percent'],
                (float) $row['unit_price'],
                (float) $row['boq_total_cost'],
                (float) $row['issued_total_cost'],
                (float) $row['variance_cost'],
                $row['status'],
                $row['remarks'],
            ]));

            return (new FastExcel($exportRows))->withoutHeaders()->download('boq-material-compliance-' . now()->format('Ymd-His') . '.xlsx');
        }

        return Inertia::render('Report/BoqMaterialCompliance', [
            'filters' => $filters,
            'projects' => $projects->map(fn ($warehouse) => [
                'id' => (int) $warehouse->id,
                'name' => (string) $warehouse->name,
                'completed_at' => $warehouse->completed_at,
            ])->values(),
            'warehouses' => $projects->map(fn ($warehouse) => [
                'id' => (int) $warehouse->id,
                'name' => (string) $warehouse->name,
                'completed_at' => $warehouse->completed_at,
            ])->values(),
            'rows' => $rows,
            'overview' => $overview,
            'chart' => $chart,
            'materialOptions' => $materialOptions,
            'canViewAllProjects' => $canViewAllProjects,
            'canViewManagementInsights' => $canViewManagementInsights,
        ]);
    }

    public function saveBoqMaterialCompliance(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canViewBoqMaterialCompliance($user), 403, 'You are not allowed to edit this report.');

        if (! Schema::hasTable('boq_material_compliances')) {
            return back()->with('error', 'BOQ baseline table is missing. Please run database migrations first.');
        }

        if (! Schema::hasColumn('boq_material_compliances', 'deleted_at')) {
            return back()->with('error', 'BOQ baseline schema is outdated. Please run database migrations first.');
        }

        $validated = $request->validate([
            'project_warehouse_id' => ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'materials' => ['required', 'array'],
            'materials.*.item_id' => ['required', 'integer'],
            'materials.*.boq_quantity' => ['nullable', 'numeric', 'min:0'],
            'materials.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'materials.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $canViewAllProjects = $this->canSelectAllProjects($user);
        $projects = $this->reportWarehousesForUser($user, $canViewAllProjects);
        $projectId = (int) $validated['project_warehouse_id'];
        $warehouseId = isset($validated['warehouse_id']) && $validated['warehouse_id'] !== null
            ? (int) $validated['warehouse_id']
            : $projectId;

        if (! $projects->contains('id', $projectId) || ! $projects->contains('id', $warehouseId)) {
            abort(403, 'You are not assigned to the selected project/warehouse.');
        }

        $materials = collect($validated['materials'])
            ->map(fn ($row) => [
                'item_id' => (int) $row['item_id'],
                'boq_quantity' => (float) ($row['boq_quantity'] ?? 0),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'remarks' => trim((string) ($row['remarks'] ?? '')),
            ])
            ->unique('item_id')
            ->values();

        DB::transaction(function () use ($projectId, $warehouseId, $materials) {
            $itemIds = $materials->pluck('item_id')->all();

            BoqMaterialCompliance::query()
                ->ofAccount()
                ->where('project_warehouse_id', $projectId)
                ->where('warehouse_id', $warehouseId)
                ->when(! empty($itemIds), fn ($query) => $query->whereNotIn('item_id', $itemIds))
                ->when(empty($itemIds), fn ($query) => $query)
                ->delete();

            foreach ($materials as $row) {
                BoqMaterialCompliance::query()->updateOrCreate(
                    [
                        'account_id' => getAccountId(1),
                        'project_warehouse_id' => $projectId,
                        'warehouse_id' => $warehouseId,
                        'item_id' => $row['item_id'],
                    ],
                    [
                        'boq_quantity' => $row['boq_quantity'],
                        'unit_price' => $row['unit_price'],
                        'remarks' => $row['remarks'] ?: null,
                    ]
                );
            }
        });

        return back()->with('message', 'BOQ material compliance baseline has been updated.');
    }

    private function canViewItemTransactional(?User $user): bool
    {
        return (bool) $user && $user->hasRole([
            'Super Admin',
            'Admin',
            'Procurement Manager',
            'Procurement Manger',
            'Project Manager',
            'Project Managers',
        ]);
    }

    private function canViewBoqMaterialCompliance(?User $user): bool
    {
        return (bool) $user && $user->hasRole([
            'Super Admin',
            'Project Manager',
            'Project Managers',
        ]);
    }

    public function adjustment(Request $request)
    {
        $filters = $request->all('start_date', 'end_date', 'start_created_at', 'end_created_at', 'reference', 'user_id', 'warehouse_id', 'draft', 'trashed', 'category_id', 'item_id', 'search');

        $query = Adjustment::with(['warehouse', 'user'])
            ->reportFilter($filters)
            ->when(!empty($filters['search']), fn ($q) => $q->search($filters['search']))
            ->orderByDesc('id');

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $rows = $query->limit(3000)->get();

            $data = [
                'title'       => 'Adjustment Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns'     => ['Reference', 'Date', 'Warehouse', 'User', 'Draft', 'Details'],
                'rows'        => $rows->map(fn ($adjustment) => [
                    $adjustment->reference,
                    optional($adjustment->date)->format('Y-m-d'),
                    optional($adjustment->warehouse)->name,
                    optional($adjustment->user)->name,
                    $adjustment->draft ? 'Yes' : 'No',
                    (string) $adjustment->details,
                ])->values()->all(),
                'meta'        => array_filter([
                    'From'      => $filters['start_date'] ?? null,
                    'To'        => $filters['end_date'] ?? null,
                    'Reference' => $filters['reference'] ?? null,
                    'Search'    => $filters['search'] ?? null,
                ], fn ($v) => filled($v)),
                'autoPrint'   => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)->download('adjustment-report-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        return Inertia::render('Report/Adjustment', [
            'filters'     => $filters,
            'users'       => User::ofAccount()->get(),
            'categories'  => Category::ofAccount()->get(),
            'warehouses'  => Warehouse::ofAccount()->active()->visible()->get(),
            'listing'     => $this->warehouseItemListing(),
            'adjustments' => new AdjustmentCollection(
                $query->paginate()->withQueryString()
            ),
        ]);
    }

    public function checkin(Request $request)
    {
        $filters = $request->all('start_date', 'end_date', 'start_created_at', 'end_created_at', 'reference', 'contact_id', 'user_id', 'warehouse_id', 'draft', 'trashed', 'category_id', 'item_id', 'search');

        $query = Checkin::with(['contact', 'warehouse', 'user'])
            ->reportFilter($filters)
            ->when(!empty($filters['search']), fn ($q) => $q->search($filters['search']))
            ->orderByDesc('id');

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $rows = $query->limit(3000)->get();

            $data = [
                'title'       => 'Checkin Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns'     => ['Reference', 'Date', 'Contact', 'Warehouse', 'User', 'Draft', 'Details'],
                'rows'        => $rows->map(fn ($checkin) => [
                    $checkin->reference,
                    optional($checkin->date)->format('Y-m-d'),
                    optional($checkin->contact)->name,
                    optional($checkin->warehouse)->name,
                    optional($checkin->user)->name,
                    $checkin->draft ? 'Yes' : 'No',
                    (string) $checkin->details,
                ])->values()->all(),
                'meta'        => array_filter([
                    'From'      => $filters['start_date'] ?? null,
                    'To'        => $filters['end_date'] ?? null,
                    'Reference' => $filters['reference'] ?? null,
                    'Search'    => $filters['search'] ?? null,
                ], fn ($v) => filled($v)),
                'autoPrint'   => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)->download('checkin-report-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        return Inertia::render('Report/Checkin', [
            'filters'    => $filters,
            'users'      => User::ofAccount()->get(),
            'contacts'   => Contact::ofAccount()->get(),
            'categories' => Category::ofAccount()->get(),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(),
            'listing'    => $this->warehouseItemListing(),
            'checkins'   => new CheckinCollection(
                $query->paginate()->withQueryString()
            ),
        ]);
    }

    public function checkout(Request $request)
    {
        $filters = $request->all('start_date', 'end_date', 'start_created_at', 'end_created_at', 'reference', 'contact_id', 'user_id', 'warehouse_id', 'draft', 'trashed', 'category_id', 'item_id', 'search');

        $query = Checkout::with(['contact', 'warehouse', 'user'])
            ->reportFilter($filters)
            ->when(!empty($filters['search']), fn ($q) => $q->search($filters['search']))
            ->orderByDesc('id');

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $rows = $query->limit(3000)->get();

            $data = [
                'title'       => 'Checkout Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns'     => ['Reference', 'Date', 'Contact', 'Warehouse', 'User', 'Draft', 'Details'],
                'rows'        => $rows->map(fn ($checkout) => [
                    $checkout->reference,
                    optional($checkout->date)->format('Y-m-d'),
                    optional($checkout->contact)->name,
                    optional($checkout->warehouse)->name,
                    optional($checkout->user)->name,
                    $checkout->draft ? 'Yes' : 'No',
                    (string) $checkout->details,
                ])->values()->all(),
                'meta'        => array_filter([
                    'From'      => $filters['start_date'] ?? null,
                    'To'        => $filters['end_date'] ?? null,
                    'Reference' => $filters['reference'] ?? null,
                    'Search'    => $filters['search'] ?? null,
                ], fn ($v) => filled($v)),
                'autoPrint'   => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)->download('checkout-report-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        return Inertia::render('Report/Checkout', [
            'filters'    => $filters,
            'users'      => User::ofAccount()->get(),
            'contacts'   => Contact::ofAccount()->get(),
            'categories' => Category::ofAccount()->get(),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(),
            'listing'    => $this->warehouseItemListing(),
            'checkouts'  => new CheckoutCollection(
                $query->paginate()->withQueryString()
            ),
        ]);
    }

    public function index()
    {
        $user = auth()->user();
        abort_unless($user && $user->hasPermissionTo('read-reports'), 403, 'You are not allowed to view this page.');

        $accountId = getAccountId(1);
        $metricYear = 2026;

        $data = Item::selectRaw('COUNT(*) as items')->without('unit')
            ->addSelect(['contacts' => Contact::selectRaw('COUNT(*) as contacts')])
            ->addSelect(['categories' => Category::selectRaw('COUNT(*) as categories')])
            ->addSelect(['warehouses' => Warehouse::visible()->selectRaw('COUNT(*) as warehouses')])
            ->addSelect(['checkins' => Checkin::selectRaw('COUNT(*) as checkins')])
            ->addSelect(['checkouts' => Checkout::selectRaw('COUNT(*) as checkouts')])
            ->addSelect(['transfers' => Transfer::selectRaw('COUNT(*) as transfers')])
            ->addSelect(['adjustments' => Adjustment::selectRaw('COUNT(*) as adjustments')])
            ->addSelect(['units' => Unit::selectRaw('COUNT(*) as units')])
            ->addSelect(['users' => User::selectRaw('COUNT(*) as users')])
            ->addSelect(['roles' => Role::selectRaw('COUNT(*) as roles')])
            ->first();

        $totalInventoryValue = (float) (DB::table('stocks')
            ->join('items', 'stocks.item_id', '=', 'items.id')
            ->where('stocks.account_id', $accountId)
            ->selectRaw('SUM(stocks.quantity * COALESCE(items.price, 0)) as total_value')
            ->value('total_value') ?? 0);

        $yearlyConsumption = (float) (DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->join('items', 'checkout_items.item_id', '=', 'items.id')
            ->where('checkouts.account_id', $accountId)
            ->whereNull('checkouts.deleted_at')
            ->where(function ($query) {
                $query->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0);
            })
            ->whereYear('checkouts.date', $metricYear)
            ->selectRaw('SUM(checkout_items.quantity * COALESCE(checkout_items.price, items.price, 0)) as yearly_total')
            ->value('yearly_total') ?? 0);

        // In this dashboard, projects are represented by active warehouses.
        $activeProjects = (int) Warehouse::ofAccount()->active()->visible()->count();

        $topExpensiveMaterials = Item::ofAccount()
            ->select('id', 'name', DB::raw('COALESCE(price, 0) as unit_price'))
            ->orderByDesc(DB::raw('COALESCE(price, 0)'))
            ->limit(3)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'value' => (float) $row->unit_price,
            ])
            ->values();

        $warehouseConsumptionSub = DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->join('items', 'checkout_items.item_id', '=', 'items.id')
            ->where('checkouts.account_id', $accountId)
            ->whereNull('checkouts.deleted_at')
            ->where(function ($query) {
                $query->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0);
            })
            ->whereYear('checkouts.date', $metricYear)
            ->whereNotNull('checkouts.warehouse_id')
            ->groupBy('checkouts.warehouse_id')
            ->selectRaw('checkouts.warehouse_id')
            ->selectRaw('SUM(checkout_items.quantity * COALESCE(checkout_items.price, items.price, 0)) as total_value');

        $topConsumingProjects = DB::table('warehouses')
            ->leftJoinSub($warehouseConsumptionSub, 'consumption', function ($join) {
                $join->on('warehouses.id', '=', 'consumption.warehouse_id');
            })
            ->where('warehouses.account_id', $accountId)
            ->where('warehouses.active', 1)
            ->select('warehouses.name')
            ->selectRaw('COALESCE(consumption.total_value, 0) as total_value')
            ->orderByDesc('total_value')
            ->orderBy('warehouses.name')
            ->limit(3)
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->name,
                'value' => (float) $row->total_value,
            ])
            ->values();

        $mapWarehouses = Warehouse::ofAccount()
            ->visible()
            ->get(['id', 'name', 'code', 'status', 'address', 'phone', 'email', 'active', 'latitude', 'longitude', 'completed_at'])
            ->map(fn ($warehouse) => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'status' => $warehouse->status,
                'address' => $warehouse->address,
                'phone' => $warehouse->phone,
                'email' => $warehouse->email,
                'active' => (bool) $warehouse->active,
                'latitude' => $warehouse->latitude,
                'longitude' => $warehouse->longitude,
                'completed_at' => $warehouse->completed_at,
            ])
            ->values();

        return Inertia::render('Report/Index', [
            'data' => $data,
            'kpi' => [
                'year_label' => (string) $metricYear,
                'total_inventory_value' => $totalInventoryValue,
                'yearly_consumption' => $yearlyConsumption,
                'active_projects' => $activeProjects,
                'top_expensive_materials' => $topExpensiveMaterials,
                'top_consuming_projects' => $topConsumingProjects,
            ],
            'mapWarehouses' => $mapWarehouses,
        ]);
    }

    public function transfer(Request $request)
    {
        $filters = $request->all('start_date', 'end_date', 'start_created_at', 'end_created_at', 'reference', 'from_warehouse_id', 'user_id', 'to_warehouse_id', 'draft', 'trashed', 'category_id', 'item_id', 'search');

        $warehouses = Warehouse::all();

        $listing = $this->warehouseItemListing();

        $query = Transfer::with(['fromWarehouse', 'toWarehouse', 'user'])
            ->when(!empty($filters['item_id']), function ($query) use ($filters) {
                $query->whereHas('items', function ($q) use ($filters) {
                    $q->where('item_id', $filters['item_id']);
                });
            })
            ->reportFilter($filters)
            ->when(!empty($filters['search']), fn ($q) => $q->search($filters['search']))
            ->orderByDesc('id');

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $rows = $query->limit(3000)->get();

            $data = [
                'title'       => 'Transfer Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns'     => ['Reference', 'Date', 'From Warehouse', 'To Warehouse', 'User', 'Draft', 'Details'],
                'rows'        => $rows->map(fn ($transfer) => [
                    $transfer->reference,
                    optional($transfer->date)->format('Y-m-d'),
                    optional($transfer->fromWarehouse)->name,
                    optional($transfer->toWarehouse)->name,
                    optional($transfer->user)->name,
                    $transfer->draft ? 'Yes' : 'No',
                    (string) $transfer->details,
                ])->values()->all(),
                'meta'        => array_filter([
                    'From'      => $filters['start_date'] ?? null,
                    'To'        => $filters['end_date'] ?? null,
                    'Reference' => $filters['reference'] ?? null,
                    'Search'    => $filters['search'] ?? null,
                ], fn ($v) => filled($v)),
                'autoPrint'   => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)->download('transfer-report-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        $transfersQuery = $query->paginate()->withQueryString();

        return Inertia::render('Report/Transfer', [
            'filters'    => $filters,
            'users'      => User::ofAccount()->get(),
            'categories' => Category::ofAccount()->get(),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(),
            'listing' => $listing,
            'transfers' => new TransferCollection($transfersQuery),
            // 'transfers'  => new TransferCollection(
            //     Transfer::with(['fromWarehouse', 'toWarehouse', 'user'])->reportFilter($filters)->orderByDesc('id')->paginate()->withQueryString()
            // ),
        ]);
    }

    public function general(Request $request)
    {
        $user = auth()->user();
        $listing = $this->generalListing($user);
        $grandTotal = $listing->sum('total');

        if ($request->query('output') === 'print') {
            return view('reports.general', [
                'title'       => 'General Stock Report',
                'listing'     => $listing,
                'grandTotal'  => $grandTotal,
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'generatedAt' => now(),
                'autoPrint'   => true,
            ]);
        }

        return Inertia::render('Report/General', [
            'listing'    => $listing,
            'grandTotal' => $grandTotal,
        ]);
    }

    public function generalPdf(Request $request)
    {
        $this->prepareHeavyPdfRender();

        $user = auth()->user();
        $listing = $this->generalListing($user);
        $grandTotal = $listing->sum('total');

        $pdf = Pdf::loadView('reports.general', [
            'title'       => 'General Stock Report',
            'listing'     => $listing,
            'grandTotal'  => $grandTotal,
            'companyName' => optional(auth()->user()->account ?? null)->name,
            'generatedAt' => now(),
        ]);

        return $pdf->download('general-stock-report-' . now()->format('Ymd-His') . '.pdf');
    }

    private function warehouseItemListing()
    {
        $warehouses = Warehouse::ofAccount()->active()->visible()->orderBy('name')->get();

        // One query for the stock of every warehouse (was one query per warehouse), grouped in PHP.
        $stockByWarehouse = Stock::whereIn('warehouse_id', $warehouses->pluck('id'))
            ->where('quantity', '>', 0)
            ->without(['variation', 'warehouse'])
            ->with(['item' => fn ($q) => $q->select('id', 'name', 'code', 'price')->with(['categories' => fn ($c) => $c->select('categories.id', 'categories.name')])])
            ->orderBy('id')
            ->get()
            ->groupBy('warehouse_id');

        return $warehouses->map(function ($warehouse) use ($stockByWarehouse) {
            $stockItems = $stockByWarehouse->get($warehouse->id) ?? collect();

            $items = $stockItems->filter(fn ($stockItem) => $stockItem->item)
                ->map(function ($stockItem) {
                    $category = $stockItem->item->categories->first();

                    return [
                        'id'           => $stockItem->item->id,
                        'name'         => $stockItem->item->name ?? 'N/A',
                        'code'         => $stockItem->item->code ?? 'N/A',
                        'category_id'  => optional($category)->id,
                        'category_name'=> optional($category)->name,
                        'quantity'     => $stockItem->quantity,
                        'price'        => $stockItem->item->price ?? 0,
                        'total_amount' => $stockItem->quantity * ($stockItem->item->price ?? 0),
                    ];
                })->values();

            return [
                'id'     => $warehouse->id,
                'name'   => $warehouse->name,
                'items'  => $items,
            ];
        })->values();
    }

    public function generalListing(?User $user = null)
    {
        $isSuperOrAdmin = $user && $user->hasRole(['Super Admin', 'Admin']);
        $warehouseQuery = Warehouse::where('account_id', getAccountId(1))->where('active', 1)->visible()->orderBy('name');

        if (!$isSuperOrAdmin && $user) {
            $assignedIds = $user->warehouses()->where('warehouses.active', 1)->pluck('warehouses.id');
            if ($assignedIds->isNotEmpty()) {
                $warehouseQuery->whereIn('id', $assignedIds);
            } elseif ($user->warehouse_id) {
                $warehouseQuery->where('id', $user->warehouse_id);
            } else {
                $warehouseQuery->whereRaw('0=1');
            }
        }

        $warehouses = $warehouseQuery->get();

        // One query for the stock of every warehouse (was one query per warehouse), grouped in PHP.
        $stockByWarehouse = Stock::main()
            ->whereIn('warehouse_id', $warehouses->pluck('id'))
            ->where('quantity', '>', 0)
            ->without(['variation', 'warehouse'])
            ->with('item:id,code,name,price,unit_id', 'item.unit:id,code,name')
            ->orderBy('id')
            ->get()
            ->groupBy('warehouse_id');

        return $warehouses
            ->map(function ($warehouse) use ($stockByWarehouse) {
                $stocks = $stockByWarehouse->get($warehouse->id) ?? collect();

                $items = $stocks
                    ->filter(fn ($s) => $s->item)
                    ->values()
                    ->map(function ($s) {
                        $price = (float) ($s->item->price ?? 0);
                        $quantity = (float) $s->quantity;

                        return [
                            'id'       => $s->item->id,
                            'code'     => $s->item->code,
                            'name'     => $s->item->name,
                            'unit'     => $s->item->unit->name ?? $s->item->unit->code ?? '-',
                            'quantity' => $quantity,
                            'price'    => $price,
                            'total'    => $quantity * $price,
                        ];
                    });

                return [
                    'id'    => $warehouse->id,
                    'name'  => $warehouse->name,
                    'code'  => $warehouse->code,
                    'total' => $items->sum('total'),
                    'items' => $items->values()->all(),
                ];
            })
            ->values();
    }

    private function prepareHeavyPdfRender(): void
    {
        // The general stock PDF can be very large for accounts with many warehouses/items.
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
    }

    // FURTHER REPORTS

    //DONE
    /**
     * Stock value (quantity x item price) per warehouse in two lean queries instead of one Eloquent query per warehouse.
     * Stock rows whose item no longer exists are ignored, exactly like the old per-warehouse filter.
     *
     * @return array<int, float>
     */
    private function warehouseValueMap(array $warehouseIds): array
    {
        if (! $warehouseIds) {
            return [];
        }
        $prices = Item::query()->select('id', 'price')->toBase()->get()->pluck('price', 'id');
        $rows = Stock::query()->whereIn('warehouse_id', $warehouseIds)
            ->select('warehouse_id', 'item_id', DB::raw('SUM(quantity) as qty'))
            ->groupBy('warehouse_id', 'item_id')->toBase()->get();
        $out = [];
        foreach ($rows as $r) {
            if (! $prices->has($r->item_id)) {
                continue;
            }
            $out[$r->warehouse_id] = ($out[$r->warehouse_id] ?? 0) + $r->qty * $prices[$r->item_id];
        }

        return $out;
    }

    public function warehousestock(Request $request)
    {
        $warehouseId = $request->query('warehouseId');
        $warehouse = Warehouse::find($warehouseId);
        $warehouses = Warehouse::visible()->orderBy('name')->get();

        $valueMap = $this->warehouseValueMap($warehouses->pluck('id')->all());
        $warehouseTotals = $warehouses->map(function ($warehouse) use ($valueMap) {
            $totalAmountInStore = $valueMap[$warehouse->id] ?? 0;

            return [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'amount' => $totalAmountInStore,
                'completed_at' => $warehouse->completed_at,
            ];
        });

        $output = strtolower((string) $request->query('output'));
        $wantsPrint = in_array($output, ['print', 'pdf'], true);

        // Printing/downloading needs an actual project/warehouse to report on
        // — the button only ever appears once one is selected, so a request
        // without one here is not a normal path a user can reach.
        abort_if($wantsPrint && ! $warehouse, 404);

        if (! $warehouse) {
            return Inertia::render('Report/Stock', [
                'stocklist' => [],
                'warehouses' => $warehouseTotals,
                'selectedWarehouseId' => null,
            ]);
        }

        $formattedStock = Stock::where('warehouse_id', $warehouseId)
            ->where('quantity', '>', 0)
            ->with('item.unit')
            ->get()
            ->filter(fn ($stock) => $stock->item)
            ->sortBy(fn ($stock) => $stock->item->name)
            ->values()
            ->map(function ($stock, $index) {
                $price = (float) ($stock->item->price ?? 0);
                $quantity = (float) $stock->quantity;

                return [
                    'no' => $index + 1,
                    'item_id' => $stock->item_id,
                    'item_name' => $stock->item->name,
                    'code' => $stock->item->code,
                    'unit' => optional($stock->item->unit)->name,
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => round($quantity * $price, 2),
                ];
            });

        if ($wantsPrint) {
            $data = [
                'title' => 'Warehouse Stock Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'warehouse' => $warehouse,
                'stocklist' => $formattedStock,
                'totalQuantity' => $formattedStock->sum('quantity'),
                'totalValue' => $formattedStock->sum('total'),
                'generatedAt' => now(),
                'generatedBy' => optional(auth()->user())->name,
                'autoPrint' => $output === 'print',
            ];

            if ($output === 'pdf') {
                $this->prepareHeavyPdfRender();

                return Pdf::loadView('reports.warehouse-stock-pdf', $data)
                    ->setPaper('a4', 'portrait')
                    ->download('warehouse-stock-' . Str::slug($warehouse->name) . '-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.warehouse-stock-pdf', $data);
        }

        return Inertia::render('Report/Stock', [
            'stocklist' => $formattedStock,
            'warehouses' => $warehouseTotals,
            'selectedWarehouseId' => (int) $warehouse->id,
        ]);
    }

    //DONE
    public function warehouseamounts(Request $request)
    {
        $warehouses = Warehouse::visible()->get();

        $valueMap = $this->warehouseValueMap($warehouses->pluck('id')->all());
        $warehouseTotals = $warehouses->map(function ($warehouse) use ($valueMap) {
            $totalAmountInStore = $valueMap[$warehouse->id] ?? 0;

            return [
                'id' => $warehouse->id,
                'name' => $warehouse->name, // Assuming Warehouse has a 'name' column
                'amount' => $totalAmountInStore,
                // Softcoded off `status` — the real "Project(s) / Warehouse(s)
                // Type" field a Super Admin picks on the warehouse form
                // (Main Warehouse / Directors / Private / Government /
                // General / Rans Warehouse). A blank one falls into its own
                // "Uncategorized" bucket rather than being silently folded
                // into any of those explicit choices.
                'category' => $warehouse->status ?: 'Uncategorized',
            ];
        });

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $data = [
                'title' => 'Warehouse Stock Value Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'warehouses' => $warehouseTotals,
                'totalValue' => $warehouseTotals->sum('amount'),
                'generatedAt' => now(),
                'generatedBy' => optional(auth()->user())->name,
                'autoPrint' => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                $this->prepareHeavyPdfRender();

                return Pdf::loadView('reports.warehouse-amounts', $data)
                    ->setPaper('a4', 'landscape')
                    ->download('warehouse-stock-value-report-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.warehouse-amounts', $data);
        }

        return Inertia::render("Report/Amounts", [
            'listing' => $warehouseTotals
        ]);
    }
    
    public function warehousecheckin(Request $request)
    {
        $warehouses = Warehouse::visible()->get();
        $warehouseId = $request->input('w');
        $startDate = $request->input('s');
        $endDate = $request->input('e');
        // Query to get all checkins for the specified warehouse within the date range
        $itemsDelivered = Checkin::where('warehouse_id', $warehouseId) // Assuming warehouse_id is in Checkin table
            ->whereBetween('date', [$startDate, $endDate]) // Date range filter
            ->with([
                'checkinItems' => function ($query) {
                    $query->select('item_id', 'quantity', 'price'); // Assuming price is a field in CheckinItem
                }
            ])
            ->get()
            ->flatMap(function ($checkin) {
                return $checkin->checkinItems->map(function ($checkinItem) {
                    return [
                        'item_id' => $checkinItem->item_id,
                        'item_name' => $checkinItem->item->name, // Assuming there's a relationship to Item model
                        'quantity_delivered' => $checkinItem->quantity,
                        'amount_delivered' => $checkinItem->quantity * $checkinItem->price, // Assuming price is a field in CheckinItem
                        'date' => $checkinItem->created_at
                    ];
                });
            })
            ->groupBy('item_id');

        // Summarize total quantity and amount for each item
        $summarizedDelivery = $itemsDelivered->map(function ($itemGroup) {
            return [
                'item_id' => $itemGroup->first()['item_id'],
                'item_name' => $itemGroup->first()['item_name'],
                'total_quantity_delivered' => $itemGroup->sum('quantity_delivered'),
                'total_amount_delivered' => $itemGroup->sum('amount_delivered')
            ];
        });

        return Inertia::render("Report/WarehouseCheckin", [
            "checkins" => $summarizedDelivery, 
            "warehouses" => $warehouses
        ]);
    }

    


    public function emailReport(Request $request)
    {
        $request->validate([
            'type'  => 'required|in:checkout,checkin,transfer,adjustment,general',
            'id'    => 'required_unless:type,general|integer',
            'email' => 'required|email',
        ]);

        $type = $request->input('type');
        $id = $request->input('id');
        $email = $request->input('email');

        if ($type === 'general') {
            $this->prepareHeavyPdfRender();

            $listing = $this->generalListing();
            $title = 'General Stock Report';
            $pdf = Pdf::loadView('reports.general', [
                'title'       => $title,
                'listing'     => $listing,
                'grandTotal'  => $listing->sum('total'),
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'generatedAt' => now(),
            ])->output();
            $filename = 'general-stock-report-' . now()->format('Ymd-His') . '.pdf';
        } else {
            $record = $this->loadRecord($type, $id);
            abort_unless($record, 404, 'Record not found');

            $title = ucfirst($type) . ' ' . ($record->reference ?? '');
            $pdf = Pdf::loadView('reports.record', [
                'type' => $type,
                'record' => $record,
                'title' => $title,
                'companyName' => optional(auth()->user()->account ?? null)->name,
            ])->output();

            $filename = $type . '-' . ($record->reference ?: $record->id) . '.pdf';
        }

        try {
            Mail::to($email)->send(new ReportMail($title, $pdf, $filename, optional(auth()->user()->account ?? null)->name));
        } catch (\Throwable $e) {
            Log::error('Report email failed', ['email' => $email, 'type' => $type, 'id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json(['status' => 'ok', 'message' => 'Sent to ' . $email]);
    }

    private function canViewUsageMaterialPerSiteCost(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && $user->hasRole([
            'Super Admin',
            'Admin',
            'Project Manager',
            'Project Managers',
            'Procurement Manager',
            'Procurement Manger',
            ...User::SITE_ENGINEER_ROLES,
        ]);
    }

    private function getAccessibleWarehousesForUser(?User $user)
    {
        if (!$user) {
            return collect();
        }

        // Super Admin and Admin can see all warehouses
        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return Warehouse::where('account_id', $user->account_id)->where('active', 1)->visible()->orderBy('name')->get(['id', 'name', 'completed_at']);
        }

        // Other roles: return assigned warehouses only
        $assigned = $user->warehouses()->where('warehouses.active', 1)->visible()->orderBy('warehouses.name')->get(['warehouses.id', 'warehouses.name', 'warehouses.completed_at']);

        if ($assigned->isNotEmpty()) {
            return $assigned->values();
        }

        // Fallback to primary warehouse
        if ($user->warehouse_id) {
            return Warehouse::where('account_id', $user->account_id)->where('active', 1)->visible()->whereKey($user->warehouse_id)->orderBy('name')->get(['id', 'name', 'completed_at']);
        }

        return collect();
    }

    private function canViewTimelyMaterialUsage(?User $user): bool
    {
        return (bool) $user && $user->hasRole([
            'Super Admin',
            'Admin',
            'Project Manager',
            'Project Managers',
            'Procurement Manager',
            'Procurement Manger',
            ...User::SITE_ENGINEER_ROLES,
        ]);
    }

    private function canSeeTimelyUsageCosts(?User $user): bool
    {
        return (bool) $user && $user->hasRole([
            'Super Admin',
            'Admin',
            'Project Manager',
            'Project Managers',
            'Procurement Manager',
            'Procurement Manger',
        ]);
    }

    private function isSiteEngineer(?User $user): bool
    {
        return (bool) $user && $user->hasRole(User::SITE_ENGINEER_ROLES);
    }

    private function canSelectAllProjects(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        return $user->hasRole(['Project Manager', 'Project Managers']) && (bool) $user->view_all;
    }

    private function reportWarehousesForUser(User $user, bool $canViewAllProjects)
    {
        if ($canViewAllProjects) {
            return Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status', 'completed_at']);
        }

        $assigned = $user->warehouses()->where('active', 1)->visible()->orderBy('name')->get(['warehouses.id as id', 'warehouses.name as name', 'warehouses.status as status', 'warehouses.completed_at as completed_at']);

        if ($assigned->isNotEmpty()) {
            return $assigned->values();
        }

        if ($user->warehouse_id) {
            return Warehouse::ofAccount()->active()->visible()->whereKey($user->warehouse_id)->orderBy('name')->get(['id', 'name', 'status', 'completed_at']);
        }

        return collect();
    }

    private function loadRecord(string $type, int $id)
    {
        $relations = [
            'items.variations',
            'items.item:id,code,name,track_quantity,track_weight,price',
            'items.unit:id,code,name',
            'user:id,name,username',
        ];

        return match ($type) {
            'checkout'   => Checkout::with(array_merge($relations, ['contact', 'warehouse']))->find($id),
            'checkin'    => Checkin::with(array_merge($relations, ['contact', 'warehouse']))->find($id),
            'transfer'   => Transfer::with(array_merge($relations, ['fromWarehouse', 'toWarehouse']))->find($id),
            'adjustment' => Adjustment::with(array_merge($relations, ['warehouse']))->find($id),
        };
    }

    // PENDING - IDLE
    public function getWarehouseStockSummary()
    {
        $warehouses = Warehouse::visible()->with(['items' => function ($query) {
            $query->with(['checkins', 'checkouts', 'adjustments']);
        }])->get();

        $warehouseStockSummary = $warehouses->map(function ($warehouse) {
            $totalStockValue = 0;
            $totalStockQuantity = 0;

            foreach ($warehouse->items as $item) {
                $checkinQuantity = $item->checkins->sum('quantity');
                $checkoutQuantity = $item->checkouts->sum('quantity');
                $adjustmentQuantity = $item->adjustments->sum('quantity');

                $availableStock = $checkinQuantity - $checkoutQuantity - $adjustmentQuantity;
                $totalStockQuantity += $availableStock;
                $totalStockValue += $availableStock * $item->price; // Assuming price is a field in the Item model
            }

            return [
                'warehouse_name' => $warehouse->name,
                'total_stock_quantity' => $totalStockQuantity,
                'total_stock_value' => $totalStockValue
            ];
        });

        return $warehouseStockSummary;
    }

    private function canViewMaterialReallocationReports(?User $user): bool
    {
        return (bool) $user && $user->hasRole([
            'Super Admin',
            'Admin',
            'Project Manager',
            'Project Managers',
            'Procurement Manager',
            'Procurement Manger',
            'Store Manager',
        ]);
    }

    private function applyReportSort($query, Request $request, array $sortable, string $defaultColumn = 'id', string $defaultDirection = 'desc')
    {
        $sort = $request->query('sort');
        $direction = $request->query('sort_direction') === 'asc' ? 'asc' : 'desc';

        if ($sort && in_array($sort, $sortable, true)) {
            return $query->orderBy($sort, $direction);
        }

        return $query->orderBy($defaultColumn, $defaultDirection);
    }

    public function materialReallocationSummary(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canViewMaterialReallocationReports($user), 403, 'You are not allowed to view this report.');

        $filters = $request->all('search', 'status', 'source_warehouse_id', 'user_id', 'start_date', 'end_date');

        $query = MaterialReallocation::with(['sourceWarehouse:id,name', 'user:id,name', 'items.item:id,price'])
            ->filter($filters)
            ->withCount('items');
        $query = $this->applyReportSort($query, $request, ['reference', 'status', 'date_requested', 'id']);

        if ($request->query('output') === 'excel') {
            $rows = $query->limit(3000)->get();

            $exportRows = $rows->map(fn ($m) => [
                'Reference' => $m->reference,
                'Source Warehouse' => optional($m->sourceWarehouse)->name,
                'Requested By' => optional($m->user)->name,
                'Status' => $m->status,
                'Date Requested' => optional($m->date_requested)->format('Y-m-d'),
                'Total Items' => $m->items->count(),
                'Total Requested Qty' => (float) $m->items->sum('requested_quantity'),
                'Total Allocated Qty' => (float) $m->items->sum('allocated_quantity'),
                'Total Received Qty' => (float) $m->items->sum('received_quantity'),
                'Total Value' => round($m->items->sum(fn ($i) => (float) $i->received_quantity * (float) optional($i->item)->price), 2),
            ]);

            return (new FastExcel($exportRows))->download('material-reallocation-summary-' . now()->format('Ymd-His') . '.xlsx');
        }

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $rows = $query->limit(3000)->get();

            $data = [
                'title' => 'Material Reallocation Summary Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns' => ['Reference', 'Source Warehouse', 'Requested By', 'Status', 'Date Requested', 'Items', 'Requested Qty', 'Allocated Qty', 'Received Qty', 'Value'],
                'rows' => $rows->map(fn ($m) => [
                    $m->reference,
                    optional($m->sourceWarehouse)->name,
                    optional($m->user)->name,
                    $m->status,
                    optional($m->date_requested)->format('Y-m-d'),
                    $m->items->count(),
                    number_format((float) $m->items->sum('requested_quantity'), 2),
                    number_format((float) $m->items->sum('allocated_quantity'), 2),
                    number_format((float) $m->items->sum('received_quantity'), 2),
                    number_format($m->items->sum(fn ($i) => (float) $i->received_quantity * (float) optional($i->item)->price), 2),
                ])->values()->all(),
                'meta' => array_filter([
                    'Status' => $filters['status'] ?? null,
                    'From' => $filters['start_date'] ?? null,
                    'To' => $filters['end_date'] ?? null,
                    'Search' => $filters['search'] ?? null,
                ], fn ($v) => filled($v)),
                'autoPrint' => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)->download('material-reallocation-summary-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        return Inertia::render('Report/MaterialReallocationSummary', [
            'filters' => $filters,
            'statuses' => MaterialReallocation::statuses(),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name']),
            'users' => User::ofAccount()->orderBy('name')->get(['id', 'name']),
            // See materialMovement()'s comment above the same wrap — a raw
            // paginator serialises flat (no .meta/.links), which crashes
            // Pagination.vue as soon as a row renders.
            'materialReallocations' => \Illuminate\Http\Resources\Json\JsonResource::collection(
                $query->paginate()->withQueryString()->through(fn ($m) => [
                    'id' => $m->id,
                    'reference' => $m->reference,
                    'status' => $m->status,
                    'date_requested' => optional($m->date_requested)->format('Y-m-d'),
                    'source_warehouse' => $m->sourceWarehouse ? ['id' => $m->sourceWarehouse->id, 'name' => $m->sourceWarehouse->name] : null,
                    'user' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name] : null,
                    'items_count' => $m->items_count,
                    'total_requested_qty' => (float) $m->items->sum('requested_quantity'),
                    'total_allocated_qty' => (float) $m->items->sum('allocated_quantity'),
                    'total_received_qty' => (float) $m->items->sum('received_quantity'),
                    'total_value' => round($m->items->sum(fn ($i) => (float) $i->received_quantity * (float) optional($i->item)->price), 2),
                ])
            ),
        ]);
    }

    public function materialMovement(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canViewMaterialReallocationReports($user), 403, 'You are not allowed to view this report.');

        $filters = $request->all('item_id', 'source_warehouse_id', 'destination_warehouse_id', 'start_date', 'end_date', 'only_completed');

        $query = MaterialReallocationItem::query()
            ->with([
                'item:id,name,code',
                'unit:id,name,code',
                'destinationWarehouse:id,name',
                'materialReallocation:id,reference,source_warehouse_id,status,date_requested',
                'materialReallocation.sourceWarehouse:id,name',
            ])
            ->when($filters['item_id'] ?? null, fn ($q, $v) => $q->where('item_id', $v))
            ->when($filters['destination_warehouse_id'] ?? null, fn ($q, $v) => $q->where('destination_warehouse_id', $v))
            ->when($filters['source_warehouse_id'] ?? null, fn ($q, $v) => $q->whereHas('materialReallocation', fn ($mq) => $mq->where('source_warehouse_id', $v)))
            ->when(($filters['start_date'] ?? null) || ($filters['end_date'] ?? null), fn ($q) => $q->whereHas('materialReallocation', function ($mq) use ($filters) {
                $mq->when($filters['start_date'] ?? null, fn ($qq, $v) => $qq->whereDate('date_requested', '>=', $v))
                    ->when($filters['end_date'] ?? null, fn ($qq, $v) => $qq->whereDate('date_requested', '<=', $v));
            }))
            ->when($request->boolean('only_completed'), fn ($q) => $q->whereHas('materialReallocation', fn ($mq) => $mq->where('status', MaterialReallocation::STATUS_COMPLETED)));
        $query = $this->applyReportSort($query, $request, ['requested_quantity', 'allocated_quantity', 'received_quantity', 'id']);

        if ($request->query('output') === 'excel') {
            $rows = $query->limit(3000)->get();

            $exportRows = $rows->map(fn ($i) => [
                'Reference' => optional($i->materialReallocation)->reference,
                'Item' => optional($i->item)->name,
                'Source Warehouse' => optional(optional($i->materialReallocation)->sourceWarehouse)->name,
                'Destination Warehouse' => optional($i->destinationWarehouse)->name,
                'Requested Qty' => (float) $i->requested_quantity,
                'Allocated Qty' => (float) $i->allocated_quantity,
                'Received Qty' => (float) $i->received_quantity,
                'Unit' => optional($i->unit)->code ?: optional($i->unit)->name,
                'Status' => optional($i->materialReallocation)->status,
            ]);

            return (new FastExcel($exportRows))->download('material-movement-' . now()->format('Ymd-His') . '.xlsx');
        }

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $rows = $query->limit(3000)->get();

            $data = [
                'title' => 'Material Movement Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns' => ['Reference', 'Item', 'Source Warehouse', 'Destination Warehouse', 'Requested Qty', 'Allocated Qty', 'Received Qty', 'Unit', 'Status'],
                'rows' => $rows->map(fn ($i) => [
                    optional($i->materialReallocation)->reference,
                    optional($i->item)->name,
                    optional(optional($i->materialReallocation)->sourceWarehouse)->name,
                    optional($i->destinationWarehouse)->name,
                    number_format((float) $i->requested_quantity, 2),
                    $i->allocated_quantity !== null ? number_format((float) $i->allocated_quantity, 2) : '-',
                    $i->received_quantity !== null ? number_format((float) $i->received_quantity, 2) : '-',
                    optional($i->unit)->code ?: optional($i->unit)->name,
                    optional($i->materialReallocation)->status,
                ])->values()->all(),
                'meta' => array_filter([
                    'From' => $filters['start_date'] ?? null,
                    'To' => $filters['end_date'] ?? null,
                ], fn ($v) => filled($v)),
                'autoPrint' => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)
                    ->setPaper('a4', 'landscape')
                    ->download('material-movement-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        // Only run the query once the user has actually chosen a filter —
        // previously this listed every Material Reallocation line item in
        // the account on first load, before any filter was ever applied.
        $hasFilters = collect($filters)->except(['only_completed'])->filter(fn ($v) => filled($v))->isNotEmpty()
            || $request->boolean('only_completed');

        // A raw LengthAwarePaginator (even after ->through()) serialises to
        // a FLAT array (current_page/total/... at the top level) — it has
        // no `meta`/`links` keys at all. Shared/Pagination.vue expects the
        // nested `{data, links: {prev,next}, meta: {total, from, to,
        // links: [...]}}` shape, which only exists once the paginator is
        // wrapped in a JsonResource collection (confirmed by inspecting
        // ContactController, which wraps in ContactCollection for the same
        // reason). Passing the raw paginator here — as this method did
        // before — left `movements.meta` undefined and crashed
        // Pagination.vue as soon as any row was rendered.
        $movements = $hasFilters
            ? \Illuminate\Http\Resources\Json\JsonResource::collection(
                $query->paginate()->withQueryString()->through(fn ($i) => [
                    'id' => $i->id,
                    'reference' => optional($i->materialReallocation)->reference,
                    'material_reallocation_id' => $i->material_reallocation_id,
                    'item' => $i->item ? ['id' => $i->item->id, 'name' => $i->item->name, 'code' => $i->item->code] : null,
                    'unit' => $i->unit ? ['id' => $i->unit->id, 'name' => $i->unit->name, 'code' => $i->unit->code] : null,
                    'source_warehouse' => optional($i->materialReallocation)->sourceWarehouse ? ['id' => $i->materialReallocation->sourceWarehouse->id, 'name' => $i->materialReallocation->sourceWarehouse->name] : null,
                    'destination_warehouse' => $i->destinationWarehouse ? ['id' => $i->destinationWarehouse->id, 'name' => $i->destinationWarehouse->name] : null,
                    'requested_quantity' => (float) $i->requested_quantity,
                    'allocated_quantity' => $i->allocated_quantity !== null ? (float) $i->allocated_quantity : null,
                    'received_quantity' => $i->received_quantity !== null ? (float) $i->received_quantity : null,
                    'status' => optional($i->materialReallocation)->status,
                ])
            )
            : null;

        return Inertia::render('Report/MaterialMovement', [
            'filters' => $filters,
            'hasFilters' => $hasFilters,
            'items' => Item::ofAccount()->orderBy('name')->get(['id', 'name', 'code']),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name']),
            'movements' => $movements,
        ]);
    }

    public function driverDispatch(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canViewMaterialReallocationReports($user), 403, 'You are not allowed to view this report.');

        $filters = $request->all('driver_contact_id', 'start_date', 'end_date');

        $query = MaterialReallocation::query()
            ->whereNotNull('dispatch_date_time')
            ->with(['sourceWarehouse:id,name', 'driverContact:id,name,phone', 'dispatchedBy:id,name', 'items.destinationWarehouse:id,name'])
            ->when($filters['driver_contact_id'] ?? null, fn ($q, $v) => $q->where('driver_contact_id', $v))
            ->when($filters['start_date'] ?? null, fn ($q, $v) => $q->whereDate('dispatch_date_time', '>=', $v))
            ->when($filters['end_date'] ?? null, fn ($q, $v) => $q->whereDate('dispatch_date_time', '<=', $v));
        $query = $this->applyReportSort($query, $request, ['dispatch_date_time', 'vehicle'], 'dispatch_date_time');

        $destinationsFor = fn ($m) => $m->items->pluck('destinationWarehouse.name')->filter()->unique()->values()->implode(', ');

        if ($request->query('output') === 'excel') {
            $rows = $query->limit(3000)->get();

            $exportRows = $rows->map(fn ($m) => [
                'Reference' => $m->reference,
                'Driver' => optional($m->driverContact)->name,
                'Driver Phone' => optional($m->driverContact)->phone,
                'Vehicle' => $m->vehicle,
                'Dispatch Date/Time' => optional($m->dispatch_date_time)->format('Y-m-d H:i'),
                'Dispatched By' => optional($m->dispatchedBy)->name,
                'Source Warehouse' => optional($m->sourceWarehouse)->name,
                'Destination(s)' => $destinationsFor($m),
                'Status' => $m->status,
            ]);

            return (new FastExcel($exportRows))->download('driver-dispatch-' . now()->format('Ymd-His') . '.xlsx');
        }

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $rows = $query->limit(3000)->get();

            $data = [
                'title' => 'Driver Dispatch Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns' => ['Reference', 'Driver', 'Phone', 'Vehicle', 'Dispatch Date/Time', 'Dispatched By', 'Source Warehouse', 'Destination(s)', 'Status'],
                'rows' => $rows->map(fn ($m) => [
                    $m->reference,
                    optional($m->driverContact)->name,
                    optional($m->driverContact)->phone,
                    $m->vehicle,
                    optional($m->dispatch_date_time)->format('Y-m-d H:i'),
                    optional($m->dispatchedBy)->name,
                    optional($m->sourceWarehouse)->name,
                    $destinationsFor($m),
                    $m->status,
                ])->values()->all(),
                'meta' => array_filter([
                    'From' => $filters['start_date'] ?? null,
                    'To' => $filters['end_date'] ?? null,
                ], fn ($v) => filled($v)),
                'autoPrint' => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)->download('driver-dispatch-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        return Inertia::render('Report/DriverDispatch', [
            'filters' => $filters,
            'drivers' => Contact::where('category', 'Drivers')->orderBy('name')->get(['id', 'name', 'phone']),
            'dispatches' => \Illuminate\Http\Resources\Json\JsonResource::collection(
                $query->paginate()->withQueryString()->through(fn ($m) => [
                    'id' => $m->id,
                    'reference' => $m->reference,
                    'driver' => $m->driverContact ? ['id' => $m->driverContact->id, 'name' => $m->driverContact->name, 'phone' => $m->driverContact->phone] : null,
                    'vehicle' => $m->vehicle,
                    'dispatch_date_time' => optional($m->dispatch_date_time)->format('Y-m-d H:i'),
                    'dispatched_by' => $m->dispatchedBy ? ['id' => $m->dispatchedBy->id, 'name' => $m->dispatchedBy->name] : null,
                    'source_warehouse' => $m->sourceWarehouse ? ['id' => $m->sourceWarehouse->id, 'name' => $m->sourceWarehouse->name] : null,
                    'destinations' => $destinationsFor($m),
                    'status' => $m->status,
                ])
            ),
        ]);
    }

    public function warehouseReceiving(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canViewMaterialReallocationReports($user), 403, 'You are not allowed to view this report.');

        $filters = $request->all('destination_warehouse_id', 'received_by', 'start_date', 'end_date');

        $query = MaterialReallocationItem::query()
            ->whereNotNull('received_quantity')
            ->with(['item:id,name,code', 'destinationWarehouse:id,name', 'materialReallocation:id,reference,received_by_user_id,received_date_time', 'materialReallocation.receivedBy:id,name'])
            ->whereHas('materialReallocation', function ($q) use ($filters) {
                $q->whereNotNull('received_date_time')
                    ->when($filters['received_by'] ?? null, fn ($qq, $v) => $qq->where('received_by_user_id', $v))
                    ->when($filters['start_date'] ?? null, fn ($qq, $v) => $qq->whereDate('received_date_time', '>=', $v))
                    ->when($filters['end_date'] ?? null, fn ($qq, $v) => $qq->whereDate('received_date_time', '<=', $v));
            })
            ->when($filters['destination_warehouse_id'] ?? null, fn ($q, $v) => $q->where('destination_warehouse_id', $v));
        $query = $this->applyReportSort($query, $request, ['allocated_quantity', 'received_quantity', 'id']);

        if ($request->query('output') === 'excel') {
            $rows = $query->limit(3000)->get();

            $exportRows = $rows->map(fn ($i) => [
                'Reference' => optional($i->materialReallocation)->reference,
                'Item' => optional($i->item)->name,
                'Destination Warehouse' => optional($i->destinationWarehouse)->name,
                'Allocated Qty' => (float) $i->allocated_quantity,
                'Received Qty' => (float) $i->received_quantity,
                'Received By' => optional(optional($i->materialReallocation)->receivedBy)->name,
                'Received Date/Time' => optional(optional($i->materialReallocation)->received_date_time)->format('Y-m-d H:i'),
            ]);

            return (new FastExcel($exportRows))->download('warehouse-receiving-' . now()->format('Ymd-His') . '.xlsx');
        }

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $rows = $query->limit(3000)->get();

            $data = [
                'title' => 'Warehouse Receiving Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns' => ['Reference', 'Item', 'Destination Warehouse', 'Allocated Qty', 'Received Qty', 'Received By', 'Received Date/Time'],
                'rows' => $rows->map(fn ($i) => [
                    optional($i->materialReallocation)->reference,
                    optional($i->item)->name,
                    optional($i->destinationWarehouse)->name,
                    number_format((float) $i->allocated_quantity, 2),
                    number_format((float) $i->received_quantity, 2),
                    optional(optional($i->materialReallocation)->receivedBy)->name,
                    optional(optional($i->materialReallocation)->received_date_time)->format('Y-m-d H:i'),
                ])->values()->all(),
                'meta' => array_filter([
                    'From' => $filters['start_date'] ?? null,
                    'To' => $filters['end_date'] ?? null,
                ], fn ($v) => filled($v)),
                'autoPrint' => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)->download('warehouse-receiving-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        return Inertia::render('Report/WarehouseReceiving', [
            'filters' => $filters,
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name']),
            'users' => User::ofAccount()->orderBy('name')->get(['id', 'name']),
            'receipts' => \Illuminate\Http\Resources\Json\JsonResource::collection(
                $query->paginate()->withQueryString()->through(fn ($i) => [
                    'id' => $i->id,
                    'reference' => optional($i->materialReallocation)->reference,
                    'item' => $i->item ? ['id' => $i->item->id, 'name' => $i->item->name, 'code' => $i->item->code] : null,
                    'destination_warehouse' => $i->destinationWarehouse ? ['id' => $i->destinationWarehouse->id, 'name' => $i->destinationWarehouse->name] : null,
                    'allocated_quantity' => (float) $i->allocated_quantity,
                    'received_quantity' => (float) $i->received_quantity,
                    'received_by' => optional($i->materialReallocation)->receivedBy ? ['id' => $i->materialReallocation->receivedBy->id, 'name' => $i->materialReallocation->receivedBy->name] : null,
                    'received_date_time' => optional(optional($i->materialReallocation)->received_date_time)->format('Y-m-d H:i'),
                ])
            ),
        ]);
    }

    public function discrepancy(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canViewMaterialReallocationReports($user), 403, 'You are not allowed to view this report.');

        $filters = $request->all('destination_warehouse_id', 'start_date', 'end_date');

        $query = MaterialReallocationItem::query()
            ->whereNotNull('received_quantity')
            ->whereNotNull('allocated_quantity')
            ->whereColumn('received_quantity', '!=', 'allocated_quantity')
            ->with(['item:id,name,code', 'destinationWarehouse:id,name', 'materialReallocation:id,reference,received_date_time'])
            ->when($filters['destination_warehouse_id'] ?? null, fn ($q, $v) => $q->where('destination_warehouse_id', $v))
            ->when($request->boolean('only_under_received'), fn ($q) => $q->whereColumn('received_quantity', '<', 'allocated_quantity'))
            ->when($request->boolean('only_over_received'), fn ($q) => $q->whereColumn('received_quantity', '>', 'allocated_quantity'))
            ->when(($filters['start_date'] ?? null) || ($filters['end_date'] ?? null), fn ($q) => $q->whereHas('materialReallocation', function ($mq) use ($filters) {
                $mq->when($filters['start_date'] ?? null, fn ($qq, $v) => $qq->whereDate('received_date_time', '>=', $v))
                    ->when($filters['end_date'] ?? null, fn ($qq, $v) => $qq->whereDate('received_date_time', '<=', $v));
            }));
        $query = $this->applyReportSort($query, $request, ['allocated_quantity', 'received_quantity', 'id']);

        $deltaRow = function ($i) {
            $allocated = (float) $i->allocated_quantity;
            $received = (float) $i->received_quantity;
            $delta = $received - $allocated;
            $percent = $allocated != 0.0 ? ($delta / $allocated) * 100 : null;

            return [$i, $delta, $percent];
        };

        if ($request->query('output') === 'excel') {
            $rows = $query->limit(3000)->get();

            $exportRows = $rows->map(function ($i) use ($deltaRow) {
                [$item, $delta, $percent] = $deltaRow($i);

                return [
                    'Reference' => optional($item->materialReallocation)->reference,
                    'Item' => optional($item->item)->name,
                    'Destination Warehouse' => optional($item->destinationWarehouse)->name,
                    'Allocated Qty' => (float) $item->allocated_quantity,
                    'Received Qty' => (float) $item->received_quantity,
                    'Delta' => $delta,
                    'Delta %' => $percent !== null ? round($percent, 2) : null,
                    'Remarks' => $item->remarks,
                ];
            });

            return (new FastExcel($exportRows))->download('discrepancy-' . now()->format('Ymd-His') . '.xlsx');
        }

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $rows = $query->limit(3000)->get();

            $data = [
                'title' => 'Discrepancy Report',
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns' => ['Reference', 'Item', 'Destination Warehouse', 'Allocated Qty', 'Received Qty', 'Delta', 'Delta %', 'Remarks'],
                'rows' => $rows->map(function ($i) use ($deltaRow) {
                    [$item, $delta, $percent] = $deltaRow($i);

                    return [
                        optional($item->materialReallocation)->reference,
                        optional($item->item)->name,
                        optional($item->destinationWarehouse)->name,
                        number_format((float) $item->allocated_quantity, 2),
                        number_format((float) $item->received_quantity, 2),
                        number_format($delta, 2),
                        $percent !== null ? number_format($percent, 1) . '%' : '-',
                        $item->remarks,
                    ];
                })->values()->all(),
                'meta' => array_filter([
                    'From' => $filters['start_date'] ?? null,
                    'To' => $filters['end_date'] ?? null,
                ], fn ($v) => filled($v)),
                'autoPrint' => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $data)->download('discrepancy-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        return Inertia::render('Report/Discrepancy', [
            'filters' => $filters,
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name']),
            'discrepancies' => \Illuminate\Http\Resources\Json\JsonResource::collection(
                $query->paginate()->withQueryString()->through(function ($i) use ($deltaRow) {
                    [$item, $delta, $percent] = $deltaRow($i);

                    return [
                        'id' => $item->id,
                        'reference' => optional($item->materialReallocation)->reference,
                        'item' => $item->item ? ['id' => $item->item->id, 'name' => $item->item->name, 'code' => $item->item->code] : null,
                        'destination_warehouse' => $item->destinationWarehouse ? ['id' => $item->destinationWarehouse->id, 'name' => $item->destinationWarehouse->name] : null,
                        'allocated_quantity' => (float) $item->allocated_quantity,
                        'received_quantity' => (float) $item->received_quantity,
                        'delta' => $delta,
                        'delta_percent' => $percent !== null ? round($percent, 2) : null,
                        'remarks' => $item->remarks,
                    ];
                })
            ),
        ]);
    }
}
