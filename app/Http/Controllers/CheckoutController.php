<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Carbon\Carbon;
use App\Models\Contact;
use App\Models\Checkout;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Actions\Tec\PrepareOrder;
use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\CheckoutResource;
use App\Http\Resources\CheckoutCollection;
use App\Traits\GuardsCompletedWarehouse;
use App\Traits\HasActivityHistory;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;

class CheckoutController extends Controller
{
    use GuardsCompletedWarehouse;
    use HasActivityHistory;

    public function __construct()
    {
        $this->setAbilities([
            'index'               => 'read',
            'show'                => 'read',
            'create'              => 'create',
            'store'               => 'create',
            'destroy'             => 'delete',
            'destroy-permanently' => 'delete',
        ]);
    }

    public function create()
    {
        $activities = WorkActivity::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Checkout/Form', [
            'contacts'           => Contact::ofAccount()->get(),
            'warehouses'         => Warehouse::ofAccount()->active()->visible()->get(),
            'activities'         => $activities,
            'default_activity_id' => optional(
                $activities->firstWhere('name', 'Construction') ?? $activities->first()
            )->id,
        ]);
    }

    public function destroy(Checkout $checkout)
    {
        if ($response = $this->rejectIfWarehouseBlocked($checkout->warehouse_id)) {
            return $response;
        }
        $checkout->load(['items.item', 'items.unit', 'items.variations']);
        if ($checkout->del()) {
            event(new \App\Events\CheckoutEvent($checkout, 'deleted'));

            return redirect()->route('checkouts.index')->with('message', __choice('action_text', ['record' => 'Checkout', 'action' => 'deleted']));
        }

        return redirect()->route('checkouts.index')->with('error', __('The record can not be deleted.'));
    }

    public function destroyPermanently(Checkout $checkout)
    {
        if ($response = $this->rejectIfWarehouseBlocked($checkout->warehouse_id)) {
            return $response;
        }
        $checkout->load(['items.item', 'items.unit', 'items.variations']);
        if ($checkout->delP()) {
            $event = new \App\Events\CheckoutEvent($checkout, 'deleted');
            $event->permanent = true;
            event($event);

            return redirect()->route('checkouts.index')->with('message', __choice('action_text', ['record' => 'Checkout', 'action' => 'permanently deleted']));
        }

        return redirect()->route('checkouts.index')->with('error', __('The record can not be deleted.'));
    }

    public function edit(Checkout $checkout)
    {
        if (! $this->canForceEdit($checkout)) {
            $this->authorize('update', $checkout);
        }
        if ($response = $this->denyExpiredSiteEngineerEdit($checkout)) {
            return $response;
        }
        if ($response = $this->rejectIfWarehouseBlocked($checkout->warehouse_id)) {
            return $response;
        }

        return Inertia::render('Checkout/Form', [
            'contacts'   => Contact::ofAccount()->get(),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(),
            'activities' => WorkActivity::orderBy('name')->get(['id', 'name']),
            'edit'       => new CheckoutResource($checkout->load(['items.variations', 'items.item.variations', 'attachments'])),
            'history'    => $this->activityHistory(Checkout::class, $checkout->id),
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->all('draft', 'search', 'trashed', 'view');
        // The summary tiles above the table filter the list (same definitions as the tile counts).
        $filters['view'] = in_array($filters['view'] ?? null, ['today', 'month', 'drafts'], true) ? $filters['view'] : null;

        return Inertia::render('Checkout/Index', [
            'filters'   => $filters,
            'stats'     => $this->indexStats(),
            'checkouts' => new CheckoutCollection(
                Checkout::with(['contact:id,name', 'warehouse:id,name', 'user:id,name', 'workActivity:id,name'])->filter($filters)
                    ->when($filters['view'], fn ($q, $view) => $this->applyTile($q, $view))
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
        $base = Checkout::query()
            ->when(warehouse_scope_ids() !== null, fn ($q) => $q->withoutGlobalScope('mine')->whereIn('warehouse_id', warehouse_scope_ids()));

        $startOfDay = now()->startOfDay()->toDateTimeString();
        $startOfMonth = now()->startOfMonth()->toDateTimeString();

        $row = (clone $base)->toBase()->selectRaw(
            'COUNT(*) as total, COALESCE(SUM(created_at >= ?), 0) as today, COALESCE(SUM(created_at >= ?), 0) as month, COALESCE(SUM(draft = 1), 0) as drafts',
            [$startOfDay, $startOfMonth]
        )->first();

        $quantity = \Illuminate\Support\Facades\DB::table('checkout_items')
            ->whereNull('deleted_at')
            ->whereIn('checkout_id', (clone $base)->select('id')->toBase())
            ->sum('quantity');

        return [
            'total' => (int) ($row->total ?? 0),
            'today' => (int) ($row->today ?? 0),
            'month' => (int) ($row->month ?? 0),
            'drafts' => (int) ($row->drafts ?? 0),
            'quantity' => (float) $quantity,
        ];
    }

    public function restore(Checkout $checkout)
    {
        abort_unless(auth()->user()->can('delete-checkouts'), 403);

        $checkout->restore();
        $checkout->items->each->restore();
        event(new \App\Events\CheckoutEvent($checkout, 'restored'));

        return back()->with('message', __choice('action_text', ['record' => 'Checkout', 'action' => 'restored']));
    }

    public function show(Request $request, Checkout $checkout)
    {
        $checkout->load(['items.variations', 'items.item:id,code,name,track_quantity,track_weight,photo', 'contact', 'warehouse', 'workActivity', 'items.unit:id,code,name', 'user:id,name,username', 'attachments']);

        return $request->json ? $checkout : Inertia::render('Checkout/Show', [
            'checkout' => new CheckoutResource($checkout),
            'history' => $this->activityHistory(Checkout::class, $checkout->id),
        ]);
    }

    public function print(Checkout $checkout)
    {
        $this->authorize('print-checkouts');
        $checkout->load(['items.item:id,code,name', 'items.unit:id,code,name', 'contact', 'warehouse', 'workActivity', 'user:id,name']);

        return view('checkouts.print', $this->printData($checkout, false));
    }

    public function pdf(Checkout $checkout)
    {
        $this->authorize('print-checkouts');
        $checkout->load(['items.item:id,code,name', 'items.unit:id,code,name', 'contact', 'warehouse', 'workActivity', 'user:id,name']);

        return Pdf::loadView('checkouts.print', $this->printData($checkout, true))
            ->setPaper('a4')
            ->download('checkout-' . $checkout->reference . '.pdf');
    }

    private function printData(Checkout $checkout, bool $isPdf): array
    {
        return [
            'checkout' => $checkout,
            'companyName' => optional(auth()->user()->account)->name,
            'qr' => PrintQrCode::dataUri(route('checkouts.show', $checkout->id)),
            'isPdf' => $isPdf,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('check_out', $checkout),
        ];
    }

    public function store(CheckoutRequest $request)
    {
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseBlocked($data['warehouse_id'] ?? null)) {
            return $response;
        }
        // Signatories: a completed (not draft) record is the final step; refuse it when a required signature is missing (inert until an administrator switches it on).
        if (empty($data['draft']) && ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('check_out', new \App\Models\Checkout(), ['draft' => false, 'user_id' => $request->user()->id, 'created_at' => now()], __('saved as completed')))) {
            return back()->with('error', $refusal);
        }

        $checkout = \App\Support\ReferenceLock::run('checkouts', function () use ($data, $request) {
            $maxSuffix = (int) DB::table('checkouts')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', 'RSH-%')
            ->value('max_suffix');

        $data['reference'] = 'RSH-' . str_pad($maxSuffix + 1, 4, '0', STR_PAD_LEFT);

            return (new PrepareOrder($data, $request->file('attachments'), new Checkout()))->process()->save();
        });
        event(new \App\Events\CheckoutEvent($checkout, 'created'));
        log_activity('Checkout Created', ['reference' => $checkout->reference, 'warehouse_id' => $checkout->warehouse_id, 'contact_id' => $checkout->contact_id], $checkout, 'Checkout');

        return redirect()->route('checkouts.index')->with('message', __choice('action_text', ['record' => 'Checkout', 'action' => 'created']));
    }

    public function update(CheckoutRequest $request, Checkout $checkout)
    {
        if (! $this->canForceEdit($checkout)) {
            $this->authorize('update', $checkout);
        }
        if ($response = $this->denyExpiredSiteEngineerEdit($checkout)) {
            return $response;
        }
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseBlocked($checkout->warehouse_id, $data['warehouse_id'] ?? null)) {
            return $response;
        }
        // Signatories: finishing a draft is the final step (see store()). Saving an already completed record again changes nothing here.
        if ($checkout->draft && empty($data['draft']) && ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('check_out', $checkout, ['draft' => false, 'created_at' => now()], __('saved as completed')))) {
            return back()->with('error', $refusal);
        }
        $wasFinal = ! $checkout->draft;
        $original = $checkout->load(['items.item', 'items.unit', 'items.variations'])->replicate();
        $checkout = (new PrepareOrder($data, $request->file('attachments'), $checkout))->process()->save();
        event(new \App\Events\CheckoutEvent($checkout, 'updated', $original));
        if ($wasFinal && $checkout->draft) {
            \App\Services\Signatories\Documents\StockSigningGuard::forget('check_out', (int) $checkout->id);   // taken back to draft: the next completion freezes afresh
        }
        log_activity('Checkout Updated', ['reference' => $checkout->reference, 'warehouse_id' => $checkout->warehouse_id, 'contact_id' => $checkout->contact_id], $checkout, 'Checkout');
        session()->flash('message', __choice('action_text', ['record' => 'Checkout', 'action' => 'updated']));

        return $request->listing == 'yes' ? redirect()->route('checkouts.index') : back();
    }

    private function denyExpiredSiteEngineerEdit(Checkout $checkout)
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return null;
        }

        $transactionDate = $checkout->date
            ? Carbon::parse($checkout->date)->endOfDay()
            : Carbon::parse($checkout->created_at);

        if ($user->hasRole(['Procurement Manager', 'Procurement Manger']) && $transactionDate->lt(now()->subDays(3))) {
            return redirect()->route('checkouts.index')->with(
                'error',
                __('Procurement Manager can only edit checkout transactions within 3 days of the transaction date. This transaction is older than 3 days. Please contact Admin for further help.')
            );
        }

        if ($user->hasRole(['Store Manager']) && $transactionDate->lt(now()->subDays(3))) {
            return redirect()->route('checkouts.index')->with(
                'error',
                __('Store Manager can only edit checkout transactions within 3 days of the transaction date. This transaction is older than 3 days. Please contact Admin for further help.')
            );
        }

        if ($user->hasRole(User::SITE_ENGINEER_ROLES) && $transactionDate->lt(now()->subDays(3))) {
            return redirect()->route('checkouts.index')->with(
                'error',
                __('You can only edit your checkout within 3 days of the transaction date. This transaction is older than 3 days. Please contact Admin for further help.')
            );
        }

        return null;
    }

    private function canForceEdit(Checkout $checkout): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->hasRole(['Super Admin', 'Admin']) || (bool) $user->edit_all;
    }
}
