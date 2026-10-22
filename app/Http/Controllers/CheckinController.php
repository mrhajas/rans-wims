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
        $filters = $request->all('draft', 'search', 'trashed', 'view');
        // The summary tiles above the table filter the list (same definitions as the tile counts).
        $filters['view'] = in_array($filters['view'] ?? null, ['today', 'month', 'drafts'], true) ? $filters['view'] : null;

        $checkins = Checkin::with(['contact:id,name', 'warehouse:id,name', 'user:id,name'])->filter($filters)
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
            'drafts' => $query->where('draft', 1),
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
            'COUNT(*) as total, COALESCE(SUM(created_at >= ?), 0) as today, COALESCE(SUM(created_at >= ?), 0) as month, COALESCE(SUM(draft = 1), 0) as drafts',
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
        $checkin->load(['items.variations', 'items.item:id,code,name,track_quantity,track_weight,photo', 'contact', 'warehouse', 'items.unit:id,code,name', 'user:id,name,username', 'attachments']);
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
        // Signatories: a completed (not draft) record is the final step; refuse it when a required signature is missing (inert until an administrator switches it on).
        if (empty($data['draft']) && ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('check_in', new \App\Models\Checkin(), ['draft' => false, 'user_id' => $request->user()->id, 'created_at' => now()], __('saved as completed')))) {
            return back()->with('error', $refusal);
        }

        $checkin = \App\Support\ReferenceLock::run('checkins', function () use ($data, $request) {
            $maxSuffix = (int) DB::table('checkins')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', 'RSC-%')
            ->value('max_suffix');

        $data['reference'] = 'RSC-' . str_pad($maxSuffix + 1, 4, '0', STR_PAD_LEFT);


            return (new PrepareOrder($data, $request->file('attachments'), new Checkin()))->process()->save();
        });
        event(new \App\Events\CheckinEvent($checkin, 'created'));
        log_activity('Checkin Created', ['reference' => $checkin->reference, 'warehouse_id' => $checkin->warehouse_id, 'contact_id' => $checkin->contact_id], $checkin, 'Checkin');

        return redirect()->route('checkins.index')->with('message', __choice('action_text', ['record' => 'Checkin', 'action' => 'created']));
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
        // Signatories: finishing a draft is the final step (see store()). Saving an already completed record again changes nothing here.
        if ($checkin->draft && empty($data['draft']) && ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('check_in', $checkin, ['draft' => false, 'created_at' => now()], __('saved as completed')))) {
            return back()->with('error', $refusal);
        }
        $wasFinal = ! $checkin->draft;
        $original = $checkin->load(['items.item', 'items.unit', 'items.variations'])->replicate();
        $checkin = (new PrepareOrder($data, $request->file('attachments'), $checkin))->process()->save();
        event(new \App\Events\CheckinEvent($checkin, 'updated', $original));
        if ($wasFinal && $checkin->draft) {
            \App\Services\Signatories\Documents\StockSigningGuard::forget('check_in', (int) $checkin->id);   // taken back to draft: the next completion freezes afresh
        }
        log_activity('Checkin Updated', ['reference' => $checkin->reference, 'warehouse_id' => $checkin->warehouse_id, 'contact_id' => $checkin->contact_id], $checkin, 'Checkin');
        session()->flash('message', __choice('action_text', ['record' => 'Checkin', 'action' => 'updated']));

        return $request->listing == 'yes' ? redirect()->route('checkins.index') : back();
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
