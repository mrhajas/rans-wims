<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Carbon\Carbon;
use App\Models\Checkin;
use App\Models\Contact;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Actions\Tec\PrepareOrder;
use App\Http\Requests\CheckinRequest;
use App\Http\Resources\CheckinResource;
use App\Http\Resources\CheckinCollection;
use App\Traits\GuardsCompletedWarehouse;
use App\Traits\HasActivityHistory;
use App\Support\PrintQrCode;
use App\Support\ApproverRole;
use App\Support\PrintDocuments;
use App\Support\ReferenceLock;
use Barryvdh\DomPDF\Facade\Pdf;

class CheckinController extends Controller
{
    use GuardsCompletedWarehouse;
    use HasActivityHistory;

    public function create()
    {
        return Inertia::render('Checkin/Form', [
            'contacts'   => Contact::ofAccount()->get(),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(),
        ]);
    }

    public function destroy(Checkin $checkin)
    {
        if ($response = $this->rejectIfWarehouseBlocked($checkin->warehouse_id)) {
            return $response;
        }
        $checkin->load(['items.item', 'items.unit', 'items.variations']);
        if ($checkin->del()) {
            event(new \App\Events\CheckinEvent($checkin, 'deleted'));

            return redirect()->route('checkins.index')->with('message', __choice('action_text', ['record' => 'Checkin', 'action' => 'deleted']));
        }

        return redirect()->route('checkins.index')->with('error', __('The record can not be deleted.'));
    }

    public function destroyPermanently(Checkin $checkin)
    {
        if ($response = $this->rejectIfWarehouseBlocked($checkin->warehouse_id)) {
            return $response;
        }
        $checkin->load(['items.item', 'items.unit', 'items.variations']);
        if ($checkin->delP()) {
            $event = new \App\Events\CheckinEvent($checkin, 'deleted');
            $event->permanent = true;
            event($event);

            return redirect()->route('checkins.index')->with('message', __choice('action_text', ['record' => 'Checkin', 'action' => 'permanently deleted']));
        }

        return redirect()->route('checkins.index')->with('error', __('The record can not be deleted.'));
    }

    public function edit(Checkin $checkin)
    {
        $this->authorize('update', $checkin);
        if ($response = $this->rejectIfWarehouseBlocked($checkin->warehouse_id)) {
            return $response;
        }
        if ($response = $this->denyExpiredSiteEngineerEdit($checkin)) {
            return $response;
        }

        return Inertia::render('Checkin/Form', [
            'contacts'   => Contact::ofAccount()->get(),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(),
            'edit'       => new CheckinResource($checkin->load(['items.variations', 'items.item.variations', 'attachments'])),
            'history'    => $this->activityHistory(Checkin::class, $checkin->id),
            'deliveryNote' => \App\Http\Controllers\DeliveryNoteController::stateFor($checkin, \App\Models\DeliveryNote::SOURCE_CHECKIN),
            'gatePass' => \App\Http\Controllers\GatePassController::stateFor($checkin, \App\Models\GatePass::SOURCE_CHECKIN),
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->all('draft', 'status', 'search', 'trashed', 'view');
        // The summary tiles above the table filter the list (same definitions as the tile counts).
        $filters['view'] = in_array($filters['view'] ?? null, ['today', 'month', 'drafts', 'pending', 'rejected'], true) ? $filters['view'] : null;
        $filters['status'] = in_array($filters['status'] ?? null, Checkin::statuses(), true) ? $filters['status'] : null;

        $checkins = Checkin::with(['contact:id,name', 'warehouse:id,name', 'user:id,name', 'approver:id,name', 'rejecter:id,name', 'grnGenerator:id,name'])->filter($filters)
            ->when($filters['status'], fn ($q, $status) => $q->status($status))
            ->when($filters['view'], fn ($q, $view) => $this->applyTile($q, $view))
            ->when(warehouse_scope_ids() !== null, fn ($q) => $q->withoutGlobalScope('mine')->whereIn('warehouse_id', warehouse_scope_ids()))
            ->orderByDesc('id')->paginate()->withQueryString();

        return Inertia::render('Checkin/Index', [
            'filters'  => $filters,
            'checkins' => new CheckinCollection($checkins),
            'stats' => $this->indexStats(),
            'deliveryNotes' => \App\Http\Controllers\DeliveryNoteController::statesFor($checkins->items(), \App\Models\DeliveryNote::SOURCE_CHECKIN),
            'gatePasses' => \App\Http\Controllers\GatePassController::statesFor($checkins->items(), \App\Models\GatePass::SOURCE_CHECKIN),
        ]);
    }

    /**
     * Summary tiles for the index page. Same visibility/scope rules as index()
     * (account + "mine" global scopes, warehouse scope override, trashed rows
     * excluded); counts come from one aggregate query.
     */
    /** Narrows the list to what a summary tile counts: created today / this month, or still a draft. */
    private function applyTile($query, string $view)
    {
        return match ($view) {
            'today' => $query->where('created_at', '>=', now()->startOfDay()->toDateTimeString()),
            'month' => $query->where('created_at', '>=', now()->startOfMonth()->toDateTimeString()),
            'drafts' => $query->status(Checkin::STATUS_DRAFT),
            'pending' => $query->status(Checkin::STATUS_PENDING),
            'rejected' => $query->status(Checkin::STATUS_REJECTED),
            default => $query,
        };
    }

    private function indexStats(): array
    {
        $base = Checkin::query()
            ->when(warehouse_scope_ids() !== null, fn ($q) => $q->withoutGlobalScope('mine')->whereIn('warehouse_id', warehouse_scope_ids()));

        $startOfDay = now()->startOfDay()->toDateTimeString();
        $startOfMonth = now()->startOfMonth()->toDateTimeString();

        $row = (clone $base)->toBase()->selectRaw(
            'COUNT(*) as total, COALESCE(SUM(created_at >= ?), 0) as today, COALESCE(SUM(created_at >= ?), 0) as month, COALESCE(SUM(draft = 1 AND submitted_at IS NULL AND rejected_at IS NULL), 0) as drafts, COALESCE(SUM(draft = 1 AND submitted_at IS NOT NULL AND rejected_at IS NULL), 0) as pending, COALESCE(SUM(draft = 1 AND rejected_at IS NOT NULL), 0) as rejected',
            [$startOfDay, $startOfMonth]
        )->first();

        $quantity = \Illuminate\Support\Facades\DB::table('checkin_items')
            ->whereNull('deleted_at')
            ->whereIn('checkin_id', (clone $base)->select('id')->toBase())
            ->sum('quantity');

        return [
            'total' => (int) ($row->total ?? 0),
            'today' => (int) ($row->today ?? 0),
            'month' => (int) ($row->month ?? 0),
            'drafts' => (int) ($row->drafts ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
            'quantity' => (float) $quantity,
        ];
    }

    public function restore(Checkin $checkin)
    {
        abort_unless(auth()->user()->can('delete-checkins'), 403);

        $checkin->restore();
        $checkin->items->each->restore();
        event(new \App\Events\CheckinEvent($checkin, 'restored'));

        return back()->with('message', __choice('action_text', ['record' => 'Checkin', 'action' => 'restored']));
    }

    public function show(Request $request, Checkin $checkin)
    {
        $checkin->load(['items.variations', 'items.item:id,code,name,track_quantity,track_weight,photo', 'contact', 'warehouse', 'items.unit:id,code,name', 'user:id,name,username', 'approver:id,name', 'rejecter:id,name', 'grnGenerator:id,name', 'attachments']);
        return $request->json ? $checkin : Inertia::render('Checkin/Show', [
            'checkin' => new CheckinResource($checkin),
            'history' => $this->activityHistory(Checkin::class, $checkin->id),
            'deliveryNote' => \App\Http\Controllers\DeliveryNoteController::stateFor($checkin, \App\Models\DeliveryNote::SOURCE_CHECKIN),
            'gatePass' => \App\Http\Controllers\GatePassController::stateFor($checkin, \App\Models\GatePass::SOURCE_CHECKIN),
        ]);
    }

    public function print(Checkin $checkin)
    {
        $this->authorize('print-checkins');
        $checkin->load(['items.item:id,code,name,price', 'items.unit:id,code,name', 'contact', 'warehouse', 'user:id,name']);

        return view('checkins.print', $this->printData($checkin, false));
    }

    public function pdf(Checkin $checkin)
    {
        $this->authorize('print-checkins');
        $checkin->load(['items.item:id,code,name,price', 'items.unit:id,code,name', 'contact', 'warehouse', 'user:id,name']);

        return Pdf::loadView('checkins.print', $this->printData($checkin, true))
            ->setPaper('a4')
            ->download('checkin-' . $checkin->reference . '.pdf');
    }

    private function printData(Checkin $checkin, bool $isPdf): array
    {
        return [
            'checkin' => $checkin,
            'companyName' => optional(auth()->user()->account)->name,
            'qr' => PrintQrCode::dataUri(route('checkins.show', $checkin->id)),
            'isPdf' => $isPdf,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('check_in', $checkin),
        ];
    }

    public function store(CheckinRequest $request)
    {
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseBlocked($data['warehouse_id'] ?? null)) {
            return $response;
        }
        // A completed check-in is not final until it is approved: it is submitted (Pending), its stock is added on approval and the
        // Signatories "final step" check happens there (see approve()). Saving it as a draft keeps it away from the approvers.
        $data = $this->withApprovalState($data, null);

        $checkin = ReferenceLock::run('checkins', function () use ($data, $request) {
            $maxSuffix = (int) DB::table('checkins')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', 'RSC-%')
            ->value('max_suffix');

        $data['reference'] = 'RSC-' . str_pad($maxSuffix + 1, 4, '0', STR_PAD_LEFT);


            return (new PrepareOrder($data, $request->file('attachments'), new Checkin()))->process()->save();
        });
        event(new \App\Events\CheckinEvent($checkin, 'created'));
        log_activity('Checkin Created', ['reference' => $checkin->reference, 'warehouse_id' => $checkin->warehouse_id, 'contact_id' => $checkin->contact_id, 'status' => $checkin->status], $checkin, 'Checkin');

        return redirect()->route('checkins.index')->with('message', $checkin->isPending()
            ? __('Checkin :ref was submitted. Its stock is added when a Super Admin approves it.', ['ref' => $checkin->reference])
            : __choice('action_text', ['record' => 'Checkin', 'action' => 'created']));
    }

    /**
     * The approval fields that go with a save. "Final" in the form means "submit for approval": the record stays a draft as far as stock is
     * concerned (draft = 1) and is stamped as submitted. Saving as a draft clears the submission. An approved check-in edited by someone who may
     * approve stays approved; edited by anyone else it goes back to Pending (its stock is taken out until it is approved again).
     */
    private function withApprovalState(array $data, ?Checkin $existing): array
    {
        $asDraft = ! empty($data['draft']);

        if ($existing && $existing->status === Checkin::STATUS_APPROVED) {
            if (Checkin::userCanApprove(auth()->user())) {
                $data['draft'] = false;   // stays approved

                return $data;
            }
            // changed by someone who cannot approve: it waits for approval again, the approval stamps are cleared (the GRN number stays)
            return [
                'draft' => true, 'submitted_at' => now(), 'approved_by' => null, 'approved_at' => null, 'approved_by_role' => null,
                'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null,
            ] + $data;
        }

        if ($asDraft) {
            return ['draft' => true, 'submitted_at' => null, 'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null] + $data;
        }

        // submitted (a rejected record that is saved again is a new submission)
        return ['draft' => true, 'submitted_at' => now(), 'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null] + $data;
    }

    public function update(CheckinRequest $request, Checkin $checkin)
    {
        $this->authorize('update', $checkin);
        if ($response = $this->denyExpiredSiteEngineerEdit($checkin)) {
            return $response;
        }
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseBlocked($checkin->warehouse_id, $data['warehouse_id'] ?? null)) {
            return $response;
        }
        $wasFinal = ! $checkin->draft;
        $data = $this->withApprovalState($data, $checkin);
        $original = $checkin->load(['items.item', 'items.unit', 'items.variations'])->replicate();
        $checkin = (new PrepareOrder($data, $request->file('attachments'), $checkin))->process()->save();
        event(new \App\Events\CheckinEvent($checkin, 'updated', $original));
        if ($wasFinal && $checkin->draft) {
            \App\Services\Signatories\Documents\StockSigningGuard::forget('check_in', (int) $checkin->id);   // taken back to draft: the next completion freezes afresh
        }
        log_activity('Checkin Updated', ['reference' => $checkin->reference, 'warehouse_id' => $checkin->warehouse_id, 'contact_id' => $checkin->contact_id, 'status' => $checkin->status], $checkin, 'Checkin');
        session()->flash('message', $wasFinal && $checkin->isPending()
            ? __('Checkin :ref was changed, so it waits for a Super Admin to approve it again. Its stock is held back until then.', ['ref' => $checkin->reference])
            : ($checkin->isPending() && $original->status !== Checkin::STATUS_PENDING
                ? __('Checkin :ref was submitted. Its stock is added when a Super Admin approves it.', ['ref' => $checkin->reference])
                : __choice('action_text', ['record' => 'Checkin', 'action' => 'updated'])));

        return $request->listing == 'yes' ? redirect()->route('checkins.index') : back();
    }

    /**
     * Approve a pending check-in: stamps who/when/in which role, takes it out of draft, adds its stock, generates its Goods Received Note
     * (GRN) and tells the person who made it. Locked and re-checked so a double click (or an approve racing a reject) can never add the
     * stock twice.
     */
    public function approve(Request $request, Checkin $checkin)
    {
        abort_unless(Checkin::userCanApprove($request->user()), 403, __('You are not allowed to approve checkins.'));
        if ($response = $this->rejectIfWarehouseBlocked($checkin->warehouse_id)) {
            return $response;
        }
        // Signatories: refuse the final step when an administrator made a signature required and it is missing (inert until switched on).
        if ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('check_in', $checkin, ['draft' => false, 'user_id' => $checkin->user_id, 'created_at' => $checkin->created_at], __('approved'))) {
            return back()->with('error', $refusal);
        }

        $before = ReferenceLock::run('grn', function () use ($request, $checkin) {
            return DB::transaction(function () use ($request, $checkin) {
                $locked = $checkin->newQueryWithoutScopes()->whereKey($checkin->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->status === Checkin::STATUS_PENDING, 422, __('This checkin is no longer pending approval.'));

                $checkin->load(['items.item', 'items.unit', 'items.variations']);
                $before = $checkin->replicate();
                $now = now();
                $checkin->forceFill([
                    'draft' => false,
                    'approved_by' => $request->user()->id,
                    'approved_by_role' => ApproverRole::for($request->user()),
                    'approved_at' => $now,
                    'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null,
                    // the GRN is issued with the approval; a check-in that was approved before keeps its number and gets a fresh date and name
                    'grn_number' => $checkin->grn_number ?: Checkin::nextGrnNumber(),
                    'grn_generated_by' => $request->user()->id,
                    'grn_generated_at' => $now,
                ])->save();
                // The lines carry their own draft flag (stock reports read it), so they leave draft together with the header.
                $checkin->items()->update(['draft' => false]);

                return $before;
            });
        });

        event(new \App\Events\CheckinEvent($checkin, 'approved', $before));
        log_activity('Checkin Approved', ['reference' => $checkin->reference, 'warehouse_id' => $checkin->warehouse_id, 'grn' => $checkin->grn_number], $checkin, 'Checkin');
        log_activity('Goods Received Note ' . $checkin->grn_number . ' generated', ['grn' => $checkin->grn_number, 'checkin' => $checkin->reference, 'generated_by' => $request->user()->name], $checkin, 'Checkin');

        return back()->with('message', __('Checkin :ref approved. Goods Received Note :grn generated.', ['ref' => $checkin->reference, 'grn' => $checkin->grn_number]));
    }

    /** Reject a pending check-in with a reason. It stays out of stock and its creator is told why (they can correct it and submit it again). */
    public function reject(Request $request, Checkin $checkin)
    {
        abort_unless(Checkin::userCanApprove($request->user()), 403, __('You are not allowed to reject checkins.'));
        $data = $request->validate(['rejection_reason' => 'required|string|min:3|max:1000']);

        DB::transaction(function () use ($request, $checkin, $data) {
            $locked = $checkin->newQueryWithoutScopes()->whereKey($checkin->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === Checkin::STATUS_PENDING, 422, __('This checkin is no longer pending approval.'));

            $checkin->forceFill([
                'rejected_by' => $request->user()->id,
                'rejected_at' => now(),
                'rejection_reason' => trim($data['rejection_reason']),
            ])->save();
        });

        $checkin->load(['items.item', 'items.unit', 'warehouse', 'user', 'rejecter']);
        event(new \App\Events\CheckinEvent($checkin, 'rejected'));
        log_activity('Checkin Rejected', ['reference' => $checkin->reference, 'warehouse_id' => $checkin->warehouse_id, 'reason' => $checkin->rejection_reason], $checkin, 'Checkin');

        return back()->with('message', __('Checkin :ref rejected.', ['ref' => $checkin->reference]));
    }

    /** The Goods Received Note of an approved check-in (browser print layout). */
    public function grn(Checkin $checkin)
    {
        $this->authorizeGrn($checkin);

        return view('checkins.grn', $this->grnData($checkin, false));
    }

    public function grnPdf(Checkin $checkin)
    {
        $this->authorizeGrn($checkin);

        return Pdf::loadView('checkins.grn', $this->grnData($checkin, true))
            ->setPaper('a4')
            ->download('grn-' . $checkin->grn_number . '.pdf');
    }

    private function authorizeGrn(Checkin $checkin): void
    {
        $this->authorize('print-checkins');
        abort_unless($checkin->status === Checkin::STATUS_APPROVED && $checkin->grn_number, 404, __('A Goods Received Note exists only for an approved check-in.'));
    }

    private function grnData(Checkin $checkin, bool $isPdf): array
    {
        $checkin->load(['items.item:id,code,name,price', 'items.unit:id,code,name', 'contact', 'warehouse', 'user:id,name', 'approver:id,name', 'grnGenerator:id,name']);
        // every opening of the GRN is written to the activity log (the Download / Print log records the print and the download as well)
        log_activity('Goods Received Note ' . $checkin->grn_number . ($isPdf ? ' downloaded' : ' opened'), ['grn' => $checkin->grn_number, 'checkin' => $checkin->reference], $checkin, 'Checkin');

        return [
            'checkin' => $checkin,
            'doc' => PrintDocuments::grn($checkin),
            'companyName' => optional(auth()->user()->account)->name,
            'qr' => PrintQrCode::dataUri(route('checkins.show', $checkin->id)),
            'isPdf' => $isPdf,
            'pdfUrl' => route('checkins.grn.pdf', $checkin->id),
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('grn', $checkin),
        ];
    }

    private function denyExpiredSiteEngineerEdit(Checkin $checkin)
    {
        $user = auth()->user();
        if (! $user || ! $user->hasRole([...User::SITE_ENGINEER_ROLES, 'Procurement Manager', 'Procurement Manger', 'Store Manager'])) {
            return null;
        }

        $createdAt = $checkin->created_at ?: Carbon::parse($checkin->date);
        if ($createdAt->lt(now()->subDays(3))) {
            return redirect()->route('checkins.index')->with(
                'error',
                __('You can only edit your checkin within 3 days of creation. This transaction is older than 3 days. Please contact Admin for further help.')
            );
        }

        return null;
    }
}
