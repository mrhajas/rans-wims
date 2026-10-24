<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Inertia\Inertia;
use App\Models\Item;
use App\Models\Unit;
use App\Models\User;
use App\Models\Contact;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use App\Models\MaterialReallocation;
use App\Models\MaterialReallocationItem;
use Illuminate\Database\QueryException;
use App\Events\MaterialReallocationEvent;
use App\Notifications\AppNotification;
use App\Services\MainWarehouseEmailService;
use App\Services\WimsConnect\CommunicationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Rap2hpoutre\FastExcel\FastExcel;
use App\Http\Resources\MaterialReallocationResource;
use App\Http\Resources\MaterialReallocationCollection;
use App\Http\Requests\MaterialReallocationStoreRequest;
use App\Http\Requests\MaterialReallocationUpdateRequest;
use App\Http\Requests\MaterialReallocationClearRequest;
use App\Http\Requests\MaterialReallocationAllocateRequest;
use App\Http\Requests\MaterialReallocationDispatchRequest;
use App\Http\Requests\MaterialReallocationReceiveRequest;
use App\Http\Requests\MaterialReallocationRemarksRequest;
use Illuminate\Support\Facades\DB;
use App\Traits\GuardsCompletedWarehouse;

class MaterialReallocationController extends Controller
{
    use GuardsCompletedWarehouse;

    public function index(Request $request)
    {
        $user = $request->user();

        $filters = $request->all('search', 'source_warehouse_id', 'user_id', 'status', 'start_date', 'end_date', 'sort', 'sort_direction');

        $query = MaterialReallocation::query()
            ->with(['sourceWarehouse:id,name', 'user:id,name']);

        if ($user->hasRole(User::SITE_ENGINEER_ROLES) && ! $user->hasRole(['Super Admin', 'Admin'])) {
            $query->where('user_id', $user->id);
        } elseif ($user->hasRole(['Procurement Manager', 'Procurement Manger']) && ! $user->hasRole(['Super Admin', 'Admin'])) {
            $query->whereNotIn('status', [MaterialReallocation::STATUS_DRAFT, MaterialReallocation::STATUS_SUBMITTED]);
        } elseif ($user->hasRole('Store Manager') && ! $user->hasRole(['Super Admin', 'Admin'])) {
            $query->whereIn('status', [
                MaterialReallocation::STATUS_DISPATCH_PREPARATION,
                MaterialReallocation::STATUS_WAREHOUSE_RECEIVING,
                MaterialReallocation::STATUS_COMPLETED,
            ]);
        }

        $query->filter($filters);
        $query = $this->applySort($query, $request, ['reference', 'status', 'date_requested'], 'id');

        if (in_array($request->query('output'), ['print', 'pdf', 'excel'], true)) {
            $rows = $query->limit(3000)->get();

            if ($request->query('output') === 'excel') {
                $exportRows = $rows->map(fn ($m) => [
                    'Reference' => $m->reference,
                    'Date Requested' => optional($m->date_requested)->format('Y-m-d'),
                    'Source Warehouse' => optional($m->sourceWarehouse)->name,
                    'Requested By' => optional($m->user)->name,
                    'Status' => $m->status,
                ]);

                return (new FastExcel($exportRows))->download('material-reallocations-' . now()->format('Ymd-His') . '.xlsx');
            }

            $data = [
                'title' => 'Material Reallocation Requests',
                'companyName' => optional($user->account ?? null)->name,
                'columns' => ['Reference', 'Date Requested', 'Source Warehouse', 'Requested By', 'Status'],
                'rows' => $rows->map(fn ($m) => [
                    $m->reference,
                    optional($m->date_requested)->format('Y-m-d'),
                    optional($m->sourceWarehouse)->name,
                    optional($m->user)->name,
                    $m->status,
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
                return Pdf::loadView('reports.pdf', $data)->download('material-reallocations-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $data);
        }

        $materialReallocations = $query->paginate()->withQueryString();
        $scopeIds = warehouse_scope_ids();

        return Inertia::render('MaterialReallocation/Index', [
            'filters' => $filters,
            'statuses' => MaterialReallocation::statuses(),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status', 'completed_at']),
            'canCreate' => $user->can('create', MaterialReallocation::class),
            'canViewReports' => $this->canViewReports($user),
            'materialReallocations' => new MaterialReallocationCollection($materialReallocations),
            'dashboard' => [
                'cards' => $this->buildDashboardCards($scopeIds),
                'charts' => [
                    'statusPipeline' => $this->statusPipelineChart($scopeIds),
                    'monthlyTrend' => $this->monthlyTrendChart($scopeIds),
                    'topItems' => $this->topItemsChart($scopeIds),
                    'topProjects' => $this->topProjectsChart($scopeIds),
                    'avgDuration' => $this->avgDurationChart($scopeIds),
                ],
            ],
        ]);
    }

    private function canViewReports(User $user): bool
    {
        return $user->hasRole([
            'Super Admin', 'Admin', 'Project Manager', 'Project Managers',
            'Procurement Manager', 'Procurement Manger', 'Store Manager',
        ]);
    }

    public function reports(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canViewReports($user), 403, 'You are not allowed to view Material Reallocation reports.');

        return Inertia::render('MaterialReallocation/Reports');
    }

    private function applySort($query, Request $request, array $sortable, string $defaultColumn = 'id', string $defaultDirection = 'desc')
    {
        $sort = $request->query('sort');
        $direction = $request->query('sort_direction') === 'asc' ? 'asc' : 'desc';

        if ($sort && in_array($sort, $sortable, true)) {
            return $query->orderBy($sort, $direction);
        }

        return $query->orderBy($defaultColumn, $defaultDirection);
    }

    private function scopeReallocationsToWarehouses($query, ?array $scopeIds)
    {
        if ($scopeIds === null) {
            return $query;
        }

        return $query->where(function ($q) use ($scopeIds) {
            $q->whereIn('source_warehouse_id', $scopeIds)
                ->orWhereHas('items', fn ($iq) => $iq->whereIn('destination_warehouse_id', $scopeIds));
        });
    }

    private function scopeItemsToWarehouses($query, ?array $scopeIds)
    {
        if ($scopeIds === null) {
            return $query;
        }

        return $query->where(function ($q) use ($scopeIds) {
            $q->whereIn('destination_warehouse_id', $scopeIds)
                ->orWhereHas('materialReallocation', fn ($mq) => $mq->whereIn('source_warehouse_id', $scopeIds));
        });
    }

    private function buildDashboardCards(?array $scopeIds): array
    {
        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $statusCounts = $this->scopeReallocationsToWarehouses(MaterialReallocation::query(), $scopeIds)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $completedThisMonthCount = $this->scopeReallocationsToWarehouses(MaterialReallocation::query(), $scopeIds)
            ->where('status', MaterialReallocation::STATUS_COMPLETED)
            ->whereBetween('completed_on', [$monthStart, $monthEnd])
            ->count();

        $completedTodayCount = $this->scopeReallocationsToWarehouses(MaterialReallocation::query(), $scopeIds)
            ->where('status', MaterialReallocation::STATUS_COMPLETED)
            ->whereBetween('completed_on', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->count();

        $completedItemsThisMonth = $this->scopeItemsToWarehouses(MaterialReallocationItem::query(), $scopeIds)
            ->whereHas('materialReallocation', fn ($q) => $q
                ->where('status', MaterialReallocation::STATUS_COMPLETED)
                ->whereBetween('completed_on', [$monthStart, $monthEnd]))
            ->with('item:id,price')
            ->get(['id', 'material_reallocation_id', 'item_id', 'destination_warehouse_id', 'received_quantity']);

        $totalReallocatedQty = (float) $completedItemsThisMonth->sum('received_quantity');
        $totalReallocatedValue = (float) $completedItemsThisMonth->sum(
            fn ($i) => (float) $i->received_quantity * (float) optional($i->item)->price
        );

        return [
            'draft' => (int) ($statusCounts[MaterialReallocation::STATUS_DRAFT] ?? 0),
            'waiting_pm_clearance' => (int) ($statusCounts[MaterialReallocation::STATUS_SUBMITTED] ?? 0),
            'waiting_warehouse_allocation' => (int) ($statusCounts[MaterialReallocation::STATUS_PM_CLEARANCE] ?? 0),
            'waiting_dispatch' => (int) ($statusCounts[MaterialReallocation::STATUS_WAREHOUSE_ALLOCATION] ?? 0),
            'in_transit' => (int) ($statusCounts[MaterialReallocation::STATUS_DISPATCH_PREPARATION] ?? 0),
            'waiting_final_approval' => (int) ($statusCounts[MaterialReallocation::STATUS_WAREHOUSE_RECEIVING] ?? 0),
            'completed_today' => $completedTodayCount,
            'completed_this_month' => $completedThisMonthCount,
            'rejected' => (int) ($statusCounts[MaterialReallocation::STATUS_REJECTED] ?? 0),
            'cancelled' => (int) ($statusCounts[MaterialReallocation::STATUS_CANCELLED] ?? 0),
            'total_material_reallocated_this_month' => $totalReallocatedQty,
            'total_value_reallocated_this_month' => round($totalReallocatedValue, 2),
            'avg_pm_approval_hours' => $this->averageHoursBetween($scopeIds, 'submitted_on', 'cleared_on'),
            'avg_transit_hours' => $this->averageHoursBetween($scopeIds, 'dispatch_date_time', 'received_date_time'),
            'avg_receiving_to_approval_hours' => $this->averageHoursBetween($scopeIds, 'received_date_time', 'final_approval_date_time'),
            'top_returned_materials' => $this->topReturnedMaterials($scopeIds),
            'top_projects_returning' => $this->topProjectsReturning($scopeIds),
        ];
    }

    private function averageHoursBetween(?array $scopeIds, string $startColumn, string $endColumn): ?float
    {
        $rows = $this->scopeReallocationsToWarehouses(MaterialReallocation::query(), $scopeIds)
            ->whereNotNull($startColumn)
            ->whereNotNull($endColumn)
            ->get([$startColumn, $endColumn]);

        if ($rows->isEmpty()) {
            return null;
        }

        $hours = $rows->map(fn ($r) => Carbon::parse($r->{$startColumn})->diffInMinutes(Carbon::parse($r->{$endColumn})) / 60);

        return round((float) $hours->avg(), 2);
    }

    private function topReturnedMaterials(?array $scopeIds): array
    {
        return $this->scopeItemsToWarehouses(MaterialReallocationItem::query(), $scopeIds)
            ->whereHas('materialReallocation', fn ($q) => $q->where('status', MaterialReallocation::STATUS_COMPLETED))
            ->selectRaw('item_id, SUM(received_quantity) as total_qty')
            ->groupBy('item_id')
            ->orderByDesc('total_qty')
            ->with('item:id,name,code')
            ->take(5)->get()
            ->map(fn ($row) => [
                'item_id' => $row->item_id,
                'name' => optional($row->item)->name ?: 'Unknown',
                'code' => optional($row->item)->code,
                'quantity' => (float) $row->total_qty,
            ])->all();
    }

    private function topProjectsReturning(?array $scopeIds): array
    {
        return $this->scopeReallocationsToWarehouses(MaterialReallocation::query(), $scopeIds)
            ->where('status', MaterialReallocation::STATUS_COMPLETED)
            ->selectRaw('source_warehouse_id, COUNT(*) as total_count')
            ->groupBy('source_warehouse_id')
            ->orderByDesc('total_count')
            ->with('sourceWarehouse:id,name')
            ->take(5)->get()
            ->map(fn ($row) => [
                'warehouse_id' => $row->source_warehouse_id,
                'name' => optional($row->sourceWarehouse)->name ?: 'Unknown',
                'count' => (int) $row->total_count,
            ])->all();
    }

    private function statusPipelineChart(?array $scopeIds): array
    {
        $counts = $this->scopeReallocationsToWarehouses(MaterialReallocation::query(), $scopeIds)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(MaterialReallocation::statuses())->map(fn ($status) => [
            'name' => $status,
            'y' => (int) ($counts[$status] ?? 0),
        ])->values()->all();
    }

    private function monthlyTrendChart(?array $scopeIds): array
    {
        $start = Carbon::now()->subMonths(11)->startOfMonth();

        $dateExpression = config('database.default') === 'sqlite'
            ? "strftime('%Y-%m', date_requested)"
            : "DATE_FORMAT(date_requested, '%Y-%m')";

        $rows = $this->scopeReallocationsToWarehouses(MaterialReallocation::query(), $scopeIds)
            ->where('date_requested', '>=', $start)
            ->selectRaw("{$dateExpression} as ym, COUNT(*) as total")
            ->groupBy('ym')->orderBy('ym')->pluck('total', 'ym');

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $key = $start->copy()->addMonths($i)->format('Y-m');
            $months[$key] = (int) ($rows[$key] ?? 0);
        }

        return ['categories' => array_keys($months), 'data' => array_values($months)];
    }

    private function topItemsChart(?array $scopeIds): array
    {
        return $this->scopeItemsToWarehouses(MaterialReallocationItem::query(), $scopeIds)
            ->whereHas('materialReallocation', fn ($q) => $q->where('status', MaterialReallocation::STATUS_COMPLETED))
            ->selectRaw('item_id, SUM(received_quantity) as total_qty')
            ->groupBy('item_id')->orderByDesc('total_qty')
            ->with('item:id,name,code')->take(8)->get()
            ->map(fn ($row) => [
                'name' => optional($row->item)->code ?: optional($row->item)->name ?: 'Unknown',
                'y' => (float) $row->total_qty,
                'item_id' => $row->item_id,
            ])->all();
    }

    private function topProjectsChart(?array $scopeIds): array
    {
        return $this->scopeReallocationsToWarehouses(MaterialReallocation::query(), $scopeIds)
            ->where('status', MaterialReallocation::STATUS_COMPLETED)
            ->selectRaw('source_warehouse_id, COUNT(*) as total_count')
            ->groupBy('source_warehouse_id')->orderByDesc('total_count')
            ->with('sourceWarehouse:id,name')->take(8)->get()
            ->map(fn ($row) => [
                'name' => optional($row->sourceWarehouse)->name ?: 'Unknown',
                'y' => (int) $row->total_count,
            ])->all();
    }

    private function avgDurationChart(?array $scopeIds): array
    {
        return [
            ['name' => 'PM Approval', 'y' => $this->averageHoursBetween($scopeIds, 'submitted_on', 'cleared_on') ?? 0],
            ['name' => 'Transit', 'y' => $this->averageHoursBetween($scopeIds, 'dispatch_date_time', 'received_date_time') ?? 0],
            ['name' => 'Receiving to Approval', 'y' => $this->averageHoursBetween($scopeIds, 'received_date_time', 'final_approval_date_time') ?? 0],
        ];
    }

    public function create(Request $request)
    {
        $this->authorize('create', MaterialReallocation::class);

        $user = $request->user();
        $isSiteEngineer = $user->hasRole(User::SITE_ENGINEER_ROLES) && ! $user->hasRole(['Super Admin', 'Admin']);

        $warehouses = $isSiteEngineer
            ? $user->warehouses()->where('active', 1)->visible()->orderBy('name')->get(['warehouses.id', 'warehouses.name', 'warehouses.status', 'warehouses.completed_at'])
            : Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status', 'completed_at']);

        return Inertia::render('MaterialReallocation/Form', [
            'edit' => null,
            'warehouses' => $warehouses,
            'items' => Item::ofAccount()->orderBy('name')->get(['id', 'name', 'code', 'unit_id']),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'code']),
            'reasons' => MaterialReallocation::reasons(),
            'requestNumber' => $this->nextReference(),
            'requestedBy' => $user->only(['id', 'name']),
            'dateRequested' => now()->format('Y-m-d'),
            'stockByWarehouse' => $this->stockByWarehouse($warehouses->pluck('id')->all()),
        ]);
    }

    /**
     * Sparse {warehouse_id: {item_id: quantity}} map (zero-stock pairs omitted) used
     * by Form.vue to show live availability and block requesting more than exists.
     */
    private function stockByWarehouse(array $warehouseIds): array
    {
        if (empty($warehouseIds)) {
            return [];
        }

        return \App\Models\Stock::query()
            ->main()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity', '>', 0)
            ->get(['warehouse_id', 'item_id', 'quantity'])
            ->groupBy('warehouse_id')
            ->map(fn ($rows) => $rows->pluck('quantity', 'item_id'))
            ->all();
    }

    /** Every Project / Warehouse a reallocation touches: its source and the destination of each item (plus any extra ids), as stock moves between them. */
    private function reallocationWarehouses(MaterialReallocation $reallocation, array $extra = []): array
    {
        return array_values(array_filter(array_unique(array_merge(
            [$reallocation->source_warehouse_id],
            $reallocation->items()->whereNotNull('destination_warehouse_id')->pluck('destination_warehouse_id')->all(),
            $extra
        ))));
    }

    public function store(MaterialReallocationStoreRequest $request)
    {
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseBlocked($data['source_warehouse_id'] ?? null)) {
            return $response;
        }
        $data['user_id'] = $request->user()->id;
        $data['status'] = MaterialReallocation::STATUS_DRAFT;

        $materialReallocation = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $data['reference'] = $this->nextReference();

            try {
                $materialReallocation = new MaterialReallocation();
                $materialReallocation->fill($data)->save();
                break;
            } catch (QueryException $e) {
                $isDuplicateReference = (string) $e->getCode() === '23000'
                    && str_contains($e->getMessage(), 'material_reallocations_reference_unique');

                if (! $isDuplicateReference || $attempt === 2) {
                    throw $e;
                }
            }
        }

        if (! $materialReallocation) {
            abort(500, 'Unable to generate a unique material reallocation reference.');
        }

        $materialReallocation->saveAttachments($request->file('attachments'));

        foreach ($data['items'] as $item) {
            $materialReallocation->items()->create($item);
        }

        $this->writeLog($request, $materialReallocation, 'created', null, MaterialReallocation::STATUS_DRAFT, 'Draft created.');

        $this->notifyRoles(
            ['Project Manager', 'Project Managers', 'Super Admin', 'Admin'],
            $materialReallocation,
            'material_reallocation_created',
            'Material Reallocation Draft Created',
            "{$materialReallocation->reference} was created by {$request->user()->name} and is still a Draft (not yet submitted).",
            $request->user()->id
        );

        return redirect()->route('material-reallocations.show', $materialReallocation->id)
            ->with('message', __choice('action_text', ['record' => 'Material Reallocation', 'action' => 'created']));
    }

    public function show(Request $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('view', $material_reallocation);

        $material_reallocation->load([
            'sourceWarehouse:id,name,code,address,phone,email',
            'user:id,name,username',
            'driverContact:id,name,phone',
            'clearedBy:id,name',
            'warehouseAllocatedBy:id,name',
            'dispatchedBy:id,name',
            'receivedBy:id,name',
            'finalApprovedBy:id,name',
            'items.item:id,name,code,symbology,unit_id',
            'items.unit:id,name,code',
            'items.destinationWarehouse:id,name,code',
            'items.parent',
            'items.children',
            'logs' => fn ($q) => $q->orderBy('id'),
            'logs.user:id,name',
            'attachments',
        ]);

        return Inertia::render('MaterialReallocation/Show', [
            'materialReallocation' => new MaterialReallocationResource($material_reallocation),
            'statuses' => MaterialReallocation::statuses(),
            'mainWarehouses' => Warehouse::ofAccount()->active()->visible()->mainWarehouses()->orderBy('name')->get(['id', 'name', 'completed_at']),
            'drivers' => Contact::where('category', 'Drivers')->orderBy('name')->get(['id', 'name', 'phone']),
        ]);
    }

    public function print(Request $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('print', $material_reallocation);

        $payload = $this->printPayload($material_reallocation);
        $this->writeLog($request, $material_reallocation, 'printed', $material_reallocation->status, $material_reallocation->status, 'Printed by ' . $request->user()->name . '.');

        return view('material-reallocation-print', $payload);
    }

    public function pdf(Request $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('downloadPdf', $material_reallocation);

        $payload = $this->printPayload($material_reallocation);
        $this->writeLog($request, $material_reallocation, 'printed', $material_reallocation->status, $material_reallocation->status, 'PDF downloaded by ' . $request->user()->name . '.');

        return Pdf::loadView('material-reallocation-print', $payload)
            ->download('material-reallocation-' . $material_reallocation->reference . '.pdf');
    }

    public function edit(Request $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('update', $material_reallocation);
        if ($response = $this->rejectIfWarehouseBlocked(...$this->reallocationWarehouses($material_reallocation))) {
            return $response;
        }

        $material_reallocation->load(['items', 'attachments']);

        $user = $request->user();
        $warehouses = $user->warehouses()
            ->where('active', 1)
            ->visible()
            ->orderBy('name')
            ->get(['warehouses.id', 'warehouses.name', 'warehouses.completed_at']);

        if ($material_reallocation->source_warehouse_id && ! $warehouses->contains('id', $material_reallocation->source_warehouse_id)) {
            $selectedWarehouse = Warehouse::ofAccount()
                ->whereKey($material_reallocation->source_warehouse_id)
                ->get(['id', 'name', 'completed_at']);

            $warehouses = $warehouses->concat($selectedWarehouse)->unique('id')->sortBy('name')->values();
        }

        return Inertia::render('MaterialReallocation/Form', [
            'edit' => new MaterialReallocationResource($material_reallocation),
            'warehouses' => $warehouses,
            'items' => Item::ofAccount()->orderBy('name')->get(['id', 'name', 'code', 'unit_id']),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'code']),
            'reasons' => MaterialReallocation::reasons(),
            'requestNumber' => $material_reallocation->reference,
            'requestedBy' => $user->only(['id', 'name']),
            'dateRequested' => $material_reallocation->date_requested?->format('Y-m-d'),
            'stockByWarehouse' => $this->stockByWarehouse($warehouses->pluck('id')->all()),
        ]);
    }

    public function update(MaterialReallocationUpdateRequest $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('update', $material_reallocation);
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseBlocked(...$this->reallocationWarehouses($material_reallocation, [$data['source_warehouse_id'] ?? null]))) {
            return $response;
        }

        $before = [
            'source_warehouse_id' => $material_reallocation->source_warehouse_id,
            'date_requested' => $material_reallocation->date_requested?->format('Y-m-d'),
            'reason' => $material_reallocation->reason,
            'reason_details' => $material_reallocation->reason_details,
            'remarks' => $material_reallocation->remarks,
        ];
        $beforeItems = $material_reallocation->items()->get(['item_id', 'requested_quantity'])->keyBy('item_id');

        $material_reallocation->update([
            'source_warehouse_id' => $data['source_warehouse_id'],
            'date_requested' => $data['date_requested'],
            'reason' => $data['reason'] ?? null,
            'reason_details' => $data['reason_details'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ]);

        $material_reallocation->items()->delete();
        foreach ($data['items'] as $item) {
            $material_reallocation->items()->create($item);
        }

        $material_reallocation->saveAttachments($request->file('attachments'));

        $changeSummary = $this->describeMaterialReallocationChanges($before, $beforeItems, $data);

        $this->writeLog($request, $material_reallocation, 'updated', $material_reallocation->status, $material_reallocation->status, $changeSummary);

        // Material Reallocation workflow page: "Edited" (the person who edits is not told about their own edit)
        $this->alertStage($material_reallocation, 'material_reallocation_updated', __('Material Reallocation Edited'), "{$material_reallocation->reference} was edited by {$request->user()->name}.");

        return redirect()->route('material-reallocations.show', $material_reallocation->id)
            ->with('message', __choice('action_text', ['record' => 'Material Reallocation', 'action' => 'updated']));
    }

    public function destroy(Request $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('delete', $material_reallocation);
        if ($response = $this->rejectIfWarehouseBlocked(...$this->reallocationWarehouses($material_reallocation))) {
            return $response;
        }

        $material_reallocation->delete();
        $this->alertStage($material_reallocation, 'material_reallocation_deleted', __('Material Reallocation Deleted'), "{$material_reallocation->reference} was deleted by {$request->user()->name}.", route('material-reallocations.index'));

        return redirect()->route('material-reallocations.index')
            ->with('message', __choice('action_text', ['record' => 'Material Reallocation', 'action' => 'deleted']));
    }

    public function submit(Request $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('submit', $material_reallocation);
        if ($response = $this->rejectIfWarehouseBlocked(...$this->reallocationWarehouses($material_reallocation))) {
            return $response;
        }

        $previousStatus = $material_reallocation->status;
        $material_reallocation->update([
            'status' => MaterialReallocation::STATUS_SUBMITTED,
            'submitted_on' => now(),
        ]);

        $this->writeLog($request, $material_reallocation, 'submitted', $previousStatus, MaterialReallocation::STATUS_SUBMITTED, 'Submitted for Project Manager clearance.');

        $this->notifyRoles(
            ['Project Manager', 'Project Managers', 'Super Admin', 'Admin'],
            $material_reallocation,
            'material_reallocation_submitted',
            'Material Reallocation Submitted',
            "{$material_reallocation->reference} has been submitted and is awaiting Project Manager clearance.",
            $request->user()->id
        );
        $this->notifyWimsConnectReallocation(
            'reallocation-pm-clearance',
            $material_reallocation,
            ['Project Manager', 'Project Managers'],
            "🔔 WIMS - Material Reallocation Submitted\nReference: {$material_reallocation->reference}\nAwaiting your clearance.",
            $request->user()->id
        );

        return back()->with('message', __('Material Reallocation has been submitted.'));
    }

    public function clear(MaterialReallocationClearRequest $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('clear', $material_reallocation);
        if ($response = $this->rejectIfWarehouseBlocked(...$this->reallocationWarehouses($material_reallocation))) {
            return $response;
        }
        $data = $request->validated();

        $previousStatus = $material_reallocation->status;
        $material_reallocation->update([
            'status' => MaterialReallocation::STATUS_PM_CLEARANCE,
            'cleared_by_user_id' => $request->user()->id,
            'cleared_on' => now(),
            'approval_remarks' => $data['remarks'] ?? null,
        ]);

        $this->writeLog($request, $material_reallocation, 'cleared', $previousStatus, MaterialReallocation::STATUS_PM_CLEARANCE, $data['remarks'] ?? 'Cleared by Project Manager.');

        $this->notifyRoles(
            ['Procurement Manager', 'Procurement Manger', 'Super Admin', 'Admin'],
            $material_reallocation,
            'material_reallocation_cleared',
            'Material Reallocation Cleared',
            "{$material_reallocation->reference} has been cleared by {$request->user()->name} and is ready for warehouse allocation.",
            $request->user()->id
        );
        $this->notifyUser(
            $material_reallocation->user,
            $material_reallocation,
            'material_reallocation_cleared',
            'Your Material Reallocation Was Cleared',
            "Your request {$material_reallocation->reference} has been cleared by {$request->user()->name}."
        );
        $this->notifyMaterialReallocationActivity($material_reallocation, 'Cleared');
        $this->notifyWimsConnectReallocation(
            'reallocation-allocation-needed',
            $material_reallocation,
            ['Procurement Manager', 'Procurement Manger'],
            "🔔 WIMS - Material Reallocation Cleared\nReference: {$material_reallocation->reference}\nReady for warehouse allocation.",
            $request->user()->id
        );

        return back()->with('message', __('Material Reallocation has been cleared.'));
    }

    public function allocate(MaterialReallocationAllocateRequest $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('allocate', $material_reallocation);
        if ($response = $this->rejectIfWarehouseBlocked(...$this->reallocationWarehouses($material_reallocation, collect($request->input('items', []))->flatMap(fn ($i) => collect($i['destinations'] ?? [])->pluck('warehouse_id'))->all()))) {
            return $response;
        }
        $data = $request->validated();

        $itemsById = $material_reallocation->items()->get()->keyBy('id');
        $mainWarehouseIds = Warehouse::mainWarehouses()->pluck('id')->all();
        $summary = [];

        DB::transaction(function () use ($data, $itemsById, $mainWarehouseIds, $material_reallocation, $request, &$summary) {
            foreach ($data['items'] as $itemData) {
                $row = $itemsById->get((int) $itemData['id']);
                abort_unless($row, 422, 'Invalid material reallocation item.');
                abort_unless(is_null($row->parent_item_id), 422, 'Only original item rows may be allocated.');

                $destinations = collect($itemData['destinations']);

                foreach ($destinations as $destination) {
                    abort_unless(in_array((int) $destination['warehouse_id'], $mainWarehouseIds, true), 422, 'Destination must be a Main Warehouse.');
                }

                $totalAllocated = $destinations->sum(fn ($d) => (float) $d['quantity']);
                abort_unless(abs($totalAllocated - (float) $row->requested_quantity) < 0.0001, 422, 'Allocated quantity must equal the requested quantity for item #' . $row->id . '.');

                $first = $destinations->first();
                $row->update([
                    'destination_warehouse_id' => $first['warehouse_id'],
                    'allocated_quantity' => $first['quantity'],
                ]);

                $itemName = optional($row->item)->name ?? $row->item_id;
                $destinationNames = [];
                $destinationNames[] = optional(Warehouse::find($first['warehouse_id']))->name . ' (' . $first['quantity'] . ')';

                foreach ($destinations->slice(1) as $split) {
                    $sibling = $row->replicate(['destination_warehouse_id', 'allocated_quantity', 'received_quantity']);
                    $sibling->parent_item_id = $row->id;
                    $sibling->destination_warehouse_id = $split['warehouse_id'];
                    $sibling->allocated_quantity = $split['quantity'];
                    $sibling->received_quantity = null;
                    $sibling->save();

                    $destinationNames[] = optional(Warehouse::find($split['warehouse_id']))->name . ' (' . $split['quantity'] . ')';
                }

                $summary[] = $itemName . ' → ' . implode(', ', $destinationNames);
            }

            $material_reallocation->update([
                'status' => MaterialReallocation::STATUS_WAREHOUSE_ALLOCATION,
                'warehouse_allocated_by_user_id' => $request->user()->id,
                'warehouse_allocated_on' => now(),
            ]);
        });

        $this->writeLog($request, $material_reallocation, 'allocated', MaterialReallocation::STATUS_PM_CLEARANCE, MaterialReallocation::STATUS_WAREHOUSE_ALLOCATION, implode('; ', $summary));

        $this->notifyRoles(
            ['Project Manager', 'Project Managers', 'Super Admin', 'Admin'],
            $material_reallocation,
            'material_reallocation_allocated',
            'Material Reallocation Warehouse Allocated',
            "{$material_reallocation->reference} has had its destination warehouse(s) allocated.",
            $request->user()->id
        );
        $this->notifyUser(
            $material_reallocation->user,
            $material_reallocation,
            'material_reallocation_allocated',
            'Warehouse Allocated - Arrange Dispatch',
            "Your request {$material_reallocation->reference} has been allocated to a Main Warehouse. Please arrange dispatch."
        );
        $this->notifyWimsConnectUser(
            'reallocation-dispatch-needed',
            $material_reallocation,
            $material_reallocation->user,
            "🔔 WIMS - Warehouse Allocated\nReference: {$material_reallocation->reference}\nPlease arrange dispatch."
        );

        return back()->with('message', __('Warehouse allocation has been saved.'));
    }

    public function dispatchShipment(MaterialReallocationDispatchRequest $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('dispatch', $material_reallocation);
        if ($response = $this->rejectIfWarehouseBlocked(...$this->reallocationWarehouses($material_reallocation))) {
            return $response;
        }
        $data = $request->validated();

        $previousStatus = $material_reallocation->status;
        $material_reallocation->update([
            'driver_contact_id' => $data['driver_contact_id'],
            'vehicle' => $data['vehicle'],
            'dispatched_by_user_id' => $request->user()->id,
            'dispatch_date_time' => $data['dispatch_date_time'],
            'status' => MaterialReallocation::STATUS_DISPATCH_PREPARATION,
        ]);

        $material_reallocation->saveAttachments($request->file('attachments'), 'dispatch');

        $driverName = optional(Contact::find($data['driver_contact_id']))->name ?? $data['driver_contact_id'];
        $this->writeLog($request, $material_reallocation, 'dispatched', $previousStatus, MaterialReallocation::STATUS_DISPATCH_PREPARATION, "Dispatched with driver {$driverName}, vehicle {$data['vehicle']}.");

        $this->notifyRoles(
            ['Store Manager', 'Project Manager', 'Project Managers', 'Super Admin', 'Admin'],
            $material_reallocation,
            'material_reallocation_dispatched',
            'Material Reallocation Dispatched',
            "{$material_reallocation->reference} has been dispatched with driver {$driverName} (vehicle {$data['vehicle']}) and is awaiting receipt.",
            $request->user()->id
        );
        $this->notifyWimsConnectReallocation(
            'reallocation-receiving-needed',
            $material_reallocation,
            ['Store Manager'],
            "🔔 WIMS - Shipment Dispatched\nReference: {$material_reallocation->reference}\nDriver: {$driverName}, Vehicle: {$data['vehicle']}\nAwaiting receipt.",
            $request->user()->id
        );

        return back()->with('message', __('Dispatch details have been saved.'));
    }

    public function receive(MaterialReallocationReceiveRequest $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('receive', $material_reallocation);
        if ($response = $this->rejectIfWarehouseBlocked(...$this->reallocationWarehouses($material_reallocation))) {
            return $response;
        }
        $data = $request->validated();

        $itemsById = $material_reallocation->items()->get()->keyBy('id');
        $summary = [];

        foreach ($data['items'] as $itemData) {
            $row = $itemsById->get((int) $itemData['id']);
            abort_unless($row, 422, 'Invalid material reallocation item.');

            $row->update(['received_quantity' => $itemData['received_quantity']]);

            $itemName = optional($row->item)->name ?? $row->item_id;
            $summary[] = "{$itemName}: allocated " . ($row->allocated_quantity ?? 0) . ', received ' . $itemData['received_quantity'];
        }

        $previousStatus = $material_reallocation->status;
        $material_reallocation->update([
            'received_by_user_id' => $request->user()->id,
            'received_date_time' => now(),
            'status' => MaterialReallocation::STATUS_WAREHOUSE_RECEIVING,
        ]);

        $material_reallocation->saveAttachments($request->file('attachments'), 'receiving');

        $this->writeLog($request, $material_reallocation, 'received', $previousStatus, MaterialReallocation::STATUS_WAREHOUSE_RECEIVING, implode('; ', $summary));

        $this->notifyRoles(
            ['Project Manager', 'Project Managers', 'Super Admin', 'Admin'],
            $material_reallocation,
            'material_reallocation_received',
            'Material Reallocation Received',
            "{$material_reallocation->reference} has been received at the destination warehouse and is awaiting final approval.",
            $request->user()->id
        );
        $this->notifyUser(
            $material_reallocation->user,
            $material_reallocation,
            'material_reallocation_received',
            'Your Material Reallocation Was Received',
            "Your request {$material_reallocation->reference} has been received at the destination warehouse and is awaiting final approval."
        );
        $this->notifyWimsConnectReallocation(
            'reallocation-final-approval-needed',
            $material_reallocation,
            ['Super Admin', 'Admin'],
            "🔔 WIMS - Reallocation Received\nReference: {$material_reallocation->reference}\nAwaiting final approval.",
            $request->user()->id
        );

        return back()->with('message', __('Warehouse receiving details have been saved.'));
    }

    public function finalApprove(Request $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('finalApprove', $material_reallocation);
        if ($response = $this->rejectIfWarehouseBlocked(...$this->reallocationWarehouses($material_reallocation))) {
            return $response;
        }

        $items = $material_reallocation->items()->get();
        abort_if($items->contains(fn ($item) => is_null($item->received_quantity)), 422, 'Every item must have a received quantity before Final Approval.');

        // Signatories: when the document setup requires a signature and blocks finalising, refuse the final approval without it (nothing changes with the default setup).
        if ($blocked = \App\Services\Signatories\Documents\Support\RefusesWithoutSignatures::message('material_reallocation', app(\App\Services\Signatories\Documents\MaterialReallocationDocument::class)->asIfFinalApproved($material_reallocation, (int) $request->user()->id), __('finally approved'))) {
            return back()->with('error', $blocked);
        }

        $previousStatus = $material_reallocation->status;

        DB::transaction(function () use ($material_reallocation, $request) {
            $material_reallocation->update([
                'status' => MaterialReallocation::STATUS_COMPLETED,
                'final_approved_by_user_id' => $request->user()->id,
                'final_approval_date_time' => now(),
                'completed_on' => now(),
            ]);

            event(new MaterialReallocationEvent($material_reallocation->fresh('items.item', 'items.unit'), 'finalApproved'));
        });

        $totalMoved = $items->sum('received_quantity');
        $this->writeLog($request, $material_reallocation, 'final_approved', $previousStatus, MaterialReallocation::STATUS_COMPLETED, "Final approval granted. Total quantity moved: {$totalMoved}.");

        $this->notifyRoles(
            ['Project Manager', 'Project Managers', 'Super Admin', 'Admin'],
            $material_reallocation,
            'material_reallocation_completed',
            'Material Reallocation Completed',
            "{$material_reallocation->reference} has been finally approved and inventory has been updated. Total quantity moved: {$totalMoved}.",
            $request->user()->id
        );
        $this->notifyUser(
            $material_reallocation->user,
            $material_reallocation,
            'material_reallocation_completed',
            'Material Reallocation Completed',
            "Your request {$material_reallocation->reference} has been finally approved and inventory has been updated."
        );
        $this->notifyMaterialReallocationActivity($material_reallocation, 'Final Approved', [
            'Approved By' => $request->user()->name,
            'Approved On' => now()->format('Y-m-d H:i'),
            'Total Quantity Moved' => $totalMoved,
        ], attachPdf: true);
        $this->notifyWimsConnectUser(
            'reallocation-completed',
            $material_reallocation,
            $material_reallocation->user,
            "🔔 WIMS - Reallocation Completed\nReference: {$material_reallocation->reference}\nTotal quantity moved: {$totalMoved}."
        );

        return back()->with('message', __('Material Reallocation has been finally approved and inventory updated.'));
    }

    public function requestChanges(MaterialReallocationRemarksRequest $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('requestChanges', $material_reallocation);
        if ($response = $this->rejectIfWarehouseBlocked(...$this->reallocationWarehouses($material_reallocation))) {
            return $response;
        }
        $data = $request->validated();

        $previousStatus = $material_reallocation->status;
        $material_reallocation->update([
            'status' => MaterialReallocation::STATUS_REQUEST_CHANGES,
            'approval_remarks' => $data['remarks'],
        ]);

        $this->writeLog($request, $material_reallocation, 'request_changes', $previousStatus, MaterialReallocation::STATUS_REQUEST_CHANGES, $data['remarks']);

        $this->notifyUser(
            $material_reallocation->user,
            $material_reallocation,
            'material_reallocation_request_changes',
            'Changes Requested on Your Material Reallocation',
            "Changes have been requested on {$material_reallocation->reference}: {$data['remarks']}"
        );
        $this->notifyWimsConnectUser(
            'reallocation-changes-requested',
            $material_reallocation,
            $material_reallocation->user,
            "🔔 WIMS - Changes Requested\nReference: {$material_reallocation->reference}\nRemarks: {$data['remarks']}"
        );

        return back()->with('message', __('Changes have been requested from the Site Engineer.'));
    }

    public function reject(MaterialReallocationRemarksRequest $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('reject', $material_reallocation);
        if ($response = $this->rejectIfWarehouseBlocked(...$this->reallocationWarehouses($material_reallocation))) {
            return $response;
        }
        $data = $request->validated();

        $previousStatus = $material_reallocation->status;
        $material_reallocation->update([
            'status' => MaterialReallocation::STATUS_REJECTED,
            'approval_remarks' => $data['remarks'],
        ]);

        $this->writeLog($request, $material_reallocation, 'rejected', $previousStatus, MaterialReallocation::STATUS_REJECTED, $data['remarks']);

        $this->notifyUser(
            $material_reallocation->user,
            $material_reallocation,
            'material_reallocation_rejected',
            'Material Reallocation Rejected',
            "Your request {$material_reallocation->reference} has been rejected: {$data['remarks']}"
        );
        $this->notifyWimsConnectUser(
            'reallocation-rejected',
            $material_reallocation,
            $material_reallocation->user,
            "🔔 WIMS - Reallocation Rejected\nReference: {$material_reallocation->reference}\nRemarks: {$data['remarks']}"
        );

        return back()->with('message', __('Material Reallocation has been rejected.'));
    }

    public function cancel(MaterialReallocationRemarksRequest $request, MaterialReallocation $material_reallocation)
    {
        $this->authorize('cancel', $material_reallocation);
        if ($response = $this->rejectIfWarehouseBlocked(...$this->reallocationWarehouses($material_reallocation))) {
            return $response;
        }
        $data = $request->validated();

        $previousStatus = $material_reallocation->status;
        $material_reallocation->update([
            'status' => MaterialReallocation::STATUS_CANCELLED,
            'approval_remarks' => $data['remarks'] ?? null,
        ]);

        $this->writeLog($request, $material_reallocation, 'cancelled', $previousStatus, MaterialReallocation::STATUS_CANCELLED, $data['remarks'] ?? 'Cancelled.');

        $this->notifyUser(
            $material_reallocation->user,
            $material_reallocation,
            'material_reallocation_cancelled',
            'Material Reallocation Cancelled',
            "Your request {$material_reallocation->reference} has been cancelled." . (! empty($data['remarks']) ? " Remarks: {$data['remarks']}" : '')
        );
        $this->notifyWimsConnectUser(
            'reallocation-cancelled',
            $material_reallocation,
            $material_reallocation->user,
            "🔔 WIMS - Reallocation Cancelled\nReference: {$material_reallocation->reference}"
        );

        return back()->with('message', __('Material Reallocation has been cancelled.'));
    }

    protected function describeMaterialReallocationChanges(array $before, \Illuminate\Support\Collection $beforeItems, array $after): string
    {
        $changes = [];

        if ((int) $before['source_warehouse_id'] !== (int) $after['source_warehouse_id']) {
            $oldName = optional(Warehouse::find($before['source_warehouse_id']))->name ?? $before['source_warehouse_id'];
            $newName = optional(Warehouse::find($after['source_warehouse_id']))->name ?? $after['source_warehouse_id'];
            $changes[] = "Source Warehouse: {$oldName} → {$newName}";
        }

        if ($before['date_requested'] !== $after['date_requested']) {
            $changes[] = "Date Requested: {$before['date_requested']} → {$after['date_requested']}";
        }

        if ($before['reason'] !== ($after['reason'] ?? null)) {
            $changes[] = "Reason for Allocation: {$before['reason']} → " . ($after['reason'] ?? '-');
        }

        if (($before['reason_details'] ?? null) !== ($after['reason_details'] ?? null)) {
            $changes[] = 'Reason explanation updated';
        }

        if (($before['remarks'] ?? null) !== ($after['remarks'] ?? null)) {
            $changes[] = 'Remarks updated';
        }

        $afterItemsById = collect($after['items'])->keyBy(fn ($item) => (int) $item['item_id']);
        $beforeItemIds = $beforeItems->keys()->map(fn ($id) => (int) $id);
        $afterItemIds = $afterItemsById->keys();

        $itemNames = Item::whereIn('id', $afterItemIds->merge($beforeItemIds)->unique())->pluck('name', 'id');

        foreach ($afterItemIds->diff($beforeItemIds) as $id) {
            $new = $afterItemsById[$id];
            $changes[] = 'Added item "' . ($itemNames[$id] ?? $id) . '" (qty ' . $new['requested_quantity'] . ')';
        }

        foreach ($beforeItemIds->diff($afterItemIds) as $id) {
            $changes[] = 'Removed item "' . ($itemNames[$id] ?? $id) . '"';
        }

        foreach ($afterItemIds->intersect($beforeItemIds) as $id) {
            $old = $beforeItems[$id];
            $new = $afterItemsById[$id];

            if ((float) $old->requested_quantity !== (float) $new['requested_quantity']) {
                $changes[] = '"' . ($itemNames[$id] ?? $id) . '" quantity ' . $old->requested_quantity . ' → ' . $new['requested_quantity'];
            }
        }

        if (empty($changes)) {
            return 'Request details updated (no field changes detected).';
        }

        return 'Request updated: ' . implode('; ', $changes) . '.';
    }

    /**
     * Emails the warehouse-email-subscribers list for every real stage transition
     * (submit onward — Draft create/edit/delete stays silent since a Draft is a
     * private working copy, not yet a real event for anyone else).
     */
    protected function notifyMaterialReallocationActivity(MaterialReallocation $materialReallocation, string $action, array $extraDetails = [], bool $attachPdf = false): void
    {
        $materialReallocation->loadMissing([
            'sourceWarehouse:id,name,status',
            'user:id,name',
            'items.item:id,name,code',
            'items.unit:id,name',
            'items.destinationWarehouse:id,name',
            'clearedBy:id,name',
            'warehouseAllocatedBy:id,name',
            'dispatchedBy:id,name',
            'driverContact:id,name,phone',
            'receivedBy:id,name',
            'finalApprovedBy:id,name',
            'logs' => fn ($q) => $q->orderBy('id'),
            'logs.user:id,name',
        ]);

        $performedBy = optional(auth()->user())->name ?: optional($materialReallocation->user)->name;

        $details = [
            'Date' => optional($materialReallocation->date_requested)->format('Y-m-d'),
            'Status' => $materialReallocation->status,
            'Action' => $action,
            'Requested By' => optional($materialReallocation->user)->name,
        ];

        if ($action === 'Cleared') {
            $details['Cleared By'] = optional($materialReallocation->clearedBy)->name;
            $details['Cleared On'] = optional($materialReallocation->cleared_on)->format('Y-m-d H:i');
        }

        $auditTrail = $materialReallocation->logs->map(fn ($log) => [
            'action' => $log->action,
            'status' => trim(($log->previous_status ? "{$log->previous_status} → " : '') . $log->new_status),
            'by' => trim(optional($log->user)->name . ($log->role ? " ({$log->role})" : '')),
            'datetime' => optional($log->created_at)->format('Y-m-d H:i'),
            'remarks' => $log->remarks,
        ])->values()->all();

        // "Final Approved" is the terminal, most-referenced-later notification, so it gets the full
        // explainable picture (named chain-of-custody stages, per-item destination/received/variance,
        // and the whole record as a PDF) rather than just the raw audit trail every other stage gets.
        $chainOfCustody = [];
        $items = $this->extractReallocationItems($materialReallocation->items);
        $attachments = [];

        if ($action === 'Final Approved') {
            $chainOfCustody = collect([
                ['milestone' => 'Submitted', 'by' => optional($materialReallocation->user)->name, 'when' => optional($materialReallocation->submitted_on)->format('Y-m-d H:i')],
                ['milestone' => 'Project Manager Clearance', 'by' => optional($materialReallocation->clearedBy)->name, 'when' => optional($materialReallocation->cleared_on)->format('Y-m-d H:i')],
                ['milestone' => 'Warehouse Allocation', 'by' => optional($materialReallocation->warehouseAllocatedBy)->name, 'when' => optional($materialReallocation->warehouse_allocated_on)->format('Y-m-d H:i')],
                ['milestone' => 'Dispatched', 'by' => optional($materialReallocation->dispatchedBy)->name, 'when' => optional($materialReallocation->dispatch_date_time)->format('Y-m-d H:i')],
                ['milestone' => 'Warehouse Receiving', 'by' => optional($materialReallocation->receivedBy)->name, 'when' => optional($materialReallocation->received_date_time)->format('Y-m-d H:i')],
                ['milestone' => 'Final Approval', 'by' => optional($materialReallocation->finalApprovedBy)->name, 'when' => optional($materialReallocation->final_approval_date_time)->format('Y-m-d H:i')],
            ])->filter(fn ($stage) => filled($stage['when']))->values()->all();

            $items = $this->extractReallocationItemsDetailed(
                $materialReallocation->items,
                optional($materialReallocation->warehouseAllocatedBy)->name ?? '—'
            );
        }

        if ($attachPdf) {
            $pdfBinary = Pdf::loadView('material-reallocation-print', $this->printPayload($materialReallocation))->output();
            $attachments[] = [
                'data' => $pdfBinary,
                'filename' => 'material-reallocation-' . $materialReallocation->reference . '.pdf',
                'mime' => 'application/pdf',
            ];
        }

        // only the two steps that have an email report on the Material Reallocations workflow page
        if (! in_array($action, ['Cleared', 'Final Approved'], true)) {
            return;
        }
        MainWarehouseEmailService::report(
            'reallocations', $action === 'Cleared' ? 'report_cleared' : 'report_final_approved', $materialReallocation,
            'Material Reallocation',
            (string) $materialReallocation->reference,
            optional($materialReallocation->sourceWarehouse)->name ?? 'N/A',
            array_merge($details, $extraDetails),
            getAccountId(),
            $items,
            $performedBy,
            "Material Reallocation {$action} - {$materialReallocation->reference}",
            $auditTrail,
            $chainOfCustody,
            $attachments,
            array_filter([$materialReallocation->source_warehouse_id ?? null])
        );
    }

    private function extractReallocationItems($items): array
    {
        return $items->map(fn ($row) => [
            'code' => optional($row->item)->code ?? '—',
            'name' => optional($row->item)->name ?? '—',
            'quantity' => $row->received_quantity ?? $row->allocated_quantity ?? $row->requested_quantity,
            'unit' => optional($row->unit)->name ?? '—',
        ])->values()->all();
    }

    /**
     * Richer per-item shape used only for the Final Approved email: destination, who allocated it,
     * received quantity, and a variance badge (received vs. allocated) with an explained color meaning.
     */
    private function extractReallocationItemsDetailed($items, string $allocatedByName): array
    {
        return $items->map(function ($row) use ($allocatedByName) {
            $allocated = (float) ($row->allocated_quantity ?? 0);
            $received = $row->received_quantity;

            $varianceLabel = '—';
            $varianceColor = '#6b7280';
            $varianceBg = '#f3f4f6';

            if (! is_null($received)) {
                $diff = (float) $received - $allocated;

                if (abs($diff) < 0.0001) {
                    $varianceLabel = 'Matches Allocation';
                    $varianceColor = '#047857';
                    $varianceBg = '#d1fae5';
                } elseif ($diff < 0) {
                    $varianceLabel = 'Short (' . number_format(abs($diff), 2) . ')';
                    $varianceColor = '#b91c1c';
                    $varianceBg = '#fee2e2';
                } else {
                    $varianceLabel = 'Exceeds (' . number_format($diff, 2) . ')';
                    $varianceColor = '#92400e';
                    $varianceBg = '#fef3c7';
                }
            }

            return [
                'code' => optional($row->item)->code ?? '—',
                'name' => optional($row->item)->name ?? '—',
                'unit' => optional($row->unit)->name ?? optional($row->unit)->code ?? '—',
                'allocated_quantity' => ! is_null($row->allocated_quantity) ? number_format($allocated, 2) : '—',
                'destination' => optional($row->destinationWarehouse)->name ?? '—',
                'allocated_by' => $allocatedByName,
                'received_quantity' => ! is_null($received) ? number_format((float) $received, 2) : '—',
                'variance_label' => $varianceLabel,
                'variance_color' => $varianceColor,
                'variance_bg' => $varianceBg,
            ];
        })->values()->all();
    }

    /**
     * In-app bell notification to every user holding any of the given roles,
     * excluding the actor themselves so nobody gets notified of their own action.
     */
    /**
     * Every notice of a reallocation stage goes to the Material Reallocation workflow page's settings (Who Gets Notified / Messages);
     * the roles named at each call below are only the starting audience there. The stage is told apart by $type.
     */
    protected function notifyRoles(array $roleNames, MaterialReallocation $materialReallocation, string $type, string $title, string $message, ?int $excludeUserId = null)
    {
        $this->alertStage($materialReallocation, $type, $title, $message);
    }

    protected function notifyUser(?User $user, MaterialReallocation $materialReallocation, string $type, string $title, string $message): void
    {
        $this->alertStage($materialReallocation, $type, $title, $message);
    }

    /** The older WIMS Connect messages are the same notices (SMS / WhatsApp / Meta now live on the workflow page): nothing more to send here. */
    protected function notifyWimsConnectReallocation(string $eventKey, MaterialReallocation $materialReallocation, array $roleNames, string $message, ?int $excludeUserId = null): void
    {
    }

    protected function notifyWimsConnectUser(string $eventKey, MaterialReallocation $materialReallocation, ?User $user, string $message): void
    {
    }

    private function alertStage(MaterialReallocation $materialReallocation, string $type, string $title, string $message, ?string $url = null): void
    {
        $event = [
            'material_reallocation_created' => 'created', 'material_reallocation_submitted' => 'submitted', 'material_reallocation_cleared' => 'cleared',
            'material_reallocation_allocated' => 'allocated', 'material_reallocation_dispatched' => 'dispatched', 'material_reallocation_received' => 'received',
            'material_reallocation_completed' => 'completed', 'material_reallocation_request_changes' => 'changes_requested',
            'material_reallocation_rejected' => 'rejected', 'material_reallocation_cancelled' => 'cancelled',
            'material_reallocation_updated' => 'updated', 'material_reallocation_deleted' => 'deleted',
        ][$type] ?? null;
        if (! $event) {
            return;
        }
        $materialReallocation->loadMissing(['sourceWarehouse:id,name', 'user:id,name']);
        \App\Services\Workflow\WorkflowMessenger::dispatch('reallocations', $event, $materialReallocation, auth()->user(), [
            'account_id' => getAccountId(1), 'title' => $title, 'message' => $message, 'url' => $url ?: route('material-reallocations.show', $materialReallocation->id),
            'owner_user_id' => (int) $materialReallocation->user_id, 'remarks' => $materialReallocation->approval_remarks, 'skip_actor' => in_array($type, ['material_reallocation_updated', 'material_reallocation_deleted'], true), 'dedupe' => $materialReallocation->id . '|' . $type . '|' . $materialReallocation->status . '|' . optional($materialReallocation->updated_at)->timestamp,
            'values' => ['reference' => $materialReallocation->reference, 'project' => optional($materialReallocation->sourceWarehouse)->name ?? '', 'requester' => optional($materialReallocation->user)->name ?? ''],
        ]);
    }

    protected function writeLog(Request $request, MaterialReallocation $materialReallocation, string $action, ?string $previousStatus, ?string $newStatus, ?string $remarks): void
    {
        $user = $request->user();

        $materialReallocation->logs()->create([
            'user_id' => $user?->id,
            'role' => $user ? optional($user->roles->first())->name : null,
            'action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'remarks' => $remarks,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    protected function printPayload(MaterialReallocation $materialReallocation): array
    {
        $materialReallocation->load([
            'sourceWarehouse:id,name,code,address,phone,email',
            'user:id,name,username',
            'driverContact:id,name,phone',
            'clearedBy:id,name',
            'warehouseAllocatedBy:id,name',
            'dispatchedBy:id,name',
            'receivedBy:id,name',
            'finalApprovedBy:id,name',
            'items.item:id,name,code,symbology,unit_id',
            'items.unit:id,name,code',
            'items.destinationWarehouse:id,name,code',
            'items.parent',
            'items.children',
            'logs' => fn ($q) => $q->orderBy('id'),
            'logs.user:id,name',
            'attachments',
        ]);

        return [
            'materialReallocation' => $materialReallocation,
            'logoPath' => $this->reportLogo(),
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => auth()->user()->name,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('material_reallocation', $materialReallocation),
        ];
    }

    protected function reportLogo(): ?string
    {
        $logo = public_path('images/logo.png');
        if (! file_exists($logo)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode(file_get_contents($logo));
    }

    protected function nextReference(): string
    {
        $year = now()->year;
        $prefix = "MRR-{$year}-";

        // Query the raw table (not Eloquent) so soft-deleted records are included and their numbers are never reused.
        $maxSuffix = (int) DB::table('material_reallocations')
            ->selectRaw('MAX(CAST(SUBSTRING(reference, ?) AS UNSIGNED)) as max_suffix', [strlen($prefix) + 1])
            ->where('reference', 'like', $prefix . '%')
            ->value('max_suffix');

        return $prefix . str_pad((string) ($maxSuffix + 1), 6, '0', STR_PAD_LEFT);
    }
}
