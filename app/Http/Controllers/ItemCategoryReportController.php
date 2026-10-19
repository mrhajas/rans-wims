<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Item;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\DownloadPrintLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Rap2hpoutre\FastExcel\FastExcel;

class ItemCategoryReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canViewReport($user), 403, 'You are not allowed to view this report.');

        $canViewAllWarehouses = $this->canSelectAllWarehouses($user);
        $warehouses = $this->reportWarehousesForUser($user, $canViewAllWarehouses);

        $filters = [
            'report_type' => $request->input('report_type', 'items'), // 'items' or 'categories'
            'items' => $request->input('items') ? explode(',', $request->input('items')) : [],
            'categories' => $request->input('categories') ? explode(',', $request->input('categories')) : [],
            'warehouse_ids' => $request->input('warehouse_ids') ? explode(',', $request->input('warehouse_ids')) : [],
            'search' => $request->input('search', ''),
        ];

        // Validate warehouse access
        if (!empty($filters['warehouse_ids'])) {
            $allowedWarehouseIds = $warehouses->pluck('id')->toArray();
            foreach ($filters['warehouse_ids'] as $warehouseId) {
                if (!in_array($warehouseId, $allowedWarehouseIds)) {
                    abort(403, 'You are not assigned to the selected warehouse.');
                }
            }
        }

        // Build rows based on report type
        $rows = $this->buildRows($filters, $warehouses, $user);

        // Handle export formats
        if ($request->query('output') === 'excel') {
            $this->logPrintDownload($request, 'download', 'Item/Category Report');
            return (new FastExcel(collect($rows['data'])))->download('item-category-report-' . now()->format('Ymd-His') . '.xlsx');
        }

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $this->logPrintDownload($request, $request->query('output') === 'pdf' ? 'download' : 'print', 'Item/Category Report');

            $mappedRows = collect($rows['data'])->map(function ($r) {
                return [
                    $r['No'] ?? '',
                    $r['Item No'] ?? '',
                    $r['Item Name'] ?? '',
                    number_format((float)($r['Quantity'] ?? 0), 2),
                    $r['Unit'] ?? '-',
                    number_format((float)($r['Price'] ?? 0), 2),
                    number_format((float)($r['Total'] ?? 0), 2),
                    $r['Warehouse'] ?? '',
                ];
            })->values()->all();

            $viewData = [
                'title' => $rows['title'],
                'companyName' => optional($request->user()->account ?? null)->name,
                'columns' => $rows['columns'],
                'rows' => $mappedRows,
                'totalValue' => $rows['total_value'] ?? 0,
                'generatedBy' => optional($request->user())->name,
                'generatedAt' => now(),
                'autoPrint' => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.item-category-pdf', $viewData)->download('item-category-report-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.item-category-pdf', $viewData);
        }

        // Get available items and categories for dropdowns
        $availableItems = $this->getAvailableItems($warehouses, $user);
        $availableCategories = $this->getAvailableCategories($warehouses, $user);

        return Inertia::render('Reports/ItemCategoryReport', [
            'filters' => $filters,
            'report' => $rows,
            'warehouses' => $warehouses,
            'availableItems' => $availableItems,
            'availableCategories' => $availableCategories,
        ]);
    }

    public function getItemDetails(Request $request, Item $item)
    {
        $user = $request->user();
        abort_unless($this->canViewReport($user), 403, 'Not authorized');

        // Reload item with all necessary fields
        $item->load('unit');

        // Get warehouse-wise stock
        $stocks = Stock::query()
            ->where('item_id', $item->id)
            ->where('quantity', '>', 0)
            ->with('warehouse:id,name')
            ->get();

        $totalQuantity = (float) $stocks->sum('quantity');

        $warehouseDetails = $stocks->map(function ($stock) {
            return [
                'warehouse' => $stock->warehouse->name,
                'quantity' => (float) $stock->quantity,
            ];
        })->values();

        // Use code as barcode (the actual scannable barcode value)
        $barcode = $item->code ?? 'Not Available';

        return response()->json([
            'item_id' => $item->id,
            'item_name' => $item->name,
            'item_code' => $item->code,
            'barcode' => $barcode,
            'unit' => $item->unit?->name ?? $item->unit?->code ?? '-',
            'price' => (float)($item->price ?? 0),
            'total_quantity' => $totalQuantity,
            'warehouse_details' => $warehouseDetails,
        ]);
    }

    private function canViewReport(?User $user): bool
    {
        return (bool) $user && $user->hasRole([
            'Super Admin',
            'Admin',
            'Project Manager',
            'Project Managers',
            'Procurement Manager',
            'Procurement Manger',
            'Storekeeper',
            'Storekeepers',
            'Store Manager',
        ]);
    }

    private function canSelectAllWarehouses(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        // Project Manager with view_all permission
        if ($user->hasRole(['Project Manager', 'Project Managers']) && $user->view_all) {
            return true;
        }

        // Procurement Manager can view all
        if ($user->hasRole(['Procurement Manager', 'Procurement Manger'])) {
            return true;
        }

        return false;
    }

    private function reportWarehousesForUser(User $user, bool $canViewAllWarehouses)
    {
        if ($canViewAllWarehouses) {
            return Warehouse::where('account_id', $user->account_id)->where('active', 1)->visible()->orderBy('name')->get(['id', 'name', 'completed_at']);
        }

        // Get assigned warehouses - use fully qualified column names to avoid ambiguity
        $assigned = $user->warehouses()->where('warehouses.active', 1)->visible()->orderBy('warehouses.name')->get(['warehouses.id', 'warehouses.name', 'warehouses.completed_at']);

        if ($assigned->isNotEmpty()) {
            return $assigned->values();
        }

        // Fallback to user's primary warehouse
        if ($user->warehouse_id) {
            return Warehouse::where('account_id', $user->account_id)->where('active', 1)->visible()->whereKey($user->warehouse_id)->orderBy('name')->get(['id', 'name', 'completed_at']);
        }

        return collect();
    }

    private function getAvailableItems($warehouses, User $user)
    {
        $warehouseIds = $warehouses->pluck('id')->toArray();

        return Item::ofAccount()
            ->whereHas('allStock', function ($q) use ($warehouseIds) {
                $q->whereIn('warehouse_id', $warehouseIds)->where('quantity', '>', 0);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'unit_id'])
            ->load('unit:id,code,name');
    }

    private function getAvailableCategories($warehouses, User $user)
    {
        $warehouseIds = $warehouses->pluck('id')->toArray();

        return Category::ofAccount()
            ->whereHas('items.allStock', function ($q) use ($warehouseIds) {
                $q->whereIn('warehouse_id', $warehouseIds)->where('quantity', '>', 0);
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function buildRows(array $filters, $warehouses, User $user): array
    {
        // Return empty if no warehouses selected
        if (empty($filters['warehouse_ids'])) {
            return [
                'title' => 'Item / Category Report',
                'columns' => ['No', 'Item Name', 'Quantity', 'Unit', 'Price', 'Total', 'Warehouse'],
                'data' => [],
                'total_value' => 0,
            ];
        }

        // Use only selected warehouses
        $warehouseIds = $filters['warehouse_ids'];

        // Return empty if no items or categories are selected
        if (empty($filters['items']) && empty($filters['categories'])) {
            return [
                'title' => 'Item / Category Report',
                'columns' => ['No', 'Item Name', 'Quantity', 'Unit', 'Price', 'Total', 'Warehouse'],
                'data' => [],
                'total_value' => 0,
            ];
        }

        // Build query based on report type
        if ($filters['report_type'] === 'categories' && !empty($filters['categories'])) {
            $rows = $this->buildCategoryRows($filters, $warehouseIds);
        } else {
            $rows = $this->buildItemRows($filters, $warehouseIds);
        }

        // Apply search filter
        if (!empty($filters['search'])) {
            $search = strtolower($filters['search']);
            $rows = array_filter($rows, function ($row) use ($search) {
                return str_contains(strtolower($row['Item Name']), $search) ||
                       str_contains(strtolower($row['Warehouse']), $search);
            });
        }

        // Re-index array and add row numbers
        $rows = array_values($rows);
        $totalValue = 0.0;
        foreach ($rows as $index => &$row) {
            $row['No'] = $index + 1;
            $totalValue += $row['Total'];
        }

        return [
            'title' => 'Item / Category Report',
            'columns' => ['No', 'Item No', 'Item Name', 'Quantity', 'Unit', 'Price', 'Total', 'Warehouse'],
            'data' => $rows,
            'total_value' => $totalValue,
        ];
    }

    private function buildItemRows(array $filters, array $warehouseIds): array
    {
        $query = Stock::query()
            ->with(['item:id,name,code,unit_id,price', 'item.unit:id,code,name', 'warehouse:id,name'])
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity', '>', 0);

        if (!empty($filters['items'])) {
            $query->whereIn('item_id', $filters['items']);
        }

        $stocks = $query->get();

        $rows = [];
        foreach ($stocks as $stock) {
            $quantity = (float) $stock->quantity;
            $price = (float) ($stock->item->price ?? 0);

            $rows[] = [
                'item_id' => $stock->item->id,
                'Item No' => $stock->item->code ?? 'N/A',
                'Item Name' => $stock->item->name ?? 'N/A',
                'Quantity' => $quantity,
                'Unit' => $stock->item->unit?->name ?? $stock->item->unit?->code ?? '-',
                'Price' => $price,
                'Total' => $price * $quantity,
                'Warehouse' => $stock->warehouse->name,
            ];
        }

        usort($rows, function ($a, $b) {
            $nameCompare = strcmp($a['Item Name'], $b['Item Name']);
            if ($nameCompare !== 0) {
                return $nameCompare;
            }
            return strcmp($a['Warehouse'], $b['Warehouse']);
        });

        return $rows;
    }

    private function buildCategoryRows(array $filters, array $warehouseIds): array
    {
        $rows = [];

        $categories = Category::ofAccount()->whereIn('id', $filters['categories'])->get();

        foreach ($categories as $category) {
            $items = $category->items()
                ->ofAccount()
                ->whereHas('allStock', function ($q) use ($warehouseIds) {
                    $q->whereIn('warehouse_id', $warehouseIds)->where('quantity', '>', 0);
                })
                ->with(['allStock' => function ($q) use ($warehouseIds) {
                    $q->whereIn('warehouse_id', $warehouseIds)->where('quantity', '>', 0)->with('warehouse:id,name');
                }, 'unit:id,code,name'])
                ->get();

            foreach ($items as $item) {
                foreach ($item->allStock as $stock) {
                    $quantity = (float) $stock->quantity;
                    $price = (float) ($item->price ?? 0);

                    $rows[] = [
                        'item_id' => $item->id,
                        'Item No' => $item->code ?? 'N/A',
                        'Item Name' => $item->name,
                        'Quantity' => $quantity,
                        'Unit' => $item->unit?->name ?? $item->unit?->code ?? '-',
                        'Price' => $price,
                        'Total' => $price * $quantity,
                        'Warehouse' => $stock->warehouse?->name ?? 'N/A',
                    ];
                }
            }
        }

        usort($rows, function ($a, $b) {
            $nameCompare = strcmp($a['Item Name'], $b['Item Name']);
            if ($nameCompare !== 0) {
                return $nameCompare;
            }
            return strcmp($a['Warehouse'], $b['Warehouse']);
        });

        return $rows;
    }

    private function logPrintDownload(Request $request, string $actionType, string $module): void
    {
        try {
            DownloadPrintLog::create([
                'account_id' => getAccountId(1),
                'user_id' => $request->user()?->id,
                'user_name' => $request->user()?->name,
                'role' => optional($request->user()?->roles()->first())->name,
                'action_type' => $actionType,
                'module' => $module,
                'route_name' => 'item-category-report.index',
                'method' => $request->method(),
                'url' => $request->url(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'performed_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Silently fail logging to not interrupt report generation
        }
    }
}
