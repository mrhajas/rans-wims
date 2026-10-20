<?php

namespace App\Http\Controllers;

use App\Actions\Tec\PrepareOrder;
use App\Events\AdjustmentEvent;
use App\Http\Requests\SuperAdjustmentRequest;
use App\Http\Resources\SuperAdjustmentCollection;
use App\Http\Resources\SuperAdjustmentResource;
use App\Models\Adjustment;
use App\Models\Item;
use App\Models\Stock;
use App\Models\SuperAdjustment;
use App\Models\SuperAdjustmentItem;
use App\Models\Warehouse;
use App\Notifications\AppNotification;
use App\Services\AdjustmentStatusService;
use App\Services\AdminNotificationService;
use App\Support\ApproverRole;
use App\Support\PrintQrCode;
use App\Traits\GuardsCompletedWarehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use App\Models\SuperAdjustmentApproval;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tecdiary\Laravel\Attachments\Attachment;

class SuperAdjustmentController extends Controller
{
    use GuardsCompletedWarehouse;

    /**
     * The base Authorizable::$abilities map auto-checks edit/update/destroy
     * against a permission-only ability, which pre-empts authorizeDraftMutation()'s
     * owner-or-permission logic below — a Draft's own creator would get a
     * 403 before that method ever runs if they lack the broader
     * update/delete-super-adjustments permission. Drop just those three keys
     * so authorizeDraftMutation() actually governs them as intended; every
     * other auto-check keeps Authorizable's own default mapping.
     */
    public function __construct()
    {
        $this->setAbilities([
            'index'               => 'read',
            'show'                => 'read',
            'create'              => 'create',
            'store'               => 'create',
            'destroy-many'        => 'delete',
            'destroy-permanently' => 'delete',
            'restore'             => 'delete',
            'delete'              => 'delete',
            'void'                => 'delete',
            'email'               => 'email',
            'disable2FA'          => 'update',
            'disable2-f-a'        => 'update',
            'disableAccount'      => 'update',
            'disable-account'     => 'update',
            'enableAccount'       => 'update',
            'enable-account'      => 'update',
            'change-password'     => 'update',
            'delete-attachment'   => 'delete',
            'payments'            => 'payments',
        ]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('read-super-adjustments'), 403);

        $filters = $request->only('search', 'status', 'session_id');

        $superAdjustments = SuperAdjustment::with(['warehouse:id,name,code', 'user:id,name', 'approver:id,name', 'rejecter:id,name', 'approvals.user:id,name', 'adjustmentSession:id,reference,status'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->search($s))
            ->when(ctype_digit((string) ($filters['session_id'] ?? '')), fn ($q) => $q->where('adjustment_session_id', (int) $filters['session_id']))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // Whole-list figures for the summary cards (not just the page being shown); the cards filter the list by status.
        $counts = SuperAdjustment::query()->toBase()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $stats = [
            'total' => (int) $counts->sum(),
            'Draft' => (int) ($counts[SuperAdjustment::STATUS_DRAFT] ?? 0),
            'Awaiting' => (int) ($counts[SuperAdjustment::STATUS_AWAITING] ?? 0),
            'Approved' => (int) ($counts[SuperAdjustment::STATUS_APPROVED] ?? 0),
            'Rejected' => (int) ($counts[SuperAdjustment::STATUS_REJECTED] ?? 0),
        ];

        return Inertia::render('SuperAdjustment/Index', [
            'stats' => $stats,
            'filters' => $filters,
            'superAdjustments' => new SuperAdjustmentCollection($superAdjustments),
            'statuses' => SuperAdjustment::statuses(),
            'canCreate' => $request->user()->can('create-super-adjustments'),
            'canSessions' => $request->user()->can('read-adjustment-sessions') && \App\Services\FeatureManager::canAccess('adjustment_sessions', $request->user()),
            'rule' => ['approvals' => SuperAdjustment::requiredApprovals(), 'can_change' => $request->user()->hasRole('Super Admin'), 'super_admins' => \App\Models\User::role('Super Admin')->where('is_active', true)->count()],
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('create-super-adjustments'), 403);

        return Inertia::render('SuperAdjustment/Form', [
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'code', 'status']),
            'today' => now()->format('Y-m-d'),
            'responsibleUser' => $request->user()->only(['id', 'name']),
            'openSessions' => $this->openSessions(),
            'preselectWarehouseId' => ctype_digit((string) $request->query('warehouse_id', '')) ? (int) $request->query('warehouse_id') : null,
        ]);
    }

    public function store(SuperAdjustmentRequest $request)
    {
        abort_unless($request->user()->can('create-super-adjustments'), 403);

        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseCompleted($data['warehouse_id'])) {
            return $response;
        }

        return $this->submitCount($request, $data, $request->file('attachments'));
    }

    /** The Super Adjustment that submitCount() just saved (the Adjustment Session needs it for the count it makes when it ends). */
    public ?SuperAdjustment $submitted = null;

    /**
     * Saves a count and sends it on the normal way (approvers told, a Super Admin's own count approved on the spot under the one-approval rule). Used by the form
     * and by the Adjustment Session for the count that clears the items nobody counted, so both follow the same approval rules.
     */
    public function submitCount(Request $request, array $data, $attachments = null, ?\App\Models\AdjustmentSession $session = null)
    {
        $superAdjustment = $this->persist(new SuperAdjustment(), $data, $attachments, $session);
        $this->submitted = $superAdjustment;

        $this->alert('super_created', $superAdjustment, $request->user(), __('Super Adjustment Created'), $superAdjustment->reference . ' was created by ' . $request->user()->name . ' for ' . optional($superAdjustment->warehouse)->name . '.', ['skip_actor' => true]);

        // A Super Admin is the approver, so a count they submit is approved on the spot (and says so); anyone else's goes
        // to Draft for a Super Admin to Approve or Reject.
        // With the two-approval rule (the default) nobody's own count is approved on the spot: two different Super Admins approve it, each with their password.
        if ($request->user()->hasRole('Super Admin') && SuperAdjustment::requiredApprovals() === 1) {
            if ($refusal = $this->signingRefusal($superAdjustment, $request->user())) {
                return redirect()->route('super-adjustments.show', $superAdjustment->id)->with('error', __('The count was submitted but not approved yet.').' '.$refusal);
            }
            $this->recordApproval($superAdjustment, $request->user(), 1);
            $this->performApproval($superAdjustment, $request->user());

            return redirect()->route('super-adjustments.show', $superAdjustment->id)
                ->with('message', __('Super Adjustment approved by :name (Super Admin) and stock updated.', ['name' => $request->user()->name]));
        }

        $this->notifyApprovers($superAdjustment);

        return redirect()->route('super-adjustments.show', $superAdjustment->id)
            ->with('message', SuperAdjustment::requiredApprovals() === 2
                ? __('Super Adjustment submitted. It needs the approval of two different Super Admins, each with their password, before the stock changes.')
                : __('Super Adjustment submitted for review.'));
    }

    /** The open Adjustment Sessions by warehouse id, so the form can say that a count is added to a session (and skip the "items not counted" question). */
    private function openSessions(): array
    {
        return \App\Models\AdjustmentSession::query()->where('status', \App\Models\AdjustmentSession::STATUS_OPEN)->whereIn('warehouse_id', Warehouse::ofAccount()->visible()->select('id'))->get(['id', 'reference', 'name', 'warehouse_id', 'started_at'])
            ->mapWithKeys(fn ($x) => [(int) $x->warehouse_id => ['id' => $x->id, 'reference' => $x->reference, 'name' => $x->name, 'started_at' => optional($x->started_at)->toIso8601String(), 'url' => route('adjustment-sessions.show', $x->id)]])->all();
    }

    public function edit(SuperAdjustment $super_adjustment)
    {
        $this->authorizeDraftMutation($super_adjustment, 'update-super-adjustments');
        if ($super_adjustment->approvalLocked()) {
            return redirect()->route('super-adjustments.show', $super_adjustment->id)->with('error', Adjustment::reversalBlockedMessage(__('Super Adjustment')));
        }

        $super_adjustment->load(['items', 'attachments']);

        return Inertia::render('SuperAdjustment/Form', [
            'edit' => new SuperAdjustmentResource($super_adjustment),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'code', 'status']),
            'today' => $super_adjustment->date,
            'responsibleUser' => $super_adjustment->user()->first()?->only(['id', 'name']),
            'openSessions' => $this->openSessions(),
            'existingItems' => $super_adjustment->items->load('item:id,name,code,unit_id', 'unit:id,name,code')->map(fn ($i) => [
                'item_id' => $i->item_id,
                'name' => optional($i->item)->name ?? __('(Item removed)'),
                'code' => optional($i->item)->code,
                'unit_id' => $i->unit_id,
                'unit_name' => optional($i->unit)->name,
                'system_quantity' => (float) $i->system_quantity_at_submission,
                'physical_quantity' => (float) $i->physical_quantity,
                'auto_cleared' => (bool) $i->auto_cleared,
            ]),
            // Mapped explicitly (not left to SuperAdjustmentResource's raw
            // whenLoaded('attachments')) because the Attachment model only
            // auto-appends `url`/`url_inline` — `download_url`/`preview_url`/
            // `is_image` don't exist on it, same mapping show() already does.
            'existingAttachments' => $super_adjustment->attachments->map(fn (Attachment $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'filesize' => $a->filesize,
                'is_image' => str_starts_with(strtolower($a->filetype ?? ''), 'image/'),
                'preview_url' => route('super-adjustments.attachments.download', [$super_adjustment->id, $a->id]) . '?disposition=inline',
                'download_url' => route('super-adjustments.attachments.download', [$super_adjustment->id, $a->id]),
                'delete_url' => route('super-adjustments.attachments.destroy', [$super_adjustment->id, $a->id]),
            ])->values(),
        ]);
    }

    public function update(SuperAdjustmentRequest $request, SuperAdjustment $super_adjustment)
    {
        $this->authorizeDraftMutation($super_adjustment, 'update-super-adjustments');
        if ($super_adjustment->approvalLocked()) {
            return redirect()->route('super-adjustments.show', $super_adjustment->id)->with('error', Adjustment::reversalBlockedMessage(__('Super Adjustment')));
        }

        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseCompleted($data['warehouse_id'])) {
            return $response;
        }

        // A Super Admin may edit an Approved / Rejected count: it goes back to Draft (an Approved one first has the stock
        // changes it made reversed) and then waits to be approved again.
        if ($super_adjustment->status !== SuperAdjustment::STATUS_DRAFT) {
            $this->moveToStatus($super_adjustment, SuperAdjustment::STATUS_DRAFT, $request->user(), null, __('Edited by :name', ['name' => $request->user()->name]));
        }

        $this->persist($super_adjustment, $data, $request->file('attachments'));
        $this->alert('super_updated', $super_adjustment, $request->user(), __('Super Adjustment Edited'), $super_adjustment->reference . ' was edited by ' . $request->user()->name . '.', ['skip_actor' => true]);

        return redirect()->route('super-adjustments.show', $super_adjustment->id)
            ->with('message', __('Super Adjustment updated.'));
    }

    public function show(Request $request, SuperAdjustment $super_adjustment)
    {
        abort_unless($request->user()->can('read-super-adjustments'), 403);

        $super_adjustment->load([
            'warehouse:id,name,code',
            'user:id,name',
            'approver:id,name',
            'rejecter:id,name',
            'items.item:id,name,code',
            'items.unit:id,name,code',
            'attachments',
            'additionAdjustment:id,reference',
            'subtractionAdjustment:id,reference',
            'approvals.user:id,name',
        ]);

        $liveStock = Stock::main()->ofWarehouse($super_adjustment->warehouse_id)
            ->whereIn('item_id', $super_adjustment->items->pluck('item_id'))
            ->pluck('quantity', 'item_id');

        $isApproved = $super_adjustment->status === SuperAdjustment::STATUS_APPROVED;
        [$additionQtyByItem, $subtractionQtyByItem] = $this->approvedOutcomeQuantities($super_adjustment);

        $items = $super_adjustment->items->map(function ($i) use ($liveStock, $isApproved, $additionQtyByItem, $subtractionQtyByItem) {
            $liveVariance = $i->varianceAgainst((float) ($liveStock[$i->item_id] ?? 0));
            [$outcome, $outcomeQuantity] = $this->itemOutcome($i->item_id, $liveVariance, $isApproved, $additionQtyByItem, $subtractionQtyByItem);

            return [
                'id' => $i->id,
                'item_id' => $i->item_id,
                'name' => optional($i->item)->name ?? __('(Item removed)'),
                'code' => optional($i->item)->code,
                'unit' => optional($i->unit)->name,
                'system_quantity_at_submission' => (float) $i->system_quantity_at_submission,
                'system_quantity_live' => (float) ($liveStock[$i->item_id] ?? 0),
                'physical_quantity' => (float) $i->physical_quantity,
                'live_variance' => $liveVariance,
                'auto_cleared' => (bool) $i->auto_cleared,
                // Authoritative Match/Addition/Subtraction classification.
                // For an Approved record this is read back from which real
                // Adjustment (if any) the item actually landed in — not
                // recomputed from live stock, which could have moved again
                // since approval and would then disagree with history.
                'outcome' => $outcome,
                'outcome_quantity' => $outcomeQuantity,
            ];
        })->values();

        $attachments = $super_adjustment->attachments->map(fn (Attachment $a) => [
            'id' => $a->id,
            'title' => $a->title,
            'filesize' => $a->filesize,
            'is_image' => str_starts_with(strtolower($a->filetype ?? ''), 'image/'),
            'preview_url' => route('super-adjustments.attachments.download', [$super_adjustment->id, $a->id]) . '?disposition=inline',
            'download_url' => route('super-adjustments.attachments.download', [$super_adjustment->id, $a->id]),
            'delete_url' => route('super-adjustments.attachments.destroy', [$super_adjustment->id, $a->id]),
        ])->values();

        return Inertia::render('SuperAdjustment/Show', [
            'superAdjustment' => new SuperAdjustmentResource($super_adjustment),
            'items' => $items,
            'attachments' => $attachments,
            'approval' => $this->approvalPanel($super_adjustment),
            'session' => $super_adjustment->adjustment_session_id && ($as = \App\Models\AdjustmentSession::find($super_adjustment->adjustment_session_id))
                ? ['id' => $as->id, 'reference' => $as->reference, 'status' => $as->status, 'url' => route('adjustment-sessions.show', $as->id)] : null,
        ]);
    }

    /** Every approval phase of the record for the page and the print: who gave it, in which role, when; the ones still missing; the ones that were voided. */
    private function approvalPanel(SuperAdjustment $sa): array
    {
        $required = SuperAdjustment::requiredApprovals();
        $given = $sa->relationLoaded('approvals') ? $sa->approvals : $sa->approvals()->with('user:id,name')->get();
        $count = max($required, $given->count());
        $phases = collect(range(1, $count))->map(function ($p) use ($given) {
            $a = $given->firstWhere('phase', $p);

            return ['phase' => $p, 'done' => (bool) $a, 'user' => optional(optional($a)->user)->name, 'role' => optional($a)->role, 'at' => optional(optional($a)->approved_at)->toDateTimeString(), 'ip' => optional($a)->ip_address, 'note' => optional($a)->note];
        })->values();
        $history = $sa->approvalHistory()->whereNotNull('voided_at')->with('user:id,name')->get()->map(fn ($a) => ['phase' => $a->phase, 'user' => optional($a->user)->name, 'at' => optional($a->approved_at)->toDateTimeString(), 'voided_at' => optional($a->voided_at)->toDateTimeString(), 'reason' => $a->voided_reason])->values();

        return ['required' => $required, 'phases' => $phases, 'history' => $history, 'complete' => $sa->status === SuperAdjustment::STATUS_APPROVED, 'super_admins' => \App\Models\User::role('Super Admin')->where('is_active', true)->count()];
    }

    public function print(SuperAdjustment $super_adjustment)
    {
        $this->authorize('print-super-adjustments');
        $this->loadForPrint($super_adjustment);

        return view('super-adjustments.print', $this->printData($super_adjustment, false));
    }

    public function pdf(SuperAdjustment $super_adjustment)
    {
        $this->authorize('print-super-adjustments');
        $this->loadForPrint($super_adjustment);

        return Pdf::loadView('super-adjustments.print', $this->printData($super_adjustment, true))
            ->setPaper('a4')
            ->download('super-adjustment-' . $super_adjustment->reference . '.pdf');
    }

    private function loadForPrint(SuperAdjustment $super_adjustment): void
    {
        $super_adjustment->load([
            'items.item:id,code,name',
            'items.unit:id,code,name',
            'warehouse',
            'user:id,name',
            'approver:id,name',
            'rejecter:id,name',
            'additionAdjustment:id,reference',
            'subtractionAdjustment:id,reference',
            'approvals.user:id,name',
        ]);
    }

    private function printData(SuperAdjustment $super_adjustment, bool $isPdf): array
    {
        // Same live-vs-submission-snapshot comparison the Show page renders,
        // so a printed/downloaded report carries the same "stock moved"
        // indicator instead of looking authoritative while silently omitting it.
        $liveStock = Stock::main()->ofWarehouse($super_adjustment->warehouse_id)
            ->whereIn('item_id', $super_adjustment->items->pluck('item_id'))
            ->pluck('quantity', 'item_id');

        $isApproved = $super_adjustment->status === SuperAdjustment::STATUS_APPROVED;
        [$additionQtyByItem, $subtractionQtyByItem] = $this->approvedOutcomeQuantities($super_adjustment);

        // Same authoritative outcome classification show() computes — keyed
        // by item_id so the Blade view can look it up per row without
        // duplicating the live-vs-history branching logic.
        $outcomes = $super_adjustment->items->mapWithKeys(function ($i) use ($liveStock, $isApproved, $additionQtyByItem, $subtractionQtyByItem) {
            $liveVariance = $i->varianceAgainst((float) ($liveStock[$i->item_id] ?? 0));
            [$outcome, $outcomeQuantity] = $this->itemOutcome($i->item_id, $liveVariance, $isApproved, $additionQtyByItem, $subtractionQtyByItem);

            return [$i->id => ['outcome' => $outcome, 'outcome_quantity' => $outcomeQuantity, 'live_variance' => $liveVariance]];
        });

        return [
            'superAdjustment' => $super_adjustment,
            'liveStock' => $liveStock,
            'outcomes' => $outcomes,
            'companyName' => optional(auth()->user()->account)->name,
            'qr' => PrintQrCode::dataUri(route('super-adjustments.show', $super_adjustment->id)),
            'isPdf' => $isPdf,
            'approval' => $this->approvalPanel($super_adjustment),
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('super_adjustment', $super_adjustment),
        ];
    }

    /**
     * The real per-item quantities the generated Addition/Subtraction
     * Adjustment(s) actually carry, keyed by item_id — empty collections for
     * a Draft/Rejected record (nothing has happened yet) or when a bucket
     * was never created. Only queried for an Approved record.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    private function approvedOutcomeQuantities(SuperAdjustment $super_adjustment): array
    {
        if ($super_adjustment->status !== SuperAdjustment::STATUS_APPROVED) {
            return [collect(), collect()];
        }

        $additionQtyByItem = $super_adjustment->additionAdjustment
            ? $super_adjustment->additionAdjustment->items()->pluck('quantity', 'item_id')
            : collect();
        $subtractionQtyByItem = $super_adjustment->subtractionAdjustment
            ? $super_adjustment->subtractionAdjustment->items()->pluck('quantity', 'item_id')
            : collect();

        return [$additionQtyByItem, $subtractionQtyByItem];
    }

    /**
     * @return array{0: string, 1: float} [outcome, outcome_quantity]
     */
    private function itemOutcome(int $itemId, float $liveVariance, bool $isApproved, $additionQtyByItem, $subtractionQtyByItem): array
    {
        if ($isApproved) {
            if ($additionQtyByItem->has($itemId)) {
                return ['Addition', (float) $additionQtyByItem[$itemId]];
            }
            if ($subtractionQtyByItem->has($itemId)) {
                return ['Subtraction', (float) $subtractionQtyByItem[$itemId]];
            }

            // Approved, but this item appears in neither generated
            // Adjustment — it was within the ~0 variance tolerance at
            // approval time (or every bucket was empty) and was correctly
            // left untouched. Fixed history, regardless of stock drift since.
            return ['Match', 0.0];
        }

        // Draft/Rejected: nothing has happened yet, so the live comparison
        // is the accurate preview of what approving right now would do.
        return [abs($liveVariance) < 0.0001 ? 'Match' : ($liveVariance > 0 ? 'Addition' : 'Subtraction'), abs($liveVariance)];
    }

    public function destroy(SuperAdjustment $super_adjustment)
    {
        $this->authorizeDraftMutation($super_adjustment, 'delete-super-adjustments');

        $super_adjustment->items()->delete();
        $super_adjustment->delete();
        $this->alert('super_deleted', $super_adjustment, auth()->user(), __('Super Adjustment Deleted'), $super_adjustment->reference . ' was deleted by ' . auth()->user()->name . '.', ['skip_actor' => true, 'url' => route('deleted.index', ['type' => 'super-adjustments'])]);

        return redirect()->route('super-adjustments.index')->with('message', __('Super Adjustment deleted.'));
    }

    /**
     * For the /deleted trash page. No event to fire here (unlike Adjustment)
     * — a Super Adjustment never mutates stock itself; only the real
     * Adjustment(s) it generates on approval do, and those are independent
     * records with their own restore lifecycle. Only Draft/Rejected records
     * can ever reach here, since destroy() only allows deleting a Draft.
     */
    public function restore(SuperAdjustment $super_adjustment)
    {
        $super_adjustment->items()->onlyTrashed()->get()->each->restore();
        $super_adjustment->restore();
        $this->alert('super_restored', $super_adjustment, auth()->user(), __('Super Adjustment Restored'), $super_adjustment->reference . ' was restored from the deleted records by ' . auth()->user()?->name . '.', ['skip_actor' => true]);

        return back()->with('message', __('Super Adjustment restored.'));
    }

    public function destroyPermanently(SuperAdjustment $super_adjustment)
    {
        $super_adjustment->items()->onlyTrashed()->forceDelete();
        $super_adjustment->forceDelete();
        $this->alert('super_permanently_deleted', $super_adjustment, auth()->user(), __('Super Adjustment Deleted for Good'), $super_adjustment->reference . ' was deleted for good by ' . auth()->user()?->name . '.', ['skip_actor' => true, 'url' => route('super-adjustments.index')]);

        return redirect()->route('super-adjustments.index')->with('message', __('Super Adjustment permanently deleted.'));
    }

    /**
     * Super Admin only, matching the literal "only super admin can approve"
     * requirement as a hardcoded role check rather than a grantable
     * permission (see PermissionRegistry.php comment on this module).
     * Recomputes variance against LIVE stock (not the submission-time
     * snapshot — stock may have moved since), splits items by variance sign
     * into up to two real Adjustments, and creates them via the exact same
     * PrepareOrder/OrderData/AdjustmentEvent path AdjustmentController::store()
     * uses, pre-approved (draft=false), so the existing, untouched
     * AdjustmentEventListener mutates stock immediately — identically to a
     * manually created-and-approved Adjustment.
     */
    public function approve(Request $request, SuperAdjustment $super_adjustment)
    {
        $user = $request->user();
        $required = SuperAdjustment::requiredApprovals();
        // With two approvals both must be Super Admins; with one, anyone allowed to approve (as before)
        abort_unless($required === 2 ? $user->hasRole('Super Admin') : $this->canApprove($user), 403);
        abort_unless($super_adjustment->isOpen(), 422);
        // an approval is a personal act: never while logged in AS someone else (one person could otherwise give both phases)
        abort_if(\App\Support\Impersonation::isActive(), 403, __('Approvals must be given from your own login, not while logged in as another user.'));

        $request->validate(['password' => ['required', 'string', 'max:1024']], ['password.required' => __('Type your password to approve.')]);
        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            log_activity('Super Adjustment approval refused: wrong password', ['reference' => $super_adjustment->reference], $super_adjustment, 'SuperAdjustment');
            \App\Services\SecurityAlert::note((string) $request->ip(), 'wrong password on a Super Adjustment approval');
            throw ValidationException::withMessages(['password' => [__('The password is not correct.')]]);
        }

        if ($response = $this->rejectIfWarehouseCompleted($super_adjustment->warehouse_id)) {
            return $response;
        }

        // SuperAdjustment ByPass: Dr Hajas's phase 1 is followed at once by phase 2 of the Super Admin he chose (only when no approval was given yet)
        $bypass = $required === 2 && $super_adjustment->approvals()->count() === 0 ? SuperAdjustment::bypassApproverFor($user) : null;
        // the signature check applies to the approval that completes the record
        if (($bypass || $super_adjustment->approvals()->count() + 1 >= $required) && ($refusal = $this->signingRefusal($super_adjustment, $bypass ?: $user))) {
            return redirect()->route('super-adjustments.show', $super_adjustment->id)->with('error', $refusal);
        }

        $outcome = DB::transaction(function () use ($super_adjustment, $user, $required, $bypass) {
            // locked and re-read: two Super Admins pressing Approve at the same moment must become phase 1 and phase 2, not two phase 1s
            $locked = SuperAdjustment::whereKey($super_adjustment->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isOpen(), 422, __('This Super Adjustment is no longer waiting for approval.'));
            $given = $locked->approvals()->get();
            if ($given->contains('user_id', $user->id)) {
                return ['error' => __('You already gave the first approval. A different Super Admin must give the second one.')];
            }
            $phase = $given->count() + 1;
            $this->recordApproval($locked, $user, $phase);
            if ($bypass && $phase === 1) {
                $this->recordApproval($locked, $bypass, 2, __('Approved by WIMS through the SuperAdjustment ByPass of :n', ['n' => $user->name]), false);
                $this->performApproval($locked, $bypass);

                return ['phase' => 2, 'final' => true, 'bypass' => $bypass->name];
            }
            if ($phase >= $required) {
                $this->performApproval($locked, $user);

                return ['phase' => $phase, 'final' => true];
            }
            $locked->update(['status' => SuperAdjustment::STATUS_AWAITING]);

            return ['phase' => $phase, 'final' => false];
        });

        if (isset($outcome['error'])) {
            return redirect()->route('super-adjustments.show', $super_adjustment->id)->with('error', $outcome['error']);
        }
        $super_adjustment->refresh();
        if (! $outcome['final']) {
            log_activity('Super Adjustment approved, phase ' . $outcome['phase'], ['reference' => $super_adjustment->reference, 'approved_by' => $user->name], $super_adjustment, 'SuperAdjustment');
            $this->alert('super_first_approved', $super_adjustment, $user, __('Super Adjustment Needs Second Approval'),
                $super_adjustment->reference . ' was approved (phase 1 of 2) by ' . $user->name . '. A different Super Admin must give the second approval before the stock changes.', ['skip_actor' => true]);

            return redirect()->route('super-adjustments.show', $super_adjustment->id)
                ->with('message', __('Phase 1 approved. The other Super Admins were told; the stock changes after the second approval.'));
        }

        if (! empty($outcome['bypass'])) {
            log_activity('Super Adjustment approved by the ByPass: phase 1 ' . $user->name . ', phase 2 ' . $outcome['bypass'], ['reference' => $super_adjustment->reference], $super_adjustment, 'SuperAdjustment');

            return redirect()->route('super-adjustments.show', $super_adjustment->id)
                ->with('message', __('Phase 1 approved. Phase 2 was given by :n through the ByPass and the stock is updated.', ['n' => $outcome['bypass']]));
        }

        return redirect()->route('super-adjustments.show', $super_adjustment->id)
            ->with('message', __('Super Adjustment approved (phase :n) and stock updated.', ['n' => $outcome['phase']]));
    }

    /** Writes one approval phase (who, in which role, when, from which address). */
    private function recordApproval(SuperAdjustment $sa, \App\Models\User $user, int $phase, ?string $note = null, bool $withIp = true): void
    {
        SuperAdjustmentApproval::create([
            'super_adjustment_id' => $sa->id, 'phase' => $phase, 'user_id' => $user->id, 'role' => ApproverRole::for($user) ?: 'Super Admin',
            'approved_at' => now(), 'ip_address' => $withIp ? request()->ip() : null, 'note' => $note,
        ]);
    }

    /** The approvals that no longer stand (edited, rejected or put back to Draft) are voided, never deleted. */
    private function voidApprovals(SuperAdjustment $sa, string $reason): void
    {
        SuperAdjustmentApproval::where('super_adjustment_id', $sa->id)->whereNull('voided_at')->update(['voided_at' => now(), 'voided_reason' => mb_substr($reason, 0, 190), 'updated_at' => now()]);
    }

    /**
     * The SuperAdjustment ByPass (Profile page, Dr Hajas only, confirmed with his own password). While it is active, his phase 1 on a Super Adjustment is
     * followed at once by phase 2 from the Super Admin chosen here. Approvals already given are never changed: only Super Adjustments approved after the
     * change follow the new setting.
     */
    public function saveBypass(Request $request)
    {
        $user = $request->user();
        abort_unless(\App\Services\Licensing\LicenceService::isManager($user), 403, __('Only Dr Hajas can change this.'));
        $data = $request->validate(['active' => 'required|boolean', 'approver_id' => 'nullable|integer', 'password' => 'required|string|max:1024']);
        if (! Hash::check($data['password'], (string) $user->password)) {
            log_activity('Super Adjustment ByPass refused: wrong password', [], null, 'SuperAdjustment');
            \App\Services\SecurityAlert::note((string) $request->ip(), 'wrong password on the Super Adjustment ByPass');
            throw ValidationException::withMessages(['password' => [__('The password is not correct.')]]);
        }
        $approver = null;
        if ($data['active']) {
            $approver = ! empty($data['approver_id']) ? \App\Models\User::role('Super Admin')->where('is_active', true)->whereKey((int) $data['approver_id'])->first() : null;
            if (! $approver || $approver->id === $user->id) {
                throw ValidationException::withMessages(['approver_id' => [__('Choose the Super Admin who gives phase 2 (an active Super Admin other than you).')]]);
            }
        }
        $old = SuperAdjustment::bypass();
        $account = (int) ($user->account_id ?: 1);
        foreach ([SuperAdjustment::BYPASS_ACTIVE_KEY => $data['active'] ? '1' : '0', SuperAdjustment::BYPASS_APPROVER_KEY => $approver ? (string) $approver->id : (string) ($old['approver_id'] ?? '')] as $k => $v) {
            $row = DB::table('settings')->where('tec_key', $k)->where('account_id', $account)->first();
            $row ? DB::table('settings')->where('id', $row->id)->update(['tec_value' => $v]) : DB::table('settings')->insert(['tec_key' => $k, 'tec_value' => $v, 'account_id' => $account]);
        }
        log_activity('SuperAdjustment ByPass ' . ($data['active'] ? 'activated, phase 2 by ' . $approver->name : 'switched off'), ['was' => $old, 'by' => $user->name], null, 'SuperAdjustment');

        return back()->with('message', $data['active'] ? __('The ByPass is active. Phase 2 will be given by :n for the Super Adjustments you approve from now on.', ['n' => $approver->name]) : __('The ByPass is off. Phase 2 needs a different Super Admin again.'));
    }

    /** Super Admin only: how many different Super Admins must approve (1 or 2), confirmed with the person's password. */
    public function saveApprovalRule(Request $request)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403, __('Only a Super Admin can change this.'));
        abort_if(\App\Support\Impersonation::isActive(), 403, __('Approvals must be given from your own login, not while logged in as another user.'));
        $data = $request->validate(['approvals' => 'required|in:1,2', 'password' => 'required|string|max:1024']);
        if (! Hash::check($data['password'], (string) $request->user()->password)) {
            log_activity('Super Adjustment approval rule refused: wrong password', [], null, 'SuperAdjustment');
            \App\Services\SecurityAlert::note((string) $request->ip(), 'wrong password on the Super Adjustment approval rule');
            throw ValidationException::withMessages(['password' => [__('The password is not correct.')]]);
        }
        $n = (int) $data['approvals'];
        $activeSuperAdmins = \App\Models\User::role('Super Admin')->where('is_active', true)->count();
        if ($n === 2 && $activeSuperAdmins < 2) {
            throw ValidationException::withMessages(['approvals' => [__('Two approvals need at least two active Super Admins. There is only :n.', ['n' => $activeSuperAdmins])]]);
        }
        $old = SuperAdjustment::requiredApprovals();
        $account = (int) ($request->user()->account_id ?: 1);
        $row = DB::table('settings')->where('tec_key', SuperAdjustment::RULE_KEY)->where('account_id', $account)->first();
        $row ? DB::table('settings')->where('id', $row->id)->update(['tec_value' => (string) $n]) : DB::table('settings')->insert(['tec_key' => SuperAdjustment::RULE_KEY, 'tec_value' => (string) $n, 'account_id' => $account]);
        \Illuminate\Support\Facades\Cache::flush();
        log_activity('Super Adjustment approval rule changed', ['from' => $old, 'to' => $n, 'by' => $request->user()->name], null, 'SuperAdjustment');

        return back()->with('message', $n === 2 ? __('Super Adjustments now need two different Super Admin approvals.') : __('Super Adjustments now need one Super Admin approval.'));
    }

    /**
     * The approval itself, shared by approve() (a Super Admin reviewing someone's Draft) and store() (a Super Admin's own
     * submission, approved on creation). Stamps who approved, when and in which role, then tells the submitter.
     */
    private function performApproval(SuperAdjustment $super_adjustment, \App\Models\User $actor): void
    {
        DB::transaction(function () use ($actor, $super_adjustment) {
            // Locked and re-checked here, not trusted from the guard above —
            // without this, two near-simultaneous approve calls (or an
            // approve racing a reject) could both pass the Draft check and
            // each create their own stock-mutating Adjustment, silently
            // orphaning one pair and double-adjusting real stock.
            $locked = SuperAdjustment::whereKey($super_adjustment->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isOpen(), 422, __('This Super Adjustment is no longer waiting for approval.'));

            $super_adjustment->load(['items.unit', 'items.item']);

            $liveStock = Stock::main()->ofWarehouse($super_adjustment->warehouse_id)
                ->whereIn('item_id', $super_adjustment->items->pluck('item_id'))
                ->pluck('quantity', 'item_id');

            $additions = [];
            $subtractions = [];
            // Items auto-added at zero via the "Clear" action always land in
            // Subtraction (physical 0 < any positive live stock) — tracked
            // per bucket (not assumed) so the generated Adjustment's own
            // extra_attributes can flag exactly which of its lines were
            // never physically counted, for the "Cleared" indicator on the
            // Adjustment record itself (see Adjustment/Details.vue).
            $clearedItemIdsByType = ['Addition' => [], 'Subtraction' => []];
            foreach ($super_adjustment->items as $index => $line) {
                $current = (float) ($liveStock[$line->item_id] ?? 0);
                $variance = $line->varianceAgainst($current);
                if (abs($variance) < 0.0001) {
                    continue;
                }
                $row = [
                    'item_id' => $line->item_id,
                    'unit_id' => $line->unit_id,
                    'quantity' => abs($variance),
                    'weight' => 0,
                    'order' => $index,
                    'selected' => ['serials' => [], 'variations' => []],
                ];
                if ($variance > 0) {
                    $additions[] = $row;
                    if ($line->auto_cleared) {
                        $clearedItemIdsByType['Addition'][] = $line->item_id;
                    }
                } else {
                    $subtractions[] = $row;
                    if ($line->auto_cleared) {
                        $clearedItemIdsByType['Subtraction'][] = $line->item_id;
                    }
                }
            }

            $createdIds = ['addition' => null, 'subtraction' => null];
            foreach (['Addition' => $additions, 'Subtraction' => $subtractions] as $type => $items) {
                if (empty($items)) {
                    continue;
                }

                $data = [
                    'date' => now()->format('Y-m-d'),
                    'warehouse_id' => $super_adjustment->warehouse_id,
                    'type' => $type,
                    'items' => $items,
                    'draft' => false,
                    'approved_by' => $actor->id,
                    'approved_by_role' => ApproverRole::for($actor),
                    'approved_at' => now(),
                    'details' => __('Generated from Super Adjustment :reference', ['reference' => $super_adjustment->reference]),
                    'extra_attributes' => [
                        'super_adjustment_id' => $super_adjustment->id,
                        'super_adjustment_reference' => $super_adjustment->reference,
                        'cleared_item_ids' => $clearedItemIdsByType[$type],
                    ],
                    'reference' => $this->nextAdjustmentReference(),
                ];

                $adjustment = (new PrepareOrder($data, [], new Adjustment()))->process()->save();
                event(new AdjustmentEvent($adjustment, 'created'));

                $createdIds[strtolower($type)] = $adjustment->id;
            }

            $super_adjustment->update([
                'status' => SuperAdjustment::STATUS_APPROVED,
                'approved_by_user_id' => $actor->id,
                'approved_by_role' => ApproverRole::for($actor),
                'approved_at' => now(),
                'created_addition_adjustment_id' => $createdIds['addition'],
                'created_subtraction_adjustment_id' => $createdIds['subtraction'],
            ]);
        }, 2);

        log_activity('Super Adjustment Approved', ['reference' => $super_adjustment->reference, 'approved_by_role' => ApproverRole::for($actor)], $super_adjustment, 'SuperAdjustment');
        $this->notifySubmitter($super_adjustment->refresh(), 'approved', $actor);
    }

    /** Signatories: a refusal message when an administrator made a signature required, blocking, and it would be missing once this approval is stamped (null otherwise). */
    private function signingRefusal(SuperAdjustment $super_adjustment, \App\Models\User $actor): ?string
    {
        return \App\Services\Signatories\Documents\StockSigningGuard::refusal('super_adjustment', $super_adjustment, [
            'status' => SuperAdjustment::STATUS_APPROVED, 'approved_by_user_id' => $actor->id, 'approved_at' => now(), 'rejected_by_user_id' => null, 'rejected_at' => null,
        ], __('approved'));
    }

    private function canApprove(\App\Models\User $user): bool
    {
        return $user->hasRole('Super Admin') || $user->can('approve-super-adjustments');
    }

    /** Super Admin only: put a Super Adjustment into any status. Leaving Approved reverses the stock its generated Adjustments applied. */
    public function changeStatus(Request $request, SuperAdjustment $super_adjustment)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403, __('Only a Super Admin can change the status of a Super Adjustment.'));
        $data = $request->validate([
            'status' => 'required|in:' . implode(',', array_diff(SuperAdjustment::statuses(), [SuperAdjustment::STATUS_AWAITING])),
            'rejection_reason' => 'required_if:status,' . SuperAdjustment::STATUS_REJECTED . '|nullable|string|min:3|max:1000',
        ]);
        abort_if($super_adjustment->status === $data['status'], 422, __('This Super Adjustment is already :status.', ['status' => strtolower($data['status'])]));
        if ($response = $this->rejectIfWarehouseCompleted($super_adjustment->warehouse_id)) {
            return $response;
        }

        if ($data['status'] === SuperAdjustment::STATUS_APPROVED && SuperAdjustment::requiredApprovals() === 2) {
            return back()->with('error', __('With the two-approval rule a Super Adjustment is approved only by two different Super Admins with the Approve button.'));
        }

        $previous = $super_adjustment->status;
        $this->moveToStatus($super_adjustment, $data['status'], $request->user(), $data['rejection_reason'] ?? null);
        if ($data['status'] === SuperAdjustment::STATUS_DRAFT) {
            $this->alert('super_set_pending', $super_adjustment, $request->user(), __('Super Adjustment Set Back to Draft'), $super_adjustment->reference . ' was moved from ' . $previous . ' back to Draft by ' . $request->user()->name . '. Any stock its adjustments changed was put back.', ['skip_actor' => true]);
        }

        return redirect()->route('super-adjustments.show', $super_adjustment->id)
            ->with('message', __('Super Adjustment status changed to :status.', ['status' => __($data['status'])]));
    }

    /**
     * Every status move goes through here. Leaving Approved first marks the generated Adjustment(s) Rejected, which reverses
     * their stock through AdjustmentStatusService; arriving at Approved runs the normal approval (new Adjustments, live stock).
     */
    private function moveToStatus(SuperAdjustment $sa, string $target, \App\Models\User $actor, ?string $reason = null, ?string $why = null): void
    {
        $why = $why ?: __('Reverted with Super Adjustment :reference', ['reference' => $sa->reference]);

        if ($target === SuperAdjustment::STATUS_APPROVED && ($refusal = $this->signingRefusal($sa, $actor))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['status' => $refusal]);
        }

        // Same rule as Adjustments: an approval can only be taken back shortly after it was given.
        if ($sa->approvalLocked() && $target !== SuperAdjustment::STATUS_APPROVED) {
            throw \Illuminate\Validation\ValidationException::withMessages(['status' => Adjustment::reversalBlockedMessage(__('Super Adjustment'))]);
        }

        if ($sa->status === SuperAdjustment::STATUS_APPROVED) {
            $statuses = app(AdjustmentStatusService::class);
            foreach ([$sa->created_addition_adjustment_id, $sa->created_subtraction_adjustment_id] as $id) {
                $adjustment = $id ? Adjustment::withoutGlobalScopes()->find($id) : null;
                if ($adjustment && $adjustment->status === Adjustment::STATUS_APPROVED) {
                    $statuses->change($adjustment, Adjustment::STATUS_REJECTED, $actor, $why, true);
                }
            }
        }

        // Back to a clean Draft first (clears approval / rejection / generated links); Rejected and Approved build on that.
        $sa->forceFill([
            'status' => SuperAdjustment::STATUS_DRAFT,
            'approved_by_user_id' => null, 'approved_by_role' => null, 'approved_at' => null,
            'rejected_by_user_id' => null, 'rejected_at' => null, 'rejection_reason' => null,
            'created_addition_adjustment_id' => null, 'created_subtraction_adjustment_id' => null,
        ])->save();

        // the approvals given before no longer stand (kept as history, voided)
        $this->voidApprovals($sa, $reason ?: $why);

        // The status moved, so any frozen signature set of the earlier status no longer applies.
        \App\Services\Signatories\Documents\StockSigningGuard::forget('super_adjustment', (int) $sa->id);

        if ($target === SuperAdjustment::STATUS_APPROVED) {
            $this->recordApproval($sa, $actor, 1);
            $this->performApproval($sa, $actor);
        } elseif ($target === SuperAdjustment::STATUS_REJECTED) {
            $sa->forceFill([
                'status' => SuperAdjustment::STATUS_REJECTED,
                'rejected_by_user_id' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => trim((string) $reason),
            ])->save();
            $this->notifySubmitter($sa->refresh(), 'rejected', $actor);
        }

        log_activity('Super Adjustment Status Changed', ['reference' => $sa->reference, 'to' => $target, 'reason' => $reason ?: $why], $sa, 'SuperAdjustment');
    }

    public function reject(Request $request, SuperAdjustment $super_adjustment)
    {
        abort_unless($this->canApprove($request->user()), 403);
        abort_unless($super_adjustment->isOpen(), 422);

        $data = $request->validate(['rejection_reason' => 'required|string|max:1000']);

        DB::transaction(function () use ($request, $super_adjustment, $data) {
            // Same lock+re-check as approve() — closes the identical race
            // (an approve committing in the gap between the guard above and
            // this write must not also be rejected).
            $locked = SuperAdjustment::whereKey($super_adjustment->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isOpen(), 422, __('This Super Adjustment is no longer waiting for approval.'));
            $this->voidApprovals($locked, __('Rejected by :name', ['name' => $request->user()->name]));

            $locked->update([
                'status' => SuperAdjustment::STATUS_REJECTED,
                'rejected_by_user_id' => $request->user()->id,
                'rejected_at' => now(),
                'rejection_reason' => $data['rejection_reason'],
            ]);
        });

        log_activity('Super Adjustment Rejected', ['reference' => $super_adjustment->reference, 'reason' => $data['rejection_reason']], $super_adjustment, 'SuperAdjustment');
        $this->notifySubmitter($super_adjustment->refresh(), 'rejected', $request->user());

        return redirect()->route('super-adjustments.show', $super_adjustment->id)
            ->with('message', __('Super Adjustment rejected.'));
    }

    /**
     * The item picker itself reuses the generic items.search endpoint (same
     * as Adjustment/Form.vue) — the shared AutoComplete component only ever
     * posts {search}, no room for a warehouse_id alongside it. This is a
     * separate, lightweight lookup fired right after an item is selected
     * (and again if the warehouse changes), returning that item's LIVE
     * system stock in the item's own default unit — the unit this module
     * always locks to, so no unit-conversion is ever needed when comparing
     * against a physical count.
     */
    public function itemStock(Request $request)
    {
        abort_unless($request->user()->can('create-super-adjustments'), 403);

        $data = $request->validate([
            'item_id' => 'required|exists:items,id',
            'warehouse_id' => 'required|exists:warehouses,id',
        ]);

        $quantity = Stock::main()->ofWarehouse($data['warehouse_id'])->ofItem($data['item_id'])->value('quantity');

        return ['system_quantity' => (float) ($quantity ?? 0)];
    }

    /**
     * Lets the form warn before submit when the warehouse has stocked items
     * the user left out of this physical count, so they can opt into a full
     * recount (zeroing the rest out at approval) instead of silently leaving
     * them untouched — see plan decision on the "uncounted item" edge case.
     */
    public function uncountedItems(Request $request)
    {
        abort_unless($request->user()->can('create-super-adjustments'), 403);

        $data = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'item_ids' => 'nullable|array',
            'item_ids.*' => 'integer',
        ]);

        // An open Adjustment Session for this warehouse tracks the items not counted across all its Super Adjustments, so the per-document question is switched off.
        if (\App\Models\AdjustmentSession::openFor((int) $data['warehouse_id'])) {
            return response()->json([]);
        }

        return Stock::main()->ofWarehouse($data['warehouse_id'])
            ->where('quantity', '!=', 0)
            ->whereNotIn('item_id', $data['item_ids'] ?? [])
            ->with(['item:id,name,code,unit_id', 'item.unit:id,name,code'])
            ->get()
            ->filter(fn ($stock) => $stock->item)
            ->map(fn ($stock) => [
                'item_id' => $stock->item_id,
                'name' => $stock->item->name,
                'code' => $stock->item->code,
                'unit_id' => $stock->item->unit_id,
                'unit_name' => optional($stock->item->unit)->name,
                'system_quantity' => (float) $stock->quantity,
            ])
            ->values();
    }

    /**
     * Every stocked item of the warehouse (non-zero stock), for the "Import from Warehouse" button: the form turns each
     * into a count line pre-filled with the current system quantity, ready to be corrected or cleared.
     */
    public function warehouseStock(Request $request)
    {
        abort_unless($request->user()->can('create-super-adjustments'), 403);

        $data = $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id']);
        abort_unless(Warehouse::ofAccount()->active()->visible()->whereKey($data['warehouse_id'])->exists(), 403, __('You cannot use this Project / Warehouse.'));

        return Stock::main()->ofWarehouse($data['warehouse_id'])
            ->where('quantity', '!=', 0)
            ->with(['item:id,name,code,unit_id', 'item.unit:id,name,code'])
            ->get()
            ->filter(fn ($stock) => $stock->item)
            ->sortBy(fn ($stock) => mb_strtolower($stock->item->name))
            ->map(fn ($stock) => [
                'item_id' => $stock->item_id,
                'name' => $stock->item->name,
                'code' => $stock->item->code,
                'unit_id' => $stock->item->unit_id,
                'unit_name' => optional($stock->item->unit)->name,
                'system_quantity' => (float) $stock->quantity,
            ])
            ->values();
    }

    public function downloadAttachment(Request $request, SuperAdjustment $super_adjustment, Attachment $attachment)
    {
        abort_unless($request->user()->can('read-super-adjustments'), 403);
        abort_unless((int) $attachment->model_id === (int) $super_adjustment->id && $attachment->model_type === SuperAdjustment::class, 404);

        if ($request->query('disposition') === 'inline') {
            return Storage::disk($attachment->disk)->response($attachment->filepath, $attachment->title);
        }

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title);
    }

    public function destroyAttachment(SuperAdjustment $super_adjustment, Attachment $attachment)
    {
        $this->authorizeDraftMutation($super_adjustment, 'update-super-adjustments');
        abort_unless((int) $attachment->model_id === (int) $super_adjustment->id && $attachment->model_type === SuperAdjustment::class, 404);

        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        return back()->with('message', __('Attachment removed.'));
    }

    private function authorizeDraftMutation(SuperAdjustment $super_adjustment, string $permission): void
    {
        $user = request()->user();
        // Super Admin may edit a record in any status (editing an approved / rejected one reopens it as Draft).
        if ($permission === 'update-super-adjustments' && $user->hasRole('Super Admin')) {
            return;
        }
        $isOwner = (int) $super_adjustment->user_id === (int) $user->id;
        abort_unless($isOwner || $user->can($permission), 403);
        abort_unless($super_adjustment->status === SuperAdjustment::STATUS_DRAFT, 422, __('Only a Draft Super Adjustment can be modified.'));
    }

    private function persist(SuperAdjustment $superAdjustment, array $data, $attachments, ?\App\Models\AdjustmentSession $session = null): SuperAdjustment
    {
        $itemIds = collect($data['items'])->pluck('item_id')->unique()->values();
        $unitByItem = Item::whereIn('id', $itemIds)->pluck('unit_id', 'id');
        $liveStock = Stock::main()->ofWarehouse($data['warehouse_id'])->whereIn('item_id', $itemIds)->pluck('quantity', 'item_id');

        return DB::transaction(function () use ($superAdjustment, $data, $attachments, $unitByItem, $liveStock, $session) {
            $isNew = ! $superAdjustment->exists;

            $superAdjustment->fill([
                'warehouse_id' => $data['warehouse_id'],
                'date' => now()->format('Y-m-d'),
                'reason' => $data['reason'],
                'details' => $data['details'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'status' => SuperAdjustment::STATUS_DRAFT,
            ]);

            // Adjustment Session: a count of a warehouse that has an open session belongs to it (an older count is linked only if it was made after the session started)
            $open = $session ?: \App\Models\AdjustmentSession::openFor((int) $data['warehouse_id'], true);
            if ($isNew) {
                $superAdjustment->adjustment_session_id = $open ? $open->id : null;
            } elseif ($superAdjustment->adjustment_session_id) {
                $linked = \App\Models\AdjustmentSession::find($superAdjustment->adjustment_session_id);
                if (! $linked || (int) $linked->warehouse_id !== (int) $data['warehouse_id']) {
                    $superAdjustment->adjustment_session_id = $open ? $open->id : null;
                }
            } elseif ($open && $superAdjustment->created_at && $superAdjustment->created_at->gte($open->started_at)) {
                $superAdjustment->adjustment_session_id = $open->id;
            }

            if ($isNew) {
                $superAdjustment->reference = $this->nextSuperAdjustmentReference();
            }

            $superAdjustment->save();

            $superAdjustment->items()->delete();
            foreach ($data['items'] as $line) {
                // unit_id is always the item's own default unit — never
                // taken from client input — so the physical count and the
                // system stock it's compared against are guaranteed to be
                // in the same unit (see plan decision 3).
                $unitId = $unitByItem[$line['item_id']] ?? null;
                SuperAdjustmentItem::create([
                    'super_adjustment_id' => $superAdjustment->id,
                    'item_id' => $line['item_id'],
                    'unit_id' => $unitId,
                    'system_quantity_at_submission' => (float) ($liveStock[$line['item_id']] ?? 0),
                    'physical_quantity' => $line['physical_quantity'],
                    'auto_cleared' => (bool) ($line['auto_cleared'] ?? false),
                    'account_id' => $superAdjustment->account_id,
                ]);
            }

            if ($attachments) {
                $superAdjustment->saveAttachments($attachments);
            }

            return $superAdjustment->refresh();
        }, 2);
    }

    /** Tells whoever submitted the count that a Super Admin approved or rejected it: Adjustments workflow page. */
    private function notifySubmitter(SuperAdjustment $sa, string $outcome, \App\Models\User $actor): void
    {
        $approved = $outcome === 'approved';
        $this->alert($approved ? 'super_approved' : 'super_rejected', $sa, $actor, $approved ? __('Super Adjustment Approved') : __('Super Adjustment Rejected'),
            $approved
                ? $sa->reference . ' was approved by ' . $this->approverNames($sa, $actor) . '. Stock has been updated.'
                : $sa->reference . ' was rejected by ' . $actor->name . '. Reason: ' . $sa->rejection_reason,
            ['skip_actor' => true, 'remarks' => $approved ? null : $sa->rejection_reason]);
    }

    /** "Name (Super Admin)" for one approver, "A (Super Admin) and B (Super Admin)" when two approved. */
    private function approverNames(SuperAdjustment $sa, \App\Models\User $actor): string
    {
        $rows = $sa->approvals()->with('user:id,name')->get();
        if ($rows->count() < 2) {
            return $actor->name . ' (' . (ApproverRole::for($actor) ?: 'Super Admin') . ')';
        }

        return $rows->map(fn ($a) => optional($a->user)->name . ' (' . ($a->role ?: 'Super Admin') . ')')->implode(' and ');
    }

    /** A Draft needs a Super Admin: Adjustments workflow page. */
    private function notifyApprovers(SuperAdjustment $sa): void
    {
        $sa->loadMissing(['warehouse:id,name', 'user:id,name']);
        $this->alert('super_pending_approval', $sa, auth()->user(), __('Super Adjustment Pending Approval'), $sa->reference . ' was submitted by ' . optional($sa->user)->name . ' for ' . optional($sa->warehouse)->name . '.');
    }

    private function alert(string $event, SuperAdjustment $sa, ?\App\Models\User $actor, string $title, string $message, array $ctx = []): void
    {
        $sa->loadMissing(['warehouse:id,name', 'user:id,name']);
        \App\Services\Workflow\WorkflowMessenger::dispatch('adjustments', $event, $sa, $actor, $ctx + [
            'account_id' => $sa->account_id ?? 1, 'title' => $title, 'message' => $message, 'url' => route('super-adjustments.show', $sa->id),
            'owner_user_id' => (int) $sa->user_id, 'warehouse_ids' => array_filter([$sa->warehouse_id]),
            'values' => ['reference' => $sa->reference, 'warehouse' => optional($sa->warehouse)->name ?? '', 'type' => 'Super Adjustment', 'creator' => optional($sa->user)->name ?? ''],
        ]);
    }

    private function nextSuperAdjustmentReference(): string
    {
        $maxSuffix = (int) DB::table('super_adjustments')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, 6) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', 'SADJ-%')
            ->value('max_suffix');

        return 'SADJ-' . str_pad($maxSuffix + 1, 4, '0', STR_PAD_LEFT);
    }

    /** Must be called fresh per Adjustment created within the same approve() transaction — the second call needs to see the first's insert. */
    private function nextAdjustmentReference(): string
    {
        $maxSuffix = (int) DB::table('adjustments')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', 'RSA-%')
            ->value('max_suffix');

        return 'RSA-' . str_pad($maxSuffix + 1, 4, '0', STR_PAD_LEFT);
    }
}
