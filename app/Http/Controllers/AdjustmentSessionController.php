<?php

namespace App\Http\Controllers;

use App\Models\AdjustmentSession;
use App\Models\SuperAdjustment;
use App\Models\Warehouse;
use App\Services\Adjustments\AdjustmentSessionService as Svc;
use App\Support\PrintQrCode;
use App\Traits\GuardsCompletedWarehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * Adjustment Session (Super Adjustment only): start one for a Project / Warehouse, make the Super Adjustments of the count over several days, then end it to
 * see (and, if wanted, clear) the items that were never counted. See App\Services\Adjustments\AdjustmentSessionService.
 */
class AdjustmentSessionController extends Controller
{
    use GuardsCompletedWarehouse;

    public function __construct()
    {
        $this->setAbilities([]); // every action checks its own permission below
    }

    private function need(string $ability): void
    {
        abort_unless(request()->user()->can($ability), 403);
    }

    /** Only warehouses the person may see (a Super Admin sees all). */
    private function visibleWarehouseIds(): array
    {
        return Warehouse::ofAccount()->visible()->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function authorizeSession(AdjustmentSession $s): void
    {
        $this->need('read-adjustment-sessions');
        abort_unless(in_array((int) $s->warehouse_id, $this->visibleWarehouseIds(), true), 403);
    }

    public function index(Request $request)
    {
        $this->need('read-adjustment-sessions');
        $filters = ['search' => trim((string) $request->query('search', '')), 'status' => in_array($request->query('status'), ['Open', 'Ended'], true) ? $request->query('status') : '', 'warehouse_id' => ctype_digit((string) $request->query('warehouse_id', '')) ? (string) $request->query('warehouse_id') : ''];
        $visible = $this->visibleWarehouseIds();
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']) . '%';

        $base = AdjustmentSession::query()->whereIn('warehouse_id', $visible);
        $rows = (clone $base)->with(['warehouse:id,name,code', 'startedBy:id,name', 'endedBy:id,name'])->withCount('superAdjustments')
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['warehouse_id'] !== '', fn ($q) => $q->where('warehouse_id', (int) $filters['warehouse_id']))
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('name', 'like', $like)->orWhereHas('warehouse', fn ($x) => $x->where('name', 'like', $like))))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        $rows->through(function (AdjustmentSession $s) {
            $snap = (array) $s->snapshot;

            return [
                'id' => $s->id, 'reference' => $s->reference, 'name' => $s->name, 'status' => $s->status, 'warehouse' => optional($s->warehouse)->name,
                'started_by' => optional($s->startedBy)->name, 'started_at' => optional($s->started_at)->toIso8601String(), 'ended_by' => optional($s->endedBy)->name, 'ended_at' => optional($s->ended_at)->toIso8601String(),
                'documents' => (int) $s->super_adjustments_count,
                'counted' => $s->isOpen() ? count(Svc::countedItemIds(Svc::documents($s))) : (int) ($snap['counted_items'] ?? 0),
                'not_counted' => $s->isOpen() ? Svc::uncounted($s)->count() : (int) (($snap['cleared_count'] ?? 0) + ($snap['kept_count'] ?? 0)),
                'cleared' => (int) ($snap['cleared_count'] ?? 0),
            ];
        });

        $counts = (clone $base)->toBase()->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $openWh = AdjustmentSession::query()->where('status', 'Open')->pluck('warehouse_id')->map(fn ($i) => (int) $i)->all();
        $user = $request->user();

        return Inertia::render('AdjustmentSession/Index', [
            'sessions' => new \App\Http\Resources\PaymentVoucherCollection($rows),
            'filters' => $filters,
            'stats' => ['total' => (int) $counts->sum(), 'open' => (int) ($counts['Open'] ?? 0), 'ended' => (int) ($counts['Ended'] ?? 0)],
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'code'])->map(fn ($w) => ['value' => $w->id, 'label' => $w->name . ($w->code ? ' (' . $w->code . ')' : ''), 'has_open' => in_array((int) $w->id, $openWh, true)])->values(),
            'can' => ['start' => $user->can('start-adjustment-sessions'), 'count' => $user->can('create-super-adjustments')],
        ]);
    }

    public function store(Request $request)
    {
        $this->need('start-adjustment-sessions');
        $data = $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id', 'name' => 'nullable|string|max:190', 'note' => 'nullable|string|max:2000']);
        abort_unless(Warehouse::ofAccount()->active()->visible()->whereKey($data['warehouse_id'])->exists(), 403, __('You cannot use this Project / Warehouse.'));
        if ($response = $this->rejectIfWarehouseCompleted($data['warehouse_id'])) {
            return $response;
        }

        $s = Svc::start($request->user(), (int) $data['warehouse_id'], $data['name'] ?? null, $data['note'] ?? null);
        log_activity('Adjustment Session ' . $s->reference . ' started', ['warehouse_id' => $s->warehouse_id, 'name' => $s->name], $s, 'Adjustment Session');
        $this->alert('session_started', $s, $request->user(), __('Adjustment Session Started'), $s->reference . ' for ' . optional($s->warehouse)->name . ' was started by ' . $request->user()->name . '.');

        return redirect()->route('adjustment-sessions.show', $s->id)->with('message', __('Adjustment Session :r started. Every Super Adjustment of this Project / Warehouse is added to it until you end it.', ['r' => $s->reference]));
    }

    /** What the Show page, the print and the PDF all read. */
    private function payload(AdjustmentSession $s): array
    {
        $s->loadMissing(['warehouse:id,name,code', 'startedBy:id,name', 'endedBy:id,name', 'clearingSuperAdjustment:id,reference,status']);
        $docs = Svc::documents($s);
        $snap = (array) $s->snapshot;

        if ($s->isOpen()) {
            $counted = Svc::countedItemIds($docs);
            $uncounted = Svc::uncounted($s, $counted)->values();
            $countedN = count($counted);
            $notN = $uncounted->count();
        } else {
            $uncounted = collect($snap['uncounted'] ?? []);
            $countedN = (int) ($snap['counted_items'] ?? 0);
            $notN = $uncounted->count();
        }
        $pending = $docs->filter(fn ($d) => $d->isOpen());
        $stocked = $countedN + $notN;

        return [
            'session' => [
                'id' => $s->id, 'reference' => $s->reference, 'name' => $s->name, 'note' => $s->note, 'status' => $s->status,
                'warehouse' => ['id' => $s->warehouse_id, 'name' => optional($s->warehouse)->name, 'code' => optional($s->warehouse)->code],
                'started_by' => optional($s->startedBy)->name, 'started_at' => optional($s->started_at)->toIso8601String(),
                'ended_by' => optional($s->endedBy)->name, 'ended_at' => optional($s->ended_at)->toIso8601String(), 'end_note' => $s->end_note,
                'clearing' => $s->clearingSuperAdjustment ? ['id' => $s->clearingSuperAdjustment->id, 'reference' => $s->clearingSuperAdjustment->reference, 'status' => $s->clearingSuperAdjustment->status] : null,
            ],
            'documents' => $docs->map(fn ($d) => ['id' => $d->id, 'reference' => $d->reference, 'status' => $d->status, 'date' => (string) $d->date, 'by' => optional($d->user)->name, 'items' => (int) $d->items_count, 'created_at' => optional($d->created_at)->toIso8601String(), 'is_clearing' => (int) $s->clearing_super_adjustment_id === (int) $d->id])->values(),
            'pending' => $pending->pluck('reference')->values(),
            'uncounted' => $uncounted->values(),
            'stats' => ['documents' => $docs->count(), 'counted' => $countedN, 'not_counted' => $notN, 'moved' => $uncounted->where('moved', true)->count(), 'cleared' => (int) ($snap['cleared_count'] ?? 0), 'kept' => (int) ($snap['kept_count'] ?? 0), 'percent' => $stocked > 0 ? round($countedN * 100 / $stocked, 1) : 0],
        ];
    }

    public function show(Request $request, AdjustmentSession $session)
    {
        $this->authorizeSession($session);
        $user = $request->user();

        return Inertia::render('AdjustmentSession/Show', $this->payload($session) + [
            'can' => ['end' => $user->can('end-adjustment-sessions'), 'clear' => $user->can('end-adjustment-sessions') && $user->can('create-super-adjustments'), 'count' => $user->can('create-super-adjustments'), 'print' => $user->can('print-adjustment-sessions')],
        ]);
    }

    public function end(Request $request, AdjustmentSession $session)
    {
        $this->need('end-adjustment-sessions');
        $this->authorizeSession($session);
        $data = $request->validate(['clear_item_ids' => 'nullable|array|max:5000', 'clear_item_ids.*' => 'integer', 'note' => 'nullable|string|max:1000']);
        $clear = array_values(array_unique(array_map('intval', $data['clear_item_ids'] ?? [])));
        if ($clear) {
            $this->need('create-super-adjustments');
            if ($response = $this->rejectIfWarehouseCompleted($session->warehouse_id)) {
                return $response;
            }
        }

        // one end at a time (a double click or two people)
        $lock = Cache::lock('adjustment-session-end-' . $session->id, 60);
        if (! $lock->get()) {
            return back()->with('error', __('This Adjustment Session is being ended right now.'));
        }
        try {
            $session = AdjustmentSession::query()->whereKey($session->id)->first();
            abort_unless($session && $session->isOpen(), 422, __('This Adjustment Session has already ended.'));

            $sac = app(SuperAdjustmentController::class);
            [$ended, $clearing] = Svc::end($request->user(), $session, $clear, $data['note'] ?? null, function (array $d) use ($sac, $request, $session) {
                $sac->submitCount($request, $d, null, $session);

                return $sac->submitted;
            });
        } finally {
            $lock->release();
        }

        log_activity('Adjustment Session ' . $ended->reference . ' ended', ['counted' => $ended->snapshot['counted_items'] ?? 0, 'cleared' => $ended->snapshot['cleared_count'] ?? 0, 'kept' => $ended->snapshot['kept_count'] ?? 0, 'clearing' => optional($clearing)->reference], $ended, 'Adjustment Session');
        $this->alert('session_ended', $ended, $request->user(), __('Adjustment Session Ended'), $ended->reference . ' for ' . optional($ended->warehouse)->name . ' was ended by ' . $request->user()->name . '. ' . Svc::summary($ended), ['values' => ['summary' => Svc::summary($ended)]]);

        return redirect()->route('adjustment-sessions.show', $ended->id)->with('message', __('Adjustment Session :r ended.', ['r' => $ended->reference]) . ' ' . Svc::summary($ended) . ($clearing ? ' ' . __('The clearing count :c was made and follows the normal approvals.', ['c' => $clearing->reference]) : ''));
    }

    public function print(Request $request, AdjustmentSession $session)
    {
        $this->need('print-adjustment-sessions');
        $this->authorizeSession($session);

        return view('adjustment-sessions.print', $this->printData($request, $session, false));
    }

    public function pdf(Request $request, AdjustmentSession $session)
    {
        $this->need('print-adjustment-sessions');
        $this->authorizeSession($session);

        return Pdf::loadView('adjustment-sessions.print', $this->printData($request, $session, true))->setPaper('a4')->download('adjustment-session-' . $session->reference . '.pdf');
    }

    private function printData(Request $request, AdjustmentSession $session, bool $isPdf): array
    {
        return $this->payload($session) + ['companyName' => optional($request->user()->account)->name, 'qr' => PrintQrCode::dataUri(route('adjustment-sessions.show', $session->id)), 'isPdf' => $isPdf];
    }

    private function alert(string $event, AdjustmentSession $s, ?\App\Models\User $actor, string $title, string $message, array $ctx = []): void
    {
        $s->loadMissing('warehouse:id,name');
        \App\Services\Workflow\WorkflowMessenger::dispatch('adjustments', $event, $s, $actor, $ctx + [
            'account_id' => $s->account_id ?? 1, 'title' => $title, 'message' => $message, 'url' => route('adjustment-sessions.show', $s->id),
            'owner_user_id' => (int) $s->started_by_user_id, 'warehouse_ids' => array_filter([$s->warehouse_id]),
            'values' => ($ctx['values'] ?? []) + ['reference' => $s->reference, 'warehouse' => optional($s->warehouse)->name ?? '', 'type' => 'Adjustment Session', 'creator' => optional($s->startedBy)->name ?? ($actor->name ?? '')],
        ]);
    }
}
