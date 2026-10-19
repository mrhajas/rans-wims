<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Warehouse;
use App\Models\Adjustment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Actions\Tec\PrepareOrder;
use App\Http\Requests\AdjustmentRequest;
use App\Http\Resources\AdjustmentResource;
use App\Http\Resources\AdjustmentCollection;
use Illuminate\Support\Facades\Auth;
use App\Traits\GuardsCompletedWarehouse;
use App\Traits\HasActivityHistory;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Support\ApproverRole;
use App\Services\AdjustmentStatusService;

class AdjustmentController extends Controller
{
    use GuardsCompletedWarehouse;
    use HasActivityHistory;

    public function create()
    {
        return Inertia::render('Adjustment/Form', [
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(),
        ]);
    }

    public function destroy(Adjustment $adjustment)
    {
        if ($response = $this->rejectIfWarehouseCompleted($adjustment->warehouse_id)) {
            return $response;
        }
        $adjustment->load(['items.item', 'items.unit', 'items.variations']);
        if ($adjustment->del()) {
            event(new \App\Events\AdjustmentEvent($adjustment, 'deleted'));

            return redirect()->route('adjustments.index')->with('message', __choice('action_text', ['record' => 'Adjustment', 'action' => 'deleted']));
        }

        return redirect()->route('adjustments.index')->with('error', __('The record can not be deleted.'));
    }

    public function destroyPermanently(Adjustment $adjustment)
    {
        if ($response = $this->rejectIfWarehouseCompleted($adjustment->warehouse_id)) {
            return $response;
        }
        $adjustment->load(['items.item', 'items.unit', 'items.variations']);
        if ($adjustment->delP()) {
            $event = new \App\Events\AdjustmentEvent($adjustment, 'deleted');
            $event->permanent = true;
            event($event);

            return redirect()->route('adjustments.index')->with('message', __choice('action_text', ['record' => 'Adjustment', 'action' => 'permanently deleted']));
        }

        return redirect()->route('adjustments.index')->with('error', __('The record can not be deleted.'));
    }

    public function edit(Adjustment $adjustment)
    {
        $this->authorize('update', $adjustment);
        abort_unless($this->canModifyAdjustment(), 403, 'Only Admin or Procurement Manager can modify adjustments.');
        if ($response = $this->rejectIfWarehouseCompleted($adjustment->warehouse_id)) {
            return $response;
        }

        if (! $this->isAdjustmentAdmin() && (int) $adjustment->draft !== 1) {
            return redirect()->route('adjustments.index')->with('error', 'This adjustment is already approved and cannot be edited by Procurement Manager. Please contact Admin to make changes.');
        }

        if ($adjustment->rejected_at && ! $this->isSuperAdmin()) {
            return redirect()->route('adjustments.show', $adjustment->id)->with('error', __('This adjustment was rejected and cannot be edited. Create a new adjustment instead.'));
        }

        return Inertia::render('Adjustment/Form', [
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(),
            'edit'       => new AdjustmentResource($adjustment->load(['items.variations', 'items.item.variations', 'attachments', 'user:id,name', 'approver:id,name', 'rejecter:id,name'])),
            'history'    => $this->activityHistory(Adjustment::class, $adjustment->id),
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->all('draft', 'status', 'search', 'trashed', 'view');
        // The summary tiles above the table filter the list (same definitions as the tile counts).
        $filters['view'] = in_array($filters['view'] ?? null, ['today', 'month', 'pending', 'rejected', 'additions', 'subtractions', 'damages'], true) ? $filters['view'] : null;

        return Inertia::render('Adjustment/Index', [
            'filters'     => $filters,
            'stats'       => $this->indexStats(),
            'adjustments' => new AdjustmentCollection(
                Adjustment::with(['warehouse:id,name', 'user:id,name', 'approver:id,name', 'rejecter:id,name'])->filter($filters)
                    ->when($filters['status'] ?? null, fn ($q, $status) => $q->status($status))
                    ->when($filters['view'], fn ($q, $view) => match ($view) {
                        'today' => $q->where('created_at', '>=', now()->startOfDay()->toDateTimeString()),
                        'month' => $q->where('created_at', '>=', now()->startOfMonth()->toDateTimeString()),
                        'pending' => $q->status(Adjustment::STATUS_PENDING),
                        'rejected' => $q->status(Adjustment::STATUS_REJECTED),
                        'additions' => $q->where('type', 'Addition'),
                        'subtractions' => $q->where('type', 'Subtraction'),
                        'damages' => $q->where('type', 'Damage'),
                    })
                    ->when(warehouse_scope_ids() !== null, fn ($q) => $q->withoutGlobalScope('mine')->whereIn('warehouse_id', warehouse_scope_ids()))
                    ->orderByDesc('id')->paginate()->withQueryString()
            ),
        ]);
    }

    /**
     * Summary tiles for the index page. Same visibility/scope rules as index()
     * (account + "mine" global scopes, warehouse scope override, trashed rows
     * excluded); counts come from one aggregate query.
     */
    private function indexStats(): array
    {
        $base = Adjustment::query()
            ->when(warehouse_scope_ids() !== null, fn ($q) => $q->withoutGlobalScope('mine')->whereIn('warehouse_id', warehouse_scope_ids()));

        $startOfDay = now()->startOfDay()->toDateTimeString();
        $startOfMonth = now()->startOfMonth()->toDateTimeString();

        $row = (clone $base)->toBase()->selectRaw(
            'COUNT(*) as total, COALESCE(SUM(created_at >= ?), 0) as today, COALESCE(SUM(created_at >= ?), 0) as month, COALESCE(SUM(draft = 1 AND rejected_at IS NULL), 0) as drafts, COALESCE(SUM(rejected_at IS NOT NULL), 0) as rejected, COALESCE(SUM(type = \'Addition\'), 0) as additions, COALESCE(SUM(type = \'Subtraction\'), 0) as subtractions, COALESCE(SUM(type = \'Damage\'), 0) as damages',
            [$startOfDay, $startOfMonth]
        )->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'today' => (int) ($row->today ?? 0),
            'month' => (int) ($row->month ?? 0),
            'drafts' => (int) ($row->drafts ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
            'additions' => (int) ($row->additions ?? 0),
            'subtractions' => (int) ($row->subtractions ?? 0),
            'damages' => (int) ($row->damages ?? 0),
        ];
    }

    public function restore(Adjustment $adjustment)
    {
        abort_unless(auth()->user()->can('delete-adjustments'), 403);

        $adjustment->restore();
        $adjustment->items->each->restore();
        event(new \App\Events\AdjustmentEvent($adjustment, 'restored'));

        return back()->with('message', __choice('action_text', ['record' => 'Adjustment', 'action' => 'restored']));
    }

    public function show(Request $request, Adjustment $adjustment)
    {
        $adjustment->load(['items.variations', 'items.item:id,code,name,track_quantity,track_weight', 'warehouse', 'items.unit:id,code,name', 'user:id,name,username', 'approver:id,name', 'rejecter:id,name', 'attachments']);

        return $request->json ? array_merge($adjustment->toArray(), [
            'status' => $adjustment->status,
            'can_approve' => $adjustment->isPending() && ! $adjustment->deleted_at && Adjustment::userCanApprove($request->user()),
            'can_change_status' => ! $adjustment->deleted_at && $request->user()->hasRole('Super Admin'),
            'approval_locked' => $adjustment->approvalLocked(),
            'approval_locked_message' => $adjustment->approvalLocked() ? Adjustment::reversalBlockedMessage() : null,
        ]) : Inertia::render('Adjustment/Show', [
            'adjustment' => new AdjustmentResource($adjustment),
            'history' => $this->activityHistory(Adjustment::class, $adjustment->id),
        ]);
    }

    public function print(Adjustment $adjustment)
    {
        $this->authorize('print-adjustments');
        $adjustment->load(['items.item:id,code,name,price', 'items.unit:id,code,name', 'warehouse', 'user:id,name', 'approver:id,name', 'rejecter:id,name']);

        return view('adjustments.print', $this->printData($adjustment, false));
    }

    public function pdf(Adjustment $adjustment)
    {
        $this->authorize('print-adjustments');
        $adjustment->load(['items.item:id,code,name,price', 'items.unit:id,code,name', 'warehouse', 'user:id,name', 'approver:id,name', 'rejecter:id,name']);

        return Pdf::loadView('adjustments.print', $this->printData($adjustment, true))
            ->setPaper('a4')
            ->download('adjustment-' . $adjustment->reference . '.pdf');
    }

    private function printData(Adjustment $adjustment, bool $isPdf): array
    {
        return [
            'adjustment' => $adjustment,
            'companyName' => optional(auth()->user()->account)->name,
            'qr' => PrintQrCode::dataUri(route('adjustments.show', $adjustment->id)),
            'isPdf' => $isPdf,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('adjustment', $adjustment),
        ];
    }

    public function store(AdjustmentRequest $request)
    {
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseCompleted($data['warehouse_id'] ?? null)) {
            return $response;
        }

        $adjustment = \App\Support\ReferenceLock::run('adjustments', function () use ($data, $request) {
            $maxSuffix = (int) DB::table('adjustments')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', 'RSA-%')
            ->value('max_suffix');

        $data['reference'] = 'RSA-' . str_pad($maxSuffix + 1, 4, '0', STR_PAD_LEFT);
        // All new adjustments start as draft and can only be approved by Admin/Super Admin.
        $data['draft'] = true;
        $data['approved_by'] = null;
        $data['approved_at'] = null;


            return (new PrepareOrder($data, $request->file('attachments'), new Adjustment()))->process()->save();
        });
        event(new \App\Events\AdjustmentEvent($adjustment, 'created'));

        return redirect()->route('adjustments.index')->with('message', __choice('action_text', ['record' => 'Adjustment', 'action' => 'created']));
    }

    public function update(AdjustmentRequest $request, Adjustment $adjustment)
    {
        $this->authorize('update', $adjustment);
        abort_unless($this->canModifyAdjustment(), 403, 'Only Admin or Procurement Manager can modify adjustments.');

        if (! $this->isAdjustmentAdmin() && (int) $adjustment->draft !== 1) {
            return redirect()->route('adjustments.index')->with('error', 'This adjustment is already approved and cannot be edited by Procurement Manager. Please contact Admin to make changes.');
        }

        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseCompleted($adjustment->warehouse_id, $data['warehouse_id'] ?? null)) {
            return $response;
        }

        if ($adjustment->rejected_at && ! $this->isSuperAdmin()) {
            return redirect()->route('adjustments.show', $adjustment->id)->with('error', __('This adjustment was rejected and cannot be edited. Create a new adjustment instead.'));
        }

        // Editing never approves: approval is its own action (approve() below), so the stored state is kept as it is.
        $data['draft'] = (int) $adjustment->draft === 1;
        unset($data['approved_by'], $data['approved_at'], $data['approved_by_role'], $data['rejected_by'], $data['rejected_at'], $data['rejection_reason']);

        $original = $adjustment->load(['items.item', 'items.unit', 'items.variations'])->replicate();
        $adjustment = (new PrepareOrder($data, $request->file('attachments'), $adjustment))->process()->save();
        event(new \App\Events\AdjustmentEvent($adjustment, 'updated', $original));
        session()->flash('message', __choice('action_text', ['record' => 'Adjustment', 'action' => 'updated']));

        return $request->listing == 'yes' ? redirect()->route('adjustments.index') : back();
    }

    /**
     * Approve a pending adjustment: stamps who/when/in which role, takes it out of draft and lets AdjustmentEvent('updated')
     * apply the stock and notify the person who made it. Locked and re-checked so a double click (or an approve racing a
     * reject) can never apply the stock twice.
     */
    public function approve(Request $request, Adjustment $adjustment)
    {
        abort_unless(Adjustment::userCanApprove($request->user()), 403, __('You are not allowed to approve adjustments.'));
        if ($response = $this->rejectIfWarehouseCompleted($adjustment->warehouse_id)) {
            return $response;
        }

        // Signatories: refuse the final step when an administrator made a signature required and it is missing (inert until switched on).
        if ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('adjustment', $adjustment, ['draft' => false, 'approved_by' => $request->user()->id, 'approved_at' => now(), 'rejected_by' => null, 'rejected_at' => null], __('approved'))) {
            return back()->with('error', $refusal);
        }

        $original = DB::transaction(function () use ($request, $adjustment) {
            $locked = $adjustment->newQueryWithoutScopes()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isPending(), 422, __('This adjustment is no longer pending approval.'));

            $adjustment->load(['items.item', 'items.unit', 'items.variations']);
            $before = $adjustment->replicate();
            $adjustment->forceFill([
                'draft' => false,
                'approved_by' => $request->user()->id,
                'approved_by_role' => ApproverRole::for($request->user()),
                'approved_at' => now(),
            ])->save();
            // The lines carry their own draft flag (stock reports read it), so they leave draft together with the header.
            $adjustment->items()->update(['draft' => false]);

            return $before;
        });

        event(new \App\Events\AdjustmentEvent($adjustment, 'updated', $original));
        log_activity('Adjustment Approved', ['reference' => $adjustment->reference, 'warehouse_id' => $adjustment->warehouse_id, 'type' => $adjustment->type], $adjustment, 'Adjustment');

        return back()->with('message', __choice('action_text', ['record' => 'Adjustment', 'action' => 'approved']));
    }

    /**
     * Super Admin only: put any adjustment into any status (e.g. re-approve a rejected one, or take an approved one back to
     * Pending / Rejected). Stock is reversed or applied by AdjustmentStatusService, the same way an edit would do it.
     */
    public function changeStatus(Request $request, Adjustment $adjustment, AdjustmentStatusService $statuses)
    {
        abort_unless($this->isSuperAdmin(), 403, __('Only a Super Admin can change the status of an adjustment.'));
        $data = $request->validate([
            'status' => 'required|in:' . implode(',', Adjustment::statuses()),
            'rejection_reason' => 'required_if:status,' . Adjustment::STATUS_REJECTED . '|nullable|string|min:3|max:1000',
        ]);
        if ($response = $this->rejectIfWarehouseCompleted($adjustment->warehouse_id)) {
            return $response;
        }

        $statuses->change($adjustment, $data['status'], $request->user(), $data['rejection_reason'] ?? null);

        return back()->with('message', __('Adjustment status changed to :status.', ['status' => __($data['status'])]));
    }

    /** Reject a pending adjustment with a reason. It stays a draft (stock is never touched) and its creator is told why. */
    public function reject(Request $request, Adjustment $adjustment)
    {
        abort_unless(Adjustment::userCanApprove($request->user()), 403, __('You are not allowed to reject adjustments.'));
        $data = $request->validate(['rejection_reason' => 'required|string|min:3|max:1000']);

        DB::transaction(function () use ($request, $adjustment, $data) {
            $locked = $adjustment->newQueryWithoutScopes()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isPending(), 422, __('This adjustment is no longer pending approval.'));

            $adjustment->forceFill([
                'rejected_by' => $request->user()->id,
                'rejected_at' => now(),
                'rejection_reason' => trim($data['rejection_reason']),
            ])->save();
        });

        $adjustment->load(['items.item', 'items.unit', 'warehouse', 'user', 'rejecter']);
        event(new \App\Events\AdjustmentEvent($adjustment, 'rejected'));
        log_activity('Adjustment Rejected', ['reference' => $adjustment->reference, 'warehouse_id' => $adjustment->warehouse_id, 'type' => $adjustment->type, 'reason' => $adjustment->rejection_reason], $adjustment, 'Adjustment');

        return back()->with('message', __choice('action_text', ['record' => 'Adjustment', 'action' => 'rejected']));
    }

    private function isSuperAdmin(): bool
    {
        return (bool) Auth::user() && Auth::user()->hasRole('Super Admin');
    }

    private function isAdjustmentAdmin(): bool
    {
        $user = Auth::user();

        return (bool) $user && ($user->hasRole('Admin') || $user->hasRole('Super Admin'));
    }

    private function canModifyAdjustment(): bool
    {
        $user = Auth::user();

        return (bool) $user && $user->hasRole(['Super Admin', 'Admin', 'Procurement Manager', 'Procurement Manger']);
    }
}
