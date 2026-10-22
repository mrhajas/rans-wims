<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Carbon\Carbon;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Actions\Tec\PrepareOrder;
use App\Actions\Tec\CleanupWarehouse;
use App\Http\Requests\TransferRequest;
use App\Http\Resources\TransferResource;
use App\Http\Resources\TransferCollection;
use App\Services\WimsConnect\CommunicationService;
use App\Traits\GuardsCompletedWarehouse;
use App\Traits\HasActivityHistory;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;

class TransferController extends Controller
{
    use GuardsCompletedWarehouse;
    use HasActivityHistory;

    public function create(Request $request)
    {
        // "Create transfer" from a Material Request: the form opens with the request's remaining items (see MaterialRequestTransfers)
        $prefill = null;
        if ($request->filled('material_request_id')) {
            $mr = \App\Models\MaterialRequest::query()->find((int) $request->query('material_request_id'));
            abort_unless($mr && $request->user()->can('view', $mr), 404);
            if (! \App\Services\MaterialRequestTransfers::allows($mr)) {
                return redirect()->route('material-requests.show', $mr->id)->with('error', __('A transfer cannot be made for a Material Request that is :s.', ['s' => strtolower(__($mr->status))]));
            }
            $p = \App\Services\MaterialRequestTransfers::prefill($mr, $request->filled('from_warehouse_id') ? (int) $request->query('from_warehouse_id') : null);
            if (! $p['items'] && ! $p['skipped']) {
                return redirect()->route('material-requests.show', $mr->id)->with('message', __('Every item of :r is already on a transfer.', ['r' => $mr->reference]));
            }
            $prefill = $p + ['material_request' => ['id' => $mr->id, 'reference' => $mr->reference], 'to_warehouse_id' => (int) $mr->warehouse_id, 'details' => __('For Material Request :r', ['r' => $mr->reference])];
        }

        return Inertia::render('Transfer/Form', [
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(),
            'prefill' => $prefill,
        ]);
    }

    public function destroy(Transfer $transfer)
    {
        if ($response = $this->rejectIfWarehouseBlocked($transfer->from_warehouse_id, $transfer->to_warehouse_id)) {
            return $response;
        }
        $transfer->load(['items.item', 'items.unit', 'items.variations']);
        if ($transfer->del()) {
            event(new \App\Events\TransferEvent($transfer, 'deleted'));

            return redirect()->route('transfers.index')->with('message', __choice('action_text', ['record' => 'Transfer', 'action' => 'deleted']));
        }

        return redirect()->route('transfers.index')->with('error', __('The record can not be deleted.'));
    }

    public function destroyPermanently(Transfer $transfer)
    {
        if ($response = $this->rejectIfWarehouseBlocked($transfer->from_warehouse_id, $transfer->to_warehouse_id)) {
            return $response;
        }
        $transfer->load(['items.item', 'items.unit', 'items.variations']);
        if ($transfer->delP()) {
            $event = new \App\Events\TransferEvent($transfer, 'deleted');
            $event->permanent = true;
            event($event);

            return redirect()->route('transfers.index')->with('message', __choice('action_text', ['record' => 'Transfer', 'action' => 'permanently deleted']));
        }

        return redirect()->route('transfers.index')->with('error', __('The record can not be deleted.'));
    }

    public function edit(Transfer $transfer)
    {
        $this->authorize('update', $transfer);
        if ($response = $this->denyExpiredSiteEngineerEdit($transfer)) {
            return $response;
        }
        if ($response = $this->rejectIfWarehouseBlocked($transfer->from_warehouse_id, $transfer->to_warehouse_id)) {
            return $response;
        }

        return Inertia::render('Transfer/Form', [
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(),
            'edit'       => new TransferResource($transfer->load(['materialRequest:id,reference,status', 'items.variations', 'items.item.variations', 'attachments', 'receivedBy:id,name', 'fromWarehouse:id,name,status', 'toWarehouse:id,name', 'approver:id,name', 'canceller:id,name'])),
            'history'    => $this->activityHistory(Transfer::class, $transfer->id),
            'deliveryNote' => \App\Http\Controllers\DeliveryNoteController::stateFor($transfer, \App\Models\DeliveryNote::SOURCE_TRANSFER),
            'gatePass' => \App\Http\Controllers\GatePassController::stateFor($transfer, \App\Models\GatePass::SOURCE_TRANSFER),
            'transportRoute' => \App\Services\Transport\TransferRoute::stateFor($transfer),
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->all('draft', 'search', 'trashed', 'view');
        $filters['view'] = in_array($filters['view'] ?? null, ['today', 'month', 'pending', 'received', 'awaiting', 'drafts'], true) ? $filters['view'] : null;

        $transfers = Transfer::with(['fromWarehouse:id,name,status', 'toWarehouse:id,name', 'user:id,name', 'receivedBy:id,name', 'approver:id,name', 'canceller:id,name'])->filter($filters)
            ->when($filters['view'], fn ($q, $view) => $this->applyTile($q, $view))
            ->when(warehouse_scope_ids() !== null, fn ($q) => $q->withoutGlobalScope('mine')->where(fn ($w) => $w->whereIn('from_warehouse_id', warehouse_scope_ids())->orWhereIn('to_warehouse_id', warehouse_scope_ids())))
            ->orderByDesc('id')->paginate()->withQueryString();

        return Inertia::render('Transfer/Index', [
            'filters'    => $filters,
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(),
            'transfers'  => new TransferCollection($transfers),
            'stats'      => $this->indexStats(),
            'deliveryNotes' => \App\Http\Controllers\DeliveryNoteController::statesFor($transfers->items(), \App\Models\DeliveryNote::SOURCE_TRANSFER),
            'gatePasses' => $gatePasses = \App\Http\Controllers\GatePassController::statesFor($transfers->items(), \App\Models\GatePass::SOURCE_TRANSFER),
            'transportRoutes' => \App\Services\Transport\TransferRoute::statesFor($transfers->items(), $gatePasses),
        ]);
    }

    /**
     * Summary tiles for the index page. Same visibility/scope rules as index()
     * (account + "mine" global scopes, warehouse scope override, trashed rows
     * excluded); counts come from one aggregate query.
     */
    /** Narrows the list to what a summary tile counts (same conditions as indexStats()). */
    private function applyTile($query, string $view)
    {
        return match ($view) {
            'today' => $query->where('created_at', '>=', now()->startOfDay()->toDateTimeString()),
            'month' => $query->where('created_at', '>=', now()->startOfMonth()->toDateTimeString()),
            'drafts' => $query->whereRaw("draft = 1 AND approval_status NOT IN ('pending', 'cancelled')"),
            'received' => $query->whereRaw('received_at IS NOT NULL AND (draft IS NULL OR draft = 0)'),
            'pending' => $query->whereRaw('received_at IS NULL AND (draft IS NULL OR draft = 0)'),
            'awaiting' => $query->where('approval_status', 'pending'),
            default => $query,
        };
    }

    private function indexStats(): array
    {
        $base = Transfer::query()
            ->when(warehouse_scope_ids() !== null, fn ($q) => $q->withoutGlobalScope('mine')->where(fn ($w) => $w->whereIn('from_warehouse_id', warehouse_scope_ids())->orWhereIn('to_warehouse_id', warehouse_scope_ids())));

        $startOfDay = now()->startOfDay()->toDateTimeString();
        $startOfMonth = now()->startOfMonth()->toDateTimeString();

        $row = (clone $base)->toBase()->selectRaw(
            'COUNT(*) as total, COALESCE(SUM(created_at >= ?), 0) as today, COALESCE(SUM(created_at >= ?), 0) as month, COALESCE(SUM(draft = 1 AND approval_status NOT IN (\'pending\', \'cancelled\')), 0) as drafts, COALESCE(SUM(received_at IS NOT NULL AND (draft IS NULL OR draft = 0)), 0) as received, COALESCE(SUM(received_at IS NULL AND (draft IS NULL OR draft = 0)), 0) as pending, COALESCE(SUM(approval_status = \'pending\'), 0) as awaiting',
            [$startOfDay, $startOfMonth]
        )->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'today' => (int) ($row->today ?? 0),
            'month' => (int) ($row->month ?? 0),
            'drafts' => (int) ($row->drafts ?? 0),
            'received' => (int) ($row->received ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'awaiting' => (int) ($row->awaiting ?? 0),
        ];
    }

    public function restore(Transfer $transfer)
    {
        abort_unless(auth()->user()->can('delete-transfers'), 403);
        if ($problem = \App\Services\MaterialRequestTransfers::restoreProblem($transfer)) {
            return back()->with('error', $problem);
        }

        $transfer->restore();
        $transfer->items->each->restore();
        event(new \App\Events\TransferEvent($transfer, 'restored'));

        return back()->with('message', __choice('action_text', ['record' => 'Transfer', 'action' => 'restored']));
    }

    public function show(Request $request, Transfer $transfer)
    {
        $itemId = $request->input('item_id');

        $transfer->load([
            'fromWarehouse',
            'toWarehouse',
            'user:id,name,username',
            'receivedBy:id,name',
            'approver:id,name',
            'canceller:id,name',
            'attachments',
            'items' => function ($query) use ($itemId) {
                if ($itemId) {
                    $query->where('item_id', $itemId); // Filter items if item_id is provided
                }
            },
            'items.variations',
            'items.item:id,code,name,track_quantity,track_weight,price',
            'items.unit:id,code,name'
        ]);

        return $request->json
            ? $transfer
            : Inertia::render('Transfer/Show', [
                'transfer' => new TransferResource($transfer),
                'history' => $this->activityHistory(Transfer::class, $transfer->id),
                'deliveryNote' => \App\Http\Controllers\DeliveryNoteController::stateFor($transfer, \App\Models\DeliveryNote::SOURCE_TRANSFER),
                'gatePass' => \App\Http\Controllers\GatePassController::stateFor($transfer, \App\Models\GatePass::SOURCE_TRANSFER),
                'transportRoute' => \App\Services\Transport\TransferRoute::stateFor($transfer),
            ]);

        // $transfer->load(['items.variations', 'items.item:id,code,name,track_quantity,track_weight,price', 'fromWarehouse', 'toWarehouse', 'items.unit:id,code,name', 'user:id,name:username', 'attachments']);
    }

    public function print(Transfer $transfer)
    {
        $this->authorize('print-transfers');
        $transfer->load(['items.item:id,code,name,price', 'items.unit:id,code,name', 'fromWarehouse', 'toWarehouse', 'user:id,name', 'receivedBy:id,name']);

        return view('transfers.print', $this->printData($transfer, false));
    }

    public function pdf(Transfer $transfer)
    {
        $this->authorize('print-transfers');
        $transfer->load(['items.item:id,code,name,price', 'items.unit:id,code,name', 'fromWarehouse', 'toWarehouse', 'user:id,name', 'receivedBy:id,name']);

        return Pdf::loadView('transfers.print', $this->printData($transfer, true))
            ->setPaper('a4')
            ->download('transfer-' . $transfer->reference . '.pdf');
    }

    private function printData(Transfer $transfer, bool $isPdf): array
    {
        return [
            'transfer' => $transfer,
            'companyName' => optional(auth()->user()->account)->name,
            'qr' => PrintQrCode::dataUri(route('transfers.show', $transfer->id)),
            'isPdf' => $isPdf,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('transfer', $transfer),
        ];
    }

    /**
     * 'deliver-transfers' grants the ability, but doesn't by itself grant
     * unrestricted reach — only Super Admin/Admin (the roles the request
     * explicitly named as unrestricted) can mark ANY transfer received;
     * every other role/user holding the permission (Engineers/"Site
     * Engineer" by default, or anyone else it's later assigned to) is
     * limited to transfers they themselves created. Centralized here so
     * receive() and bulkReceive() enforce the exact same rule.
     */
    private function userMayDeliver($user, Transfer $transfer): bool
    {
        if (! $user->can('deliver-transfers')) {
            return false;
        }

        return $user->hasRole(['Super Admin', 'Admin']) || (int) $transfer->user_id === (int) $user->id;
    }

    /**
     * The "Transfer Received" trigger WIMS Connect's analysis doc flagged as
     * missing entirely — Transfer previously had no state past
     * draft/approved. Deliberately its own small endpoint rather than
     * folded into update() (which handles the full edit form): this is a
     * one-way confirmation a destination-side user makes once, not an edit.
     * Not in Authorizable's $abilities map (method name isn't "update" etc.),
     * so it needs its own explicit permission check.
     */
    public function receive(Request $request, Transfer $transfer)
    {
        abort_unless($this->userMayDeliver($request->user(), $transfer), 403);
        if ($response = $this->rejectIfWarehouseBlocked($transfer->from_warehouse_id, $transfer->to_warehouse_id)) {
            return $response;
        }
        abort_if($transfer->draft, 422, 'A draft transfer cannot be marked received.');
        abort_if($transfer->received_at, 422, 'This transfer has already been marked received.');
        // Signatories: refuse the final step when an administrator made a signature required and it is missing (inert until switched on).
        if ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('transfer', $transfer, ['received_at' => now(), 'received_by' => $request->user()->id], __('marked as received'))) {
            return back()->with('error', $refusal);
        }

        $transfer->update([
            'received_at' => now(),
            'received_by' => $request->user()->id,
        ]);

        log_activity('Transfer Received', ['reference' => $transfer->reference, 'received_by' => $request->user()->id], $transfer, 'Transfer');

        $transfer->loadMissing(['fromWarehouse:id,name', 'toWarehouse:id,name']);

        // Transfers workflow page (Who Gets Notified / Messages)
        \App\Services\TransferNotifier::fire('received', $transfer, __('Transfer Received'), $transfer->reference . ' was received at ' . optional($transfer->toWarehouse)->name . ' (confirmed by ' . $request->user()->name . ').', $request->user()->id, ['remarks' => null]);

        \App\Services\MultiTransferNotifier::childChanged($transfer->refresh(), $request->user());

        return back()->with('message', __('Transfer marked as received.'));
        // return $request->json ? $transfer : Inertia::render('Transfer/Show', ['transfer' => new TransferResource($transfer)]);
    }

    /**
     * Bulk counterpart to receive() above — same permission gate
     * ('update-transfers', which Super Admin already always passes) and the
     * exact same per-record guards, just looped. Deliberately does NOT run
     * denyExpiredSiteEngineerEdit() — same as the single-row action above,
     * marking a transfer received is a one-way confirmation, not an edit,
     * so it stays available past the 3-day edit window that only blocks
     * edit()/update(). A transfer that no longer qualifies by the time this
     * runs (already received, now a draft, or its warehouse got completed
     * in the meantime) is silently skipped rather than aborting the whole
     * batch, since a multi-select can easily go stale between page load
     * and submit.
     */
    public function bulkReceive(Request $request)
    {
        // Just the base ability here — a user who holds it but isn't
        // Super Admin/Admin can still reach this endpoint (their own
        // transfers might be among the selected ids); userMayDeliver()
        // below is what actually filters per-transfer.
        abort_unless($request->user()->can('deliver-transfers'), 403);

        $data = $request->validate([
            'transfer_ids' => 'required|array|min:1',
            'transfer_ids.*' => 'required|integer|exists:transfers,id',
        ]);

        $receivedCount = 0;
        $skippedCount = 0;
        $blockedMessages = [];
        $receivedTransfers = [];

        DB::transaction(function () use ($data, $request, &$receivedCount, &$skippedCount, &$blockedMessages, &$receivedTransfers) {
            $transfers = Transfer::whereIn('id', $data['transfer_ids'])->lockForUpdate()->get();

            foreach ($transfers as $transfer) {
                if (
                    ! $this->userMayDeliver($request->user(), $transfer)
                    || $this->rejectIfWarehouseBlocked($transfer->from_warehouse_id, $transfer->to_warehouse_id)
                    || $transfer->draft
                    || $transfer->received_at
                ) {
                    $skippedCount++;

                    continue;
                }

                if ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('transfer', $transfer, ['received_at' => now(), 'received_by' => $request->user()->id], __('marked as received'))) {
                    $skippedCount++;
                    $blockedMessages[$transfer->reference] = $refusal;

                    continue;
                }

                $transfer->update([
                    'received_at' => now(),
                    'received_by' => $request->user()->id,
                ]);

                log_activity('Transfer Received', ['reference' => $transfer->reference, 'received_by' => $request->user()->id], $transfer, 'Transfer');

                $transfer->loadMissing(['fromWarehouse:id,name', 'toWarehouse:id,name']);

                \App\Services\TransferNotifier::fire('received', $transfer, __('Transfer Received'), $transfer->reference . ' was received at ' . optional($transfer->toWarehouse)->name . ' (confirmed by ' . $request->user()->name . ').', $request->user()->id);

                $receivedCount++;
                $receivedTransfers[] = $transfer;
            }
        });
        foreach ($receivedTransfers as $done) {
            \App\Services\MultiTransferNotifier::childChanged($done->refresh(), $request->user());
        }

        $message = __(':count transfer(s) marked as received.', ['count' => $receivedCount]);
        if ($skippedCount) {
            $message .= ' '.__(':count skipped, already received, a draft, or its warehouse is completed.', ['count' => $skippedCount]);
        }
        if ($blockedMessages) {
            $message .= ' '.collect($blockedMessages)->map(fn ($m, $ref) => $ref.': '.$m)->implode(' ');
        }

        return back()->with('message', $message);
    }

    public function store(TransferRequest $request)
    {
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseBlocked($data['from_warehouse_id'] ?? null, $data['to_warehouse_id'] ?? null)) {
            return $response;
        }
        // A transfer leaving a MAIN warehouse is only released by an approver: whatever the form says, it is stored
        // as a draft (no stock effect) marked pending until approve() runs. A genuine draft stays a draft.
        $needsApproval = ! ($data['draft'] ?? false) && Transfer::warehouseNeedsApproval($data['from_warehouse_id'] ?? null);
        if ($needsApproval) {
            $data['draft'] = true;
        }

        $transfer = \App\Support\ReferenceLock::run('transfers', function () use ($data, $request) {
            // checked while the lock is held, so two people cannot both take the last quantity
            if (! empty($data['material_request_id'])) {
                \App\Services\MaterialRequestTransfers::validate((int) $data['material_request_id'], (int) $data['to_warehouse_id'], $data['items'] ?? []);
            }
            $maxSuffix = (int) DB::table('transfers')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', 'RST-%')
            ->value('max_suffix');

        $data['reference'] = 'RST-' . str_pad($maxSuffix + 1, 4, '0', STR_PAD_LEFT);


            return (new PrepareOrder($data, $request->file('attachments'), new Transfer()))->process()->save();
        });
        if ($needsApproval) {
            $transfer->forceFill(['approval_status' => Transfer::APPROVAL_PENDING])->save();
            $this->notifyApprovers($transfer);
        }
        event(new \App\Events\TransferEvent($transfer, 'created'));
        log_activity('Transfer Created', ['reference' => $transfer->reference, 'material_request_id' => $transfer->material_request_id, 'from_warehouse_id' => $transfer->from_warehouse_id, 'to_warehouse_id' => $transfer->to_warehouse_id], $transfer, 'Transfer');

        return redirect()->route('transfers.index')->with('message', $needsApproval
            ? __('Transfer :ref submitted. It leaves a main warehouse, so stock only moves once it is approved.', ['ref' => $transfer->reference])
            : __choice('action_text', ['record' => 'Transfer', 'action' => 'created']));
    }

    /**
     * Releases a transfer that leaves a main warehouse: only now does the stock move (the 'approved' event applies
     * it exactly as a normal transfer would have on creation). Re-checks stock first, since weeks may have passed.
     */
    public function approve(Request $request, Transfer $transfer)
    {
        abort_unless($request->user()->can('approve-transfers'), 403);
        if ($response = $this->rejectIfWarehouseBlocked($transfer->from_warehouse_id, $transfer->to_warehouse_id)) {
            return $response;
        }

        if ($error = \App\Services\TransferApproval::approve($transfer, $request->user())) {
            return back()->with('error', $error);
        }

        return back()->with('message', __('Transfer :ref approved. Stock has been moved.', ['ref' => $transfer->reference]));
    }

    /** Stops a transfer that is still waiting for approval. Nothing was ever moved, so nothing needs undoing. */
    public function cancel(Request $request, Transfer $transfer)
    {
        abort_unless($request->user()->can('cancel-transfers'), 403);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        if ($error = \App\Services\TransferApproval::cancel($transfer, $request->user(), $data['reason'] ?? null)) {
            return back()->with('error', $error);
        }

        return back()->with('message', __('Transfer :ref cancelled.', ['ref' => $transfer->reference]));
    }

    /** Tell everyone holding approve-transfers that a transfer is waiting (the submitter is not told about their own): Transfers workflow page. */
    private function notifyApprovers(Transfer $transfer): void
    {
        \App\Services\TransferNotifier::fire('approval_needed', $transfer, __('Transfer awaiting approval'), $transfer->reference . ': ' . \App\Services\TransferNotifier::route($transfer), auth()->id(), ['skip_actor' => true]);
    }

    /** The creator hears about the outcome (the actor is skipped unless they are the creator): Transfers workflow page. */
    private function notifyRequester(Transfer $transfer, string $outcome, User $actor): void
    {
        $text = $transfer->reference . ' ' . ($outcome === 'approved' ? __('was approved by :name.', ['name' => $actor->name]) : __('was cancelled by :name.', ['name' => $actor->name]));
        \App\Services\TransferNotifier::fire($outcome, $transfer, $outcome === 'approved' ? __('Transfer approved') : __('Transfer cancelled'), $text, $actor->id, ['skip_actor' => true]);
    }

    public function cleanupWarehouse(Request $request)
    {
        abort_unless(auth()->user()->hasRole(['Super Admin', 'Admin']), 403, __('Only administrators can clean up a warehouse.'));

        $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'password' => ['required', 'current_password'],
        ]);

        $warehouse = Warehouse::ofAccount()->findOrFail($request->input('warehouse_id'));
        if ($response = $this->rejectIfWarehouseBlocked($warehouse->id)) {
            return $response;
        }

        $created = (new CleanupWarehouse())($warehouse);

        $this->notifyCleanup($warehouse, $created);

        $message = empty($created)
            ? __('No remaining transferred stock to return for ') . $warehouse->name . '.'
            : __('Cleanup of ') . $warehouse->name . __(' created ') . count($created) . __(' return transfer(s).');

        return back()->with('message', $message);
    }

    public function cleanupPreview(Request $request)
    {
        abort_unless(auth()->user()->hasRole(['Super Admin', 'Admin']), 403, __('Only administrators can preview warehouse cleanup.'));

        $request->validate(['warehouse_id' => 'required|exists:warehouses,id']);

        $warehouse = Warehouse::ofAccount()->findOrFail($request->input('warehouse_id'));

        return response()->json((new CleanupWarehouse())->preview($warehouse));
    }

    public function cleanupDistribute(Request $request)
    {
        abort_unless(auth()->user()->hasRole(['Super Admin', 'Admin']), 403, __('Only administrators can distribute cleanup stock.'));

        $data = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.to_warehouse_id' => 'required|exists:warehouses,id',
        ]);

        $warehouse = Warehouse::ofAccount()->findOrFail($data['warehouse_id']);
        if ($response = $this->rejectIfWarehouseBlocked($warehouse->id)) {
            return $response;
        }

        foreach ($data['items'] as $item) {
            if ((int) $item['to_warehouse_id'] === $warehouse->id) {
                return back()->withErrors(['items' => __('Destination warehouse must be different from the warehouse being cleaned up.')]);
            }
        }

        $created = (new CleanupWarehouse())->createManualTransfers($warehouse, $data['items']);

        $this->notifyCleanup($warehouse, $created);

        $message = empty($created)
            ? __('No transfers were created, the requested items are no longer available in stock.')
            : __('Created ') . count($created) . __(' transfer(s) distributing remaining stock from ') . $warehouse->name . '.';

        return back()->with('message', $message);
    }

    /** Transfers workflow page: "Warehouse Clean-up" (only when the clean-up really created transfers). */
    private function notifyCleanup(Warehouse $warehouse, array $created): void
    {
        if (empty($created)) {
            return;
        }
        $actor = auth()->user();
        \App\Services\Workflow\WorkflowMessenger::dispatch('transfers', 'warehouse_cleanup', $warehouse, $actor, [
            'account_id' => $warehouse->account_id ?? $actor?->account_id ?? 1, 'title' => __('Warehouse Clean-up'),
            'message' => $warehouse->name . ': ' . count($created) . ' transfer(s) were created to return or distribute its remaining stock' . ($actor ? ' (by ' . $actor->name . ')' : '') . '.',
            'url' => route('transfers.index'), 'skip_actor' => true, 'dedupe' => 'cleanup|' . $warehouse->id . '|' . count($created) . '|' . intdiv(time(), 20),
            'values' => ['warehouse' => (string) $warehouse->name, 'count' => (string) count($created)],
            'details' => [__('Warehouse') => $warehouse->name, __('Transfers created') => count($created), __('Done by') => $actor?->name],
        ]);
    }

    public function validatePassword(Request $request)
    {
        abort_unless(auth()->user()->hasRole(['Super Admin', 'Admin']), 403, __('Only administrators can validate warehouse cleanup password.'));

        $request->validate(['password' => 'required|string']);

        $user = auth()->user();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => __('The password you entered is incorrect.')], 422);
        }

        return response()->json(['valid' => true]);
    }

    public function update(TransferRequest $request, Transfer $transfer)
    {
        $this->authorize('update', $transfer);
        if ($response = $this->denyExpiredSiteEngineerEdit($transfer)) {
            return $response;
        }
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseBlocked($transfer->from_warehouse_id, $transfer->to_warehouse_id, $data['from_warehouse_id'] ?? null, $data['to_warehouse_id'] ?? null)) {
            return $response;
        }
        // the request a transfer was made for cannot be changed by editing; its items still obey what the request left to move
        $data['material_request_id'] = $transfer->material_request_id;
        if ($transfer->material_request_id) {
            \App\Services\MaterialRequestTransfers::validate((int) $transfer->material_request_id, (int) $data['to_warehouse_id'], $data['items'] ?? [], $transfer->id);
        }
        if ($transfer->approval_status === Transfer::APPROVAL_CANCELLED) {
            return back()->with('error', __('This transfer was cancelled and can no longer be edited.'));
        }

        // Same rule as store(): from a main warehouse, a final (non-draft) save must be approved before stock moves.
        // An already-approved transfer that keeps the same source warehouse stays approved (its stock is applied).
        $toMain = Transfer::warehouseNeedsApproval($data['from_warehouse_id'] ?? null);
        $stillApproved = $transfer->approval_status === Transfer::APPROVAL_APPROVED && (int) $transfer->from_warehouse_id === (int) ($data['from_warehouse_id'] ?? 0);
        $needsApproval = $toMain && ! ($data['draft'] ?? false) && ! $stillApproved;
        if ($needsApproval) {
            $data['draft'] = true;
        }

        $original = $transfer->load(['items.item', 'items.unit', 'items.variations'])->replicate();
        $transfer = (new PrepareOrder($data, $request->file('attachments'), $transfer))->process()->save();
        if ($needsApproval) {
            $transfer->forceFill(['approval_status' => Transfer::APPROVAL_PENDING, 'approved_by' => null, 'approved_at' => null])->save();
            if ($original->approval_status !== Transfer::APPROVAL_PENDING) {
                $this->notifyApprovers($transfer);
            }
        } elseif ((! $toMain || ($data['draft'] ?? false)) && $transfer->approval_status !== Transfer::APPROVAL_NOT_REQUIRED) {
            // Moved to a non-main source, or pulled back to a plain draft: approval no longer applies; a later
            // final save from a main warehouse will have to be approved again.
            $transfer->forceFill(['approval_status' => Transfer::APPROVAL_NOT_REQUIRED, 'approved_by' => null, 'approved_at' => null])->save();
        }
        event(new \App\Events\TransferEvent($transfer, 'updated', $original));
        log_activity('Transfer Updated', ['reference' => $transfer->reference, 'from_warehouse_id' => $transfer->from_warehouse_id, 'to_warehouse_id' => $transfer->to_warehouse_id], $transfer, 'Transfer');
        session()->flash('message', __choice('action_text', ['record' => 'Transfer', 'action' => 'updated']));

        return $request->listing == 'yes' ? redirect()->route('transfers.index') : back();
    }

    private function denyExpiredSiteEngineerEdit(Transfer $transfer)
    {
        $user = auth()->user();
        if (! $user || ! $user->hasRole([...User::SITE_ENGINEER_ROLES, 'Procurement Manager', 'Procurement Manger', 'Store Manager'])) {
            return null;
        }

        $createdAt = $transfer->created_at ?: Carbon::parse($transfer->date);
        if ($createdAt->lt(now()->subDays(3))) {
            return redirect()->route('transfers.index')->with(
                'error',
                __('You can only edit your transfer within 3 days of creation. This transaction is older than 3 days. Please contact Admin for further help.')
            );
        }

        return null;
    }
}
