<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestNotificationRule;
use App\Support\MaterialRequestVisibility;
use App\Models\MaterialRequestWorkflowStep;
use App\Models\Contact;
use App\Policies\MaterialRequestPolicy;
use Illuminate\Validation\ValidationException;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Notifications\AppNotification;
use App\Services\MaterialRequestMessenger;
use App\Services\WimsConnect\CommunicationService;
use App\Events\MaterialRequestStatusChanged;
use App\Http\Resources\MaterialRequestResource;
use App\Http\Requests\MaterialRequestStoreRequest;
use App\Http\Requests\MaterialRequestStatusRequest;
use App\Http\Requests\MaterialRequestUpdateRequest;
use App\Http\Resources\MaterialRequestCollection;
use Illuminate\Support\Facades\DB;
use App\Traits\GuardsCompletedWarehouse;

class MaterialRequestController extends Controller
{
    use GuardsCompletedWarehouse;

    public function __construct()
    {
        // The legacy Authorizable auto-gate would derive one permission per action name
        // (e.g. "index-material-requests") and enforce it BEFORE any policy runs. Every action below already has its
        // own explicit authorize() call, and MaterialRequestPolicy checks the real permissions
        // (read / view-all / create / approve / procure / issue / receive / complete / print / download ...)
        // assigned on the Roles screen, so opting out of the auto-gate here is safe.
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewAny', MaterialRequest::class);

        $filters = $request->all('search', 'warehouse_id', 'user_id', 'status', 'start_date', 'end_date');

        $query = MaterialRequest::query()
            ->select('material_requests.*')
            ->selectSub(function ($subQuery) {
                $subQuery->from('material_request_status_logs as mrsl')
                    ->join('users as approved_by_users', 'approved_by_users.id', '=', 'mrsl.user_id')
                    ->whereColumn('mrsl.material_request_id', 'material_requests.id')
                    ->where('mrsl.status', MaterialRequest::STATUS_APPROVED)
                    ->orderByDesc('mrsl.id')
                    ->limit(1)
                    ->select('approved_by_users.name');
            }, 'approved_by_name')
            // Who put the request into its CURRENT status, and when (from the audit log).
            // Delivery progress for the badge next to the status: items Procurement dealt with, and how many are ticked.
            ->selectSub(DB::table('material_request_items as dli')->whereColumn('dli.material_request_id', 'material_requests.id')->whereNull('dli.deleted_at')->whereNotNull('dli.procedure')->selectRaw('COUNT(*)'), 'delivery_total')
            ->selectSub(DB::table('material_request_items as dlf')->whereColumn('dlf.material_request_id', 'material_requests.id')->whereNull('dlf.deleted_at')->where('dlf.delivery_status', 'Fully Delivered')->selectRaw('COUNT(*)'), 'delivery_full')
            ->selectSub(DB::table('material_request_items as dla')->whereColumn('dla.material_request_id', 'material_requests.id')->whereNull('dla.deleted_at')->whereNotNull('dla.delivery_status')->selectRaw('COUNT(*)'), 'delivery_any')
            ->selectSub(function ($subQuery) {
                $subQuery->from('material_request_status_logs as mrsl')
                    ->join('users as status_users', 'status_users.id', '=', 'mrsl.user_id')
                    ->whereColumn('mrsl.material_request_id', 'material_requests.id')
                    ->whereColumn('mrsl.status', 'material_requests.status')
                    ->whereNull('mrsl.deleted_at')
                    ->orderByDesc('mrsl.id')
                    ->limit(1)
                    ->select('status_users.name');
            }, 'status_by_name')
            ->selectSub(function ($subQuery) {
                $subQuery->from('material_request_status_logs as mrsl')
                    ->whereColumn('mrsl.material_request_id', 'material_requests.id')
                    ->whereColumn('mrsl.status', 'material_requests.status')
                    ->whereNull('mrsl.deleted_at')
                    ->orderByDesc('mrsl.id')
                    ->limit(1)
                    ->select('mrsl.created_at');
            }, 'status_at')
            ->with(['warehouse:id,name', 'user:id,name', 'clearedBy:id,name']);

        MaterialRequestVisibility::scopeQueryFor($query, $user);

        $materialRequests = $query->filter($filters)->orderByDesc('id')->paginate()->withQueryString();

        // Every status a request on this page has been through: who did it and when (latest entry per status).
        $trail = ['Pending Approval', 'Approved', 'Rejected', 'Partially Issued', 'Issued', 'Delivery Update', 'Partially Delivered', 'Fully Delivered', 'Cleared', 'Closed'];
        $logsByRequest = \App\Models\MaterialRequestStatusLog::whereIn('material_request_id', $materialRequests->pluck('id'))
            ->whereIn('status', $trail)
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->groupBy('material_request_id');
        $materialRequests->getCollection()->each(function ($m) use ($logsByRequest, $trail) {
            $latest = ($logsByRequest[$m->id] ?? collect())->groupBy('status')->map->last();
            $m->setAttribute('history', collect($trail)
                ->filter(fn ($status) => isset($latest[$status]))
                ->map(fn ($status) => [
                    'status' => $status,
                    'by' => optional($latest[$status]->user)->name,
                    'at' => optional($latest[$status]->created_at)->toDateTimeString(),
                ])
                ->sortBy('at')
                ->values()
                ->all());
        });

        // One grouped count over the same visibility scope as the list (filters intentionally ignored).
        $statusCounts = MaterialRequestVisibility::scopeQueryFor(MaterialRequest::query(), $user)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('MaterialRequest/Index', [
            'stats' => [
                'total' => (int) $statusCounts->sum(),
                'pending' => (int) ($statusCounts[MaterialRequest::STATUS_PENDING_APPROVAL] ?? 0),
                'approved' => (int) ($statusCounts[MaterialRequest::STATUS_APPROVED] ?? 0),
                'rejected' => (int) ($statusCounts[MaterialRequest::STATUS_REJECTED] ?? 0),
                'partially_issued' => (int) ($statusCounts[MaterialRequest::STATUS_PARTIALLY_ISSUED] ?? 0),
                'issued' => (int) ($statusCounts[MaterialRequest::STATUS_ISSUED] ?? 0),
                'cleared' => (int) ($statusCounts[MaterialRequest::STATUS_CLEARED] ?? 0),
            ],
            'filters' => $filters,
            'statuses' => MaterialRequest::filterStatuses(),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'completed_at']),
            // Everyone who has ever actually submitted a request in this
            // account — not hardcoded to a role, since the Create step's
            // role assignment is configurable and would drift from this list.
            'requesters' => User::whereIn('id', MaterialRequest::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
                ->orderBy('name')
                ->get(['id', 'name']),
            'canCreate' => $user->can('create', MaterialRequest::class),
            'ownRequestsOnly' => MaterialRequestVisibility::isOwnOnly($user),
            'materialRequests' => new MaterialRequestCollection($materialRequests),
            // Super Admin: every request that Admin Force Clearance can still reach (id => reference), so "select all" can go past this page and the dialog can name them.
            'clearable' => $user->hasRole('Super Admin')
                ? MaterialRequest::query()->whereNotIn('status', self::FORCE_CLEAR_EXCLUDED)->orderBy('id')->limit(1000)->pluck('reference', 'id')->all()
                : (object) [],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', MaterialRequest::class);

        $user = $request->user();
        $isSiteEngineer = $user->hasRole(User::SITE_ENGINEER_ROLES);

        $warehouses = $isSiteEngineer
            ? $user->warehouses()->where('active', 1)->visible()->orderBy('name')->get(['warehouses.id', 'warehouses.name', 'warehouses.completed_at'])
            : Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'completed_at']);

        return Inertia::render('MaterialRequest/Form', [
            'edit' => null,
            'statuses' => MaterialRequest::statuses(),
            'warehouses' => $warehouses,
            'items' => Item::ofAccount()->orderBy('name')->get(['id', 'name', 'code', 'unit_id']),
            'units' => \App\Models\Unit::orderBy('name')->get(['id', 'name', 'code']),
            'requestNumber' => $this->nextReference(),
            'requestedBy' => $request->user()->only(['id', 'name']),
            'dateRequested' => now()->format('Y-m-d'),
        ]);
    }

    public function store(MaterialRequestStoreRequest $request)
    {
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseCompleted($data['warehouse_id'] ?? null)) {
            return $response;
        }
        $data['user_id'] = $request->user()->id;
        $data['status'] = MaterialRequest::STATUS_PENDING_APPROVAL;

        $materialRequest = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $data['reference'] = $this->nextReference();

            try {
                $materialRequest = new MaterialRequest();
                $materialRequest->fill($data)->save();
                break;
            } catch (QueryException $e) {
                $isDuplicateReference = (string) $e->getCode() === '23000'
                    && str_contains($e->getMessage(), 'material_requests_reference_unique');

                if (! $isDuplicateReference || $attempt === 2) {
                    throw $e;
                }
            }
        }

        if (! $materialRequest) {
            abort(500, 'Unable to generate a unique material request reference.');
        }

        $materialRequest->saveAttachments($request->file('attachments'));

        foreach ($data['items'] as $item) {
            $materialRequest->items()->create($item);
        }

        $materialRequest->logs()->create([
            'status' => $materialRequest->status,
            'remarks' => 'Request submitted.',
            'user_id' => $request->user()->id,
        ]);

        $this->notifyAdminsOfMaterialRequestAction($materialRequest, 'created', $request->user()->name);
        $this->notifyApproversOfNewRequest($materialRequest, $request->user()->name);
        app(MaterialRequestMessenger::class)->dispatch($materialRequest, MaterialRequestNotificationRule::EVENT_CREATED, $request->user());

        return redirect()->route('material-requests.index')->with('message', __choice('action_text', ['record' => 'Material Request', 'action' => 'created']));
    }

    public function show(Request $request, MaterialRequest $material_request)
    {
        $this->authorize('view', $material_request);

        $material_request->load([
            'warehouse:id,name,code,address,phone,email',
            'user:id,name,username',
            'clearedBy:id,name',
            'items.item:id,name,code,symbology,unit_id',
            'items.unit:id,name,code',
            'items.procedureContact:id,name',
            'items.procedureWarehouse:id,name',
            'items.deliveredBy:id,name',
            'logs.user:id,name',
            'attachments',
        ]);

        $itemIds = $material_request->items
            ->pluck('item_id')
            ->filter()
            ->unique()
            ->values();

        $availableStockByItem = [];
        if ($itemIds->isNotEmpty()) {
            $rows = DB::table('stocks')
                ->join('warehouses', 'stocks.warehouse_id', '=', 'warehouses.id')
                ->where('stocks.account_id', getAccountId(1))
                ->whereIn('stocks.item_id', $itemIds)
                ->whereNull('stocks.variation_id')
                ->where('stocks.quantity', '>', 0)
                ->where('warehouses.active', 1)
                ->select(
                    'stocks.item_id',
                    'warehouses.id as warehouse_id',
                    'warehouses.name as warehouse_name',
                    'warehouses.code as warehouse_code',
                    'warehouses.status as warehouse_category',
                    'stocks.quantity'
                )
                ->orderBy('warehouses.status')
                ->orderBy('warehouses.name')
                ->get();

            $availableStockByItem = $rows
                ->groupBy('item_id')
                ->map(function ($itemRows) {
                    return $itemRows
                        ->groupBy(fn ($row) => $row->warehouse_category ?: 'Uncategorized')
                        ->map(function ($categoryRows, $categoryName) {
                            return [
                                'category' => (string) $categoryName,
                                'warehouses' => collect($categoryRows)->map(fn ($row) => [
                                    'id' => (int) $row->warehouse_id,
                                    'name' => (string) $row->warehouse_name,
                                    'code' => $row->warehouse_code,
                                    'quantity' => (float) $row->quantity,
                                ])->values()->all(),
                            ];
                        })
                        ->values()
                        ->all();
                })
                ->all();
        }

        if ($request->boolean('json')) {
            return $material_request;
        }

        $canSetProcedures = app(MaterialRequestPolicy::class)->setProcedures($request->user(), $material_request);

        // Transfers made for this request: what is still to move (grouped by source warehouse) and the transfers already made
        $mrTransfers = \App\Services\MaterialRequestTransfers::allows($material_request) || $material_request->transfersMade()->exists()
            ? ['can_create' => \App\Services\MaterialRequestTransfers::allows($material_request) && $request->user()->can('create-transfers'), 'groups' => \App\Services\MaterialRequestTransfers::allows($material_request) ? \App\Services\MaterialRequestTransfers::groups($material_request) : [], 'made' => \App\Services\MaterialRequestTransfers::transfers($material_request), 'by_item' => (object) \App\Services\MaterialRequestTransfers::byItem($material_request->id), 'lines' => collect(\App\Services\MaterialRequestTransfers::lines($material_request))->filter(fn ($l) => $l['has_transfer'])->values()->all(), 'can_open' => $request->user()->can('read-transfers')]
            : null;

        return Inertia::render('MaterialRequest/Show', [
            'mrTransfers' => $mrTransfers,
            'materialRequest' => new MaterialRequestResource($material_request),
            'statuses' => MaterialRequest::statuses(),
            'availableStockByItem' => $availableStockByItem,
            // Only Procurement (who set the procedures) needs these pick-lists.
            'supplierOptions' => $canSetProcedures
                ? Contact::where('category', 'Suppliers')->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->values()
                : [],
            // "Main Warehouse" procedure: only warehouses whose category is Main Warehouse(s).
            'mainWarehouseOptions' => $canSetProcedures
                ? Warehouse::ofAccount()->active()->whereIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses'])->orderBy('name')->get(['id', 'name'])->map(fn ($w) => ['value' => $w->id, 'label' => $w->name])->values()
                : [],
            'siteOptions' => $canSetProcedures
                ? Warehouse::ofAccount()->active()->orderBy('name')->get(['id', 'name'])->map(fn ($w) => ['value' => $w->id, 'label' => $w->name])->values()
                : [],
        ]);
    }

    /** "Create Transfers" from a Material Request: the user says how many of each Main Warehouse item to move; one DRAFT transfer is made per source warehouse. */
    public function createTransfers(Request $request, MaterialRequest $material_request)
    {
        $this->authorize('view', $material_request);
        abort_unless($request->user()->can('create-transfers'), 403);
        $data = $request->validate(['quantities' => 'required|array', 'quantities.*' => 'nullable']);
        $made = \App\Services\MaterialRequestTransfers::createDrafts($material_request, $data['quantities'], $request->user());
        log_activity('Material Request transfers created', ['reference' => $material_request->reference, 'transfers' => collect($made)->pluck('reference')->all()], $material_request, 'Material Request');

        return back()->with('message', trans_choice(':n draft transfer created (:refs). Stock is checked when you turn it into a normal transfer.|:n draft transfers created (:refs). Stock is checked when you turn each into a normal transfer.', count($made), ['n' => count($made), 'refs' => collect($made)->pluck('reference')->implode(', ')]));
    }

    public function edit(Request $request, MaterialRequest $material_request)
    {
        $this->authorize('update', $material_request);
        if ($response = $this->rejectIfWarehouseCompleted($material_request->warehouse_id)) {
            return $response;
        }

        $material_request->load(['items', 'attachments']);

        $warehouses = $request->user()->warehouses()
            ->where('active', 1)
            ->visible()
            ->orderBy('name')
            ->get(['warehouses.id', 'warehouses.name', 'warehouses.completed_at']);

        if ($material_request->warehouse_id && ! $warehouses->contains('id', $material_request->warehouse_id)) {
            $selectedWarehouse = Warehouse::ofAccount()
                ->whereKey($material_request->warehouse_id)
                ->get(['id', 'name', 'completed_at']);

            $warehouses = $warehouses
                ->concat($selectedWarehouse)
                ->unique('id')
                ->sortBy('name')
                ->values();
        }

        return Inertia::render('MaterialRequest/Form', [
            'edit' => new MaterialRequestResource($material_request),
            'statuses' => MaterialRequest::statuses(),
            'warehouses' => $warehouses,
            'items' => Item::ofAccount()->orderBy('name')->get(['id', 'name', 'code', 'unit_id']),
            'units' => \App\Models\Unit::orderBy('name')->get(['id', 'name', 'code']),
            'requestNumber' => $material_request->reference,
            'requestedBy' => $request->user()->only(['id', 'name']),
            'dateRequested' => $material_request->date_requested?->format('Y-m-d'),
        ]);
    }

    public function update(MaterialRequestUpdateRequest $request, MaterialRequest $material_request)
    {
        $this->authorize('update', $material_request);
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseCompleted($material_request->warehouse_id, $data['warehouse_id'] ?? null)) {
            return $response;
        }

        $before = [
            'warehouse_id' => $material_request->warehouse_id,
            'date_requested' => $material_request->date_requested?->format('Y-m-d'),
            'reason' => $material_request->reason,
            'comments' => $material_request->comments,
        ];
        $beforeItems = $material_request->items()->get(['item_id', 'quantity', 'priority'])->keyBy('item_id');

        $material_request->update([
            'warehouse_id' => $data['warehouse_id'],
            'date_requested' => $data['date_requested'],
            'reason' => $data['reason'],
            'comments' => $data['comments'] ?? null,
        ]);

        $material_request->items()->delete();
        foreach ($data['items'] as $item) {
            $material_request->items()->create($item);
        }

        $material_request->saveAttachments($request->file('attachments'));

        $changeSummary = $this->describeMaterialRequestChanges($before, $beforeItems, $data);

        $material_request->logs()->create([
            'status' => $material_request->status,
            'remarks' => $changeSummary,
            'user_id' => $request->user()->id,
        ]);

        $this->notifyAdminsOfMaterialRequestAction($material_request, 'updated', $request->user()->name);
        app(MaterialRequestMessenger::class)->dispatch($material_request, MaterialRequestNotificationRule::EVENT_UPDATED, $request->user(), $changeSummary);

        return redirect()->route('material-requests.show', $material_request->id)->with('message', __choice('action_text', ['record' => 'Material Request', 'action' => 'updated']));
    }

    protected function describeMaterialRequestChanges(array $before, \Illuminate\Support\Collection $beforeItems, array $after): string
    {
        $changes = [];

        if ((int) $before['warehouse_id'] !== (int) $after['warehouse_id']) {
            $oldName = optional(Warehouse::find($before['warehouse_id']))->name ?? $before['warehouse_id'];
            $newName = optional(Warehouse::find($after['warehouse_id']))->name ?? $after['warehouse_id'];
            $changes[] = "Warehouse/Site: {$oldName} → {$newName}";
        }

        if ($before['date_requested'] !== $after['date_requested']) {
            $changes[] = "Date Requested: {$before['date_requested']} → {$after['date_requested']}";
        }

        if ($before['reason'] !== $after['reason']) {
            $changes[] = 'Reason updated';
        }

        if (($before['comments'] ?? null) !== ($after['comments'] ?? null)) {
            $changes[] = 'Comments updated';
        }

        $afterItemsById = collect($after['items'])->keyBy(fn ($item) => (int) $item['item_id']);
        $beforeItemIds = $beforeItems->keys()->map(fn ($id) => (int) $id);
        $afterItemIds = $afterItemsById->keys();

        $itemNames = Item::whereIn('id', $afterItemIds->merge($beforeItemIds)->unique())->pluck('name', 'id');

        foreach ($afterItemIds->diff($beforeItemIds) as $id) {
            $new = $afterItemsById[$id];
            $changes[] = 'Added item "' . ($itemNames[$id] ?? $id) . '" (qty ' . $new['quantity'] . ')';
        }

        foreach ($beforeItemIds->diff($afterItemIds) as $id) {
            $changes[] = 'Removed item "' . ($itemNames[$id] ?? $id) . '"';
        }

        foreach ($afterItemIds->intersect($beforeItemIds) as $id) {
            $old = $beforeItems[$id];
            $new = $afterItemsById[$id];
            $itemChanges = [];

            if ((float) $old->quantity !== (float) $new['quantity']) {
                $itemChanges[] = 'quantity ' . $old->quantity . ' → ' . $new['quantity'];
            }

            if ($old->priority !== $new['priority']) {
                $itemChanges[] = 'priority ' . $old->priority . ' → ' . $new['priority'];
            }

            if ($itemChanges) {
                $changes[] = '"' . ($itemNames[$id] ?? $id) . '" ' . implode(', ', $itemChanges);
            }
        }

        if (empty($changes)) {
            return 'Request details updated (no field changes detected).';
        }

        return 'Request updated: ' . implode('; ', $changes) . '.';
    }

    public function destroy(Request $request, MaterialRequest $material_request)
    {
        $this->authorize('delete', $material_request);
        if ($response = $this->rejectIfWarehouseCompleted($material_request->warehouse_id)) {
            return $response;
        }

        $reference = $material_request->reference;
        $actorName = $request->user()->name;

        $material_request->delete();

        $this->notifyAdminsOfMaterialRequestAction($material_request, 'deleted', $actorName, $reference);
        app(MaterialRequestMessenger::class)->dispatch($material_request, MaterialRequestNotificationRule::EVENT_DELETED, $request->user());

        return redirect()->route('material-requests.index')->with('message', __choice('action_text', ['record' => 'Material Request', 'action' => 'deleted']));
    }

    public function updateStatus(MaterialRequestStatusRequest $request, MaterialRequest $material_request)
    {
        $targetStatus = $request->input('status');
        $this->authorize('changeStatus', [$material_request, $targetStatus]);
        if ($response = $this->rejectIfWarehouseCompleted($material_request->warehouse_id)) {
            return $response;
        }
        $data = $request->validated();
        $user = $request->user();

        // Issuing needs the procurement procedures to be in place: at least one for "Partially Issued", every item for "Fully Issued".
        if (in_array($targetStatus, [MaterialRequest::STATUS_PARTIALLY_ISSUED, MaterialRequest::STATUS_ISSUED], true)) {
            $total = $material_request->items()->count();
            $withProcedure = $material_request->items()->whereNotNull('procedure')->count();
            if ($withProcedure === 0) {
                return back()->withErrors(['status' => __('Set a procurement procedure on at least one item before marking the request as issued.')]);
            }
            if ($targetStatus === MaterialRequest::STATUS_ISSUED && $withProcedure < $total) {
                return back()->withErrors(['status' => __('Every item needs a procurement procedure before the request can be Fully Issued (:done of :total set). Use Partially Issued for now.', ['done' => $withProcedure, 'total' => $total])]);
            }
        }

        // Clear / Complete: how strictly to check that Site has received everything (a Super Admin setting).
        $clearNote = null;
        if ($targetStatus === MaterialRequest::STATUS_CLEARED) {
            $undelivered = $material_request->undeliveredItems();
            if ($undelivered) {
                $mode = MaterialRequestWorkflowStep::clearDeliveryMode(getAccountId(1));
                if ($mode === 'block') {
                    return back()->withErrors(['status' => __('This request cannot be cleared yet: :n item(s) are not Fully Delivered on site.', ['n' => count($undelivered)]) . ' ' . implode('; ', $undelivered)]);
                }
                if ($mode === 'warn' && ! $request->boolean('confirm_undelivered')) {
                    return back()->withErrors(['undelivered' => json_encode($undelivered)]);
                }
                $clearNote = 'Cleared with ' . count($undelivered) . ' item(s) not fully delivered: ' . implode('; ', $undelivered) . '.';
            }
        }

        // Signatories: when the document setup requires a signature and blocks finalising, refuse to clear / complete without it (nothing changes with the default setup).
        if ($targetStatus === MaterialRequest::STATUS_CLEARED
            && ($signBlock = \App\Services\Signatories\Documents\Support\RefusesWithoutSignatures::message('material_request', app(\App\Services\Signatories\Documents\MaterialRequestDocument::class)->asIfCleared($material_request, (int) $user->id), __('cleared')))) {
            return back()->withErrors(['status' => $signBlock]);
        }

        $blocked = null;
        DB::transaction(function () use ($material_request, $targetStatus, $data, $user, &$blocked, $clearNote) {
            $locked = MaterialRequest::whereKey($material_request->id)->lockForUpdate()->first();
            // Re-check against the fresh row: someone else may have moved it a second ago.
            if (! $locked || ! in_array($targetStatus, app(MaterialRequestPolicy::class)->allowedTargetStatuses($user, $locked), true)) {
                $blocked = __('This request has already moved on, so that action is no longer available. Please refresh.');

                return;
            }

            $update = ['status' => $targetStatus];
            if (in_array($targetStatus, [MaterialRequest::STATUS_APPROVED, MaterialRequest::STATUS_REJECTED], true)) {
                $update['approval_remarks'] = $data['remarks'] ?? null;
            }
            if ($targetStatus === MaterialRequest::STATUS_CLEARED) {
                $update['cleared_by_user_id'] = $user->id;
                $update['cleared_at'] = now();
            }
            $locked->update($update);

            $locked->logs()->create([
                'status' => $targetStatus,
                'remarks' => trim(($data['remarks'] ?? '') . ($clearNote ? ' ' . $clearNote : '')) ?: null,
                'user_id' => $user->id,
            ]);
        });

        if ($blocked) {
            return back()->withErrors(['status' => $blocked]);
        }

        $fresh = $material_request->fresh(['user', 'warehouse']);
        $this->notifyAdminsOfMaterialRequestAction($fresh, 'status_changed', $user->name);

        app(MaterialRequestMessenger::class)->dispatch($fresh, MaterialRequestNotificationRule::EVENT_STATUS_CHANGED, $user, $data['remarks'] ?? null);
        event(new MaterialRequestStatusChanged($fresh, (string) ($data['remarks'] ?? '')));

        return back()->with('message', __('Material Request status has been updated.'));
    }

    /**
     * Admin Force Clearance: a Super Admin clears requests (any status except Rejected, Cleared and Closed) in one step, jumping over approval, issuing, delivery and the normal clearing checks.
     * The request becomes an ordinary Cleared request (locked, cleared by / at, status log) and is flagged force_cleared so the lists show it in orange.
     * The Super Admin's own password is checked here. No notifications are sent: this is a back-office clean-up, not a progress event.
     */
    /** Statuses Admin Force Clearance never touches. */
    private const FORCE_CLEAR_EXCLUDED = [MaterialRequest::STATUS_REJECTED, MaterialRequest::STATUS_CLEARED, 'Closed'];

    public function forceClear(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->hasRole('Super Admin'), 403);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:1000'], 'ids.*' => ['integer'], 'password' => ['required', 'string']]);
        if (! \Illuminate\Support\Facades\Hash::check($data['password'], (string) $user->password)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['password' => __('That password is incorrect.')]);
        }

        $done = 0;
        $skipped = 0;
        foreach (array_chunk(array_unique($data['ids']), 100) as $chunk) {
            DB::transaction(function () use ($chunk, $user, &$done, &$skipped) {
                foreach (MaterialRequest::query()->whereIn('id', $chunk)->lockForUpdate()->get() as $mr) {
                    $warehouse = Warehouse::query()->find($mr->warehouse_id);
                    if (in_array($mr->status, self::FORCE_CLEAR_EXCLUDED, true) || ($warehouse && $warehouse->completed_at)) {
                        $skipped++;
                        continue;
                    }
                    $was = $mr->status;
                    $mr->forceFill(['status' => MaterialRequest::STATUS_CLEARED, 'cleared_by_user_id' => $user->id, 'cleared_at' => now(), 'force_cleared' => true])->save();
                    $mr->logs()->create([
                        'status' => MaterialRequest::STATUS_CLEARED,
                        'remarks' => 'Admin Force Clearance: cleared from ' . $was . ' in one step, skipping the approval, issue and delivery steps and the normal clearing checks.',
                        'user_id' => $user->id,
                    ]);
                    log_activity('Admin Force Clearance', ['reference' => $mr->reference, 'from' => $was, 'to' => MaterialRequest::STATUS_CLEARED], $mr, 'Material Request');
                    $done++;
                }
            });
        }

        $msg = __(':n request(s) cleared by Admin Force Clearance.', ['n' => $done]);
        if ($skipped) {
            $msg .= ' ' . __(':n skipped (already Rejected, Cleared or Closed, or its project is completed).', ['n' => $skipped]);
        }

        return $done ? back()->with('message', $msg) : back()->with('error', $msg);
    }

    /**
     * Procurement: set the procedure of each item (and, depending on it, the supplier, the project / warehouse the
     * stock comes from, or a free-text note). Items can be saved in batches — only rows that carry a procedure
     * are touched. Setting procedures no longer "clears" the request; clearing is the final Super Admin step.
     */
    public function updateProcedures(Request $request, MaterialRequest $material_request)
    {
        $this->authorize('view', $material_request);

        $user = $request->user();
        abort_unless(app(MaterialRequestPolicy::class)->setProcedures($user, $material_request), 403);
        if ($response = $this->rejectIfWarehouseCompleted($material_request->warehouse_id)) {
            return $response;
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.procedure' => ['nullable', 'string', 'in:' . implode(',', MaterialRequest::procedures())],
            'items.*.contact_id' => ['nullable', 'integer'],
            'items.*.warehouse_id' => ['nullable', 'integer'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $itemsById = $material_request->items()->get()->keyBy('id');
        $supplierIds = Contact::where('category', 'Suppliers')->pluck('id')->all();
        $warehouseIds = Warehouse::ofAccount()->active()->pluck('id')->all();
        $mainWarehouseIds = Warehouse::ofAccount()->active()->whereIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses'])->pluck('id')->all();
        $errors = [];
        $rows = [];
        $stockWarnings = [];

        foreach ($data['items'] as $index => $row) {
            $procedure = $row['procedure'] ?? null;
            if (! $procedure) {
                continue; // untouched
            }
            $item = $itemsById->get((int) $row['id']);
            if (! $item) {
                $errors["items.$index.procedure"] = __('Invalid material request item.');
                continue;
            }

            $contactId = null;
            $warehouseId = null;
            $note = null;

            if (in_array($procedure, [MaterialRequest::PROCEDURE_SUPPLIER_PROCURE, MaterialRequest::PROCEDURE_SUPPLIER_CREDIT], true)) {
                $contactId = (int) ($row['contact_id'] ?? 0);
                if (! in_array($contactId, $supplierIds, true)) {
                    $errors["items.$index.contact_id"] = __('Choose the supplier for this item.');
                }
            } elseif ($procedure === MaterialRequest::PROCEDURE_TRANSFER_FROM_SITE) {
                $warehouseId = (int) ($row['warehouse_id'] ?? 0);
                if (! in_array($warehouseId, $warehouseIds, true)) {
                    $errors["items.$index.warehouse_id"] = __('Choose the project / warehouse the items come from.');
                }
            } elseif ($procedure === MaterialRequest::PROCEDURE_MAIN_WAREHOUSE) {
                $warehouseId = (int) ($row['warehouse_id'] ?? 0);
                if (! in_array($warehouseId, $mainWarehouseIds, true)) {
                    $errors["items.$index.warehouse_id"] = __('Choose the Main Warehouse the items come from.');
                } else {
                    // Main Warehouse chosen: the quantity is validated against what that warehouse holds now. None at all is refused; less than asked is
                    // allowed (a Partially Issued request moves what there is) but the user is told.
                    $held = (float) DB::table('stocks')->where('warehouse_id', $warehouseId)->where('item_id', $item->item_id)->whereNull('variation_id')->whereNull('deleted_at')->sum('quantity');
                    $name = optional($item->item)->name ?? '#' . $item->item_id;
                    $whName = optional(Warehouse::withoutGlobalScopes()->find($warehouseId))->name;
                    $fmt = fn ($v) => rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0';
                    if ($held <= 0.00005) {
                        $errors["items.$index.warehouse_id"] = __(':item has no stock in :w (:n requested). Choose a warehouse that holds it.', ['item' => $name, 'w' => $whName, 'n' => $fmt((float) $item->quantity)]);
                    } elseif ($held + 0.00005 < (float) $item->quantity) {
                        $stockWarnings[] = __(':item: :w holds :h of the :n requested.', ['item' => $name, 'w' => $whName, 'h' => $fmt($held), 'n' => $fmt((float) $item->quantity)]);
                    }
                }
            } elseif ($procedure === MaterialRequest::PROCEDURE_OTHER) {
                $note = trim((string) ($row['note'] ?? ''));
                if ($note === '') {
                    $errors["items.$index.note"] = __('Describe the procedure for this item.');
                }
            }

            $rows[] = [$item, $procedure, $contactId ?: null, $warehouseId ?: null, $note];
        }

        if (! count($rows) && ! $errors) {
            $errors['items'] = __('Select a procedure for at least one item.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($rows, $material_request, $user) {
            foreach ($rows as [$item, $procedure, $contactId, $warehouseId, $note]) {
                $item->update([
                    'procedure' => $procedure,
                    'procedure_contact_id' => $contactId,
                    'procedure_warehouse_id' => $warehouseId,
                    'procedure_note' => $note,
                ]);
            }

            $material_request->logs()->create([
                'status' => 'Update Procedure',
                'remarks' => 'Procurement procedures saved by ' . $user->name . ' (' . count($rows) . ' item' . (count($rows) === 1 ? '' : 's') . ').',
                'user_id' => $user->id,
            ]);
        });

        return back()->with('message', __('Procurement procedures have been saved.') . ($stockWarnings ? ' ' . implode(' ', $stockWarnings) : ''));
    }

    /**
     * Site Engineer: record what has arrived — each item with a procedure is ticked Partially or Fully Delivered —
     * plus a remark, and save. Can be repeated whenever Procurement updates the request (Partially / Fully Issued);
     * locked for everybody once the request is Cleared. Every save is logged and notified like a status change.
     */
    public function confirmDelivery(Request $request, MaterialRequest $material_request)
    {
        $this->authorize('view', $material_request);

        $user = $request->user();
        abort_unless(app(MaterialRequestPolicy::class)->confirmDelivery($user, $material_request), 403);
        if ($response = $this->rejectIfWarehouseCompleted($material_request->warehouse_id)) {
            return $response;
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.delivery_status' => ['nullable', 'string', 'in:' . implode(',', MaterialRequest::deliveryStatuses())],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $items = $material_request->items()->whereNotNull('procedure')->get()->keyBy('id');
        $changed = 0;
        $nothing = false;

        DB::transaction(function () use ($data, $items, $material_request, $user, &$changed, &$nothing) {
            foreach ($data['items'] as $row) {
                $item = $items->get((int) $row['id']);
                if (! $item) {
                    continue; // not deliverable (no procedure yet)
                }
                $new = $row['delivery_status'] ?? null;
                if ($item->delivery_status === $new) {
                    continue;
                }
                $item->update([
                    'delivery_status' => $new,
                    'delivered_by_user_id' => $new ? $user->id : null,
                    'delivered_at' => $new ? now() : null,
                ]);
                $changed++;
            }

            $remarks = trim((string) ($data['remarks'] ?? ''));
            if ($changed === 0 && $remarks === '') {
                $nothing = true;

                return;
            }
            $all = $material_request->items()->whereNotNull('procedure')->get();
            $summary = sprintf(
                'Fully delivered: %d, partially delivered: %d, awaiting: %d of %d item(s).',
                $all->where('delivery_status', MaterialRequest::DELIVERY_FULL)->count(),
                $all->where('delivery_status', MaterialRequest::DELIVERY_PARTIAL)->count(),
                $all->whereNull('delivery_status')->count(),
                $all->count()
            );

            // The history says which kind of update this was: everything delivered, something delivered, or just a remark.
            $logStatus = $all->every(fn ($i) => $i->delivery_status === MaterialRequest::DELIVERY_FULL)
                ? MaterialRequest::DELIVERY_FULL
                : ($all->whereNotNull('delivery_status')->isNotEmpty() ? MaterialRequest::DELIVERY_PARTIAL : 'Delivery Update');

            $material_request->logs()->create([
                'status' => $logStatus,
                'remarks' => $summary . ($remarks !== '' ? ' ' . $remarks : ''),
                'user_id' => $user->id,
            ]);
        });

        if ($nothing) {
            return back()->withErrors(['items' => __('Tick at least one item or write a remark before saving.')]);
        }

        $fresh = $material_request->fresh(['user', 'warehouse']);
        $this->notifyEvent($fresh, MaterialRequestNotificationRule::EVENT_DELIVERY, 'Delivery Confirmed on Site', $fresh->reference . ': ' . $user->name . ' updated the delivery status on site.', $user->id, $data['remarks'] ?? null);
        app(MaterialRequestMessenger::class)->dispatch($fresh, MaterialRequestNotificationRule::EVENT_DELIVERY, $user, $data['remarks'] ?? null, (string) $fresh->logs()->max('id'));

        return back()->with('message', __('Delivery status saved.'));
    }

    public function print(Request $request, MaterialRequest $material_request)
    {
        $this->authorize('print', $material_request);
        $payload = $this->printPayload($material_request, false);

        return view('material-request-print', $payload);
    }

    public function pdf(Request $request, MaterialRequest $material_request)
    {
        $this->authorize('downloadPdf', $material_request);
        $payload = $this->printPayload($material_request, true);

        $pdf = Pdf::loadView('material-request-print', $payload)->setPaper('a4', 'portrait');

        return $pdf->download('material-request-' . $material_request->reference . '.pdf');
    }

    protected function printPayload(MaterialRequest $materialRequest, bool $isPdf = false): array
    {
        $materialRequest->load([
            'warehouse:id,name,code,address,phone,email',
            'user:id,name,username',
            'clearedBy:id,name',
            'items.item:id,name,code',
            'items.unit:id,name,code',
            'items.procedureContact:id,name',
            'items.procedureWarehouse:id,name',
            'logs.user:id,name',
        ]);

        // "Final Approval By" on the signature block — the most recent status
        // log that actually moved this request to Approved, not necessarily
        // the current status (a request can move past Approved into
        // Issued/Closed and this should still name who approved it).
        $approvalLog = $materialRequest->logs->where('status', MaterialRequest::STATUS_APPROVED)->last();

        return [
            'materialRequest' => $materialRequest,
            'isPdf' => $isPdf,
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => auth()->user()->name,
            'approvedByName' => optional($approvalLog?->user)->name,
            'approvedAt' => optional($approvalLog?->created_at)->format('Y-m-d H:i'),
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('material_request', $materialRequest),
        ];
    }

    protected function nextReference(): string
    {
        // Query the raw table (not Eloquent) so soft-deleted records are included and their numbers are never reused.
        $maxSuffix = (int) DB::table('material_requests')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', 'RMR-%')
            ->value('max_suffix');

        return 'RMR-' . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * $action is also the MaterialRequestNotificationRule event_key for
     * created/updated/deleted/status_changed — configured on the Material Request
     * Notification page (Who Gets Notified).
     */
    protected function notifyAdminsOfMaterialRequestAction(MaterialRequest $materialRequest, string $action, string $actorName, ?string $referenceOverride = null): void
    {
        $reference = $referenceOverride ?: $materialRequest->reference;

        $messages = [
            'created' => $reference . ' submitted by ' . $actorName . '.',
            'updated' => $reference . ' updated by ' . $actorName . '.',
            'deleted' => $reference . ' deleted by ' . $actorName . '.',
            'status_changed' => $reference . ' status changed to ' . $materialRequest->status . ' by ' . $actorName . '.',
        ];

        $recipients = MaterialRequestNotificationRule::recipientsFor(getAccountId(1), $action, $materialRequest, (int) $materialRequest->user_id);

        foreach ($recipients as $recipient) {
            $recipient->notify(new AppNotification([
                'module' => 'material_requests',
                'type' => 'material_request_' . $action,
                'title' => 'Material Request Notification',
                'message' => $messages[$action] ?? ($reference . ' updated.'),
                'status' => $materialRequest->status,
                'reference' => $reference,
                'url' => route('material-requests.show', $materialRequest->id),
                'created_at' => now()->toDateTimeString(),
            ]));
        }
    }

    /** Whoever holds the (softcoded) Approve step is told a request is waiting for their decision. */
    protected function notifyApproversOfNewRequest(MaterialRequest $materialRequest, string $actorName): void
    {
        $alreadyTold = MaterialRequestNotificationRule::recipientsFor(getAccountId(1), MaterialRequestNotificationRule::EVENT_CREATED, $materialRequest, (int) $materialRequest->user_id)->pluck('id');

        MaterialRequestWorkflowStep::assignedUsers(getAccountId(1), MaterialRequestWorkflowStep::STEP_APPROVE)
            ->reject(fn ($u) => (int) $u->id === (int) $materialRequest->user_id || $alreadyTold->contains($u->id))
            ->each(fn ($approver) => $approver->notify(new AppNotification([
                'module' => 'material_requests',
                'type' => 'material_request_awaiting_approval',
                'title' => 'Material Request Awaiting Approval',
                'message' => $materialRequest->reference . ' was submitted by ' . $actorName . ' and is waiting for your approval.',
                'status' => $materialRequest->status,
                'reference' => $materialRequest->reference,
                'url' => route('material-requests.show', $materialRequest->id),
                'created_at' => now()->toDateTimeString(),
            ])));
    }

    /** Sends the bell notification for one rule-driven lifecycle event to everyone that event's rule says should hear it. */
    protected function notifyEvent(MaterialRequest $materialRequest, string $eventKey, string $title, string $message, ?int $excludeUserId = null, ?string $remarks = null): void
    {
        $materialRequest->loadMissing('user:id,name', 'warehouse:id,name');

        $recipients = MaterialRequestNotificationRule::recipientsFor(getAccountId(1), $eventKey, $materialRequest, $excludeUserId);

        foreach ($recipients as $recipient) {
            $recipient->notify(new AppNotification([
                'module' => 'material_requests',
                'type' => 'material_request_' . $eventKey,
                'title' => $title,
                'message' => $message,
                'status' => $materialRequest->status,
                'reference' => $materialRequest->reference,
                'remarks' => $remarks,
                'url' => route('material-requests.show', $materialRequest->id),
                'created_at' => now()->toDateTimeString(),
            ]));
        }
    }

    protected function lifecycleDetails(MaterialRequest $mr, ?string $remarks = null): array
    {
        return MaterialRequestMessenger::details($mr, $remarks);
    }
}
