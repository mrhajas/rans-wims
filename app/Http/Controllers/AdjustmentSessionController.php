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
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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
        $rows = (clone $base)->with(['warehouse:id,name,code', 'startedBy:id,name', 'endedBy:id,name', 'haltedBy:id,name'])->withCount(['superAdjustments' => fn ($q) => $q->withoutGlobalScope('mine')])
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['warehouse_id'] !== '', fn ($q) => $q->where('warehouse_id', (int) $filters['warehouse_id']))
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('name', 'like', $like)->orWhereHas('warehouse', fn ($x) => $x->where('name', 'like', $like))))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        $rows->through(function (AdjustmentSession $s) {
            $snap = (array) $s->snapshot;

            return [
                'id' => $s->id, 'reference' => $s->reference, 'name' => $s->name, 'status' => $s->status, 'warehouse' => optional($s->warehouse)->name,
                'started_by' => optional($s->startedBy)->name, 'started_at' => optional($s->started_at)->toIso8601String(), 'ended_by' => optional($s->endedBy)->name, 'ended_at' => optional($s->ended_at)->toIso8601String(),
                'documents' => (int) $s->super_adjustments_count, 'halted' => $s->isHalting(), 'halted_by' => $s->isHalting() ? optional($s->haltedBy)->name : null,
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
            'can' => ['start' => $user->can('start-adjustment-sessions'), 'count' => $user->can('create-super-adjustments'), 'stop' => $user->can('stop-stock-movement')],
        ]);
    }

    /** The person's own password, asked before the stock movement of a warehouse is stopped or released. */
    private function confirmPassword(Request $request, string $what): void
    {
        if (! Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            log_activity('Adjustment Session ' . $what . ' refused: wrong password', [], null, 'Adjustment Session');
            \App\Services\SecurityAlert::note((string) $request->ip(), 'wrong password on ' . $what);
            throw ValidationException::withMessages(['password' => [__('The password is not correct.')]]);
        }
    }

    public function store(Request $request)
    {
        $this->need('start-adjustment-sessions');
        $data = $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id', 'name' => 'nullable|string|max:190', 'note' => 'nullable|string|max:2000', 'halt_stock' => 'nullable|boolean', 'password' => 'nullable|string|max:1024']);
        abort_unless(Warehouse::ofAccount()->active()->visible()->whereKey($data['warehouse_id'])->exists(), 403, __('You cannot use this Project / Warehouse.'));
        if ($response = $this->rejectIfWarehouseCompleted($data['warehouse_id'])) {
            return $response;
        }
        $halt = ! empty($data['halt_stock']);
        if ($halt) {
            abort_unless($request->user()->can('stop-stock-movement'), 403, __('You do not have permission to stop stock movement.'));
            $this->confirmPassword($request, 'stopping stock movement');
        }

        $s = Svc::start($request->user(), (int) $data['warehouse_id'], $data['name'] ?? null, $data['note'] ?? null, $halt);
        log_activity('Adjustment Session ' . $s->reference . ' started', ['warehouse_id' => $s->warehouse_id, 'name' => $s->name, 'stock_movement_halted' => $halt], $s, 'Adjustment Session');
        $this->alert('session_started', $s, $request->user(), __('Adjustment Session Started'), $s->reference . ' for ' . optional($s->warehouse)->name . ' was started by ' . $request->user()->name . '.' . ($halt ? ' ' . __('Stock movement of this Project / Warehouse is halted.') : ''));
        if ($halt) {
            $this->alert('stock_halted', $s, $request->user(), __('Stock Movement Halted'), __('Stock movement of :w was halted by :n for the count in :r.', ['w' => optional($s->warehouse)->name, 'n' => $request->user()->name, 'r' => $s->reference]));
        }

        return redirect()->route('adjustment-sessions.show', $s->id)->with('message', __('Adjustment Session :r started. Every Super Adjustment of this Project / Warehouse is added to it until you end it.', ['r' => $s->reference]) . ($halt ? ' ' . __('Stock movement of this Project / Warehouse is halted until the session ends.') : ''));
    }

    /** Stop (halt = true) or release (halt = false) the stock movement of the session's warehouse while it stays open. Needs the permission and the person's password. */
    public function halt(Request $request, AdjustmentSession $session)
    {
        $this->need('stop-stock-movement');
        $this->authorizeSession($session);
        $data = $request->validate(['halt' => 'required|boolean', 'password' => 'required|string|max:1024']);
        $this->confirmPassword($request, $data['halt'] ? 'stopping stock movement' : 'releasing stock movement');
        $warehouse = optional($session->warehouse)->name;

        if ($data['halt']) {
            if (! Svc::halt($request->user(), $session)) {
                return back()->with('error', $session->isOpen() ? __('Stock movement of this Project / Warehouse is already halted.') : __('This Adjustment Session has already ended.'));
            }
            log_activity('Adjustment Session ' . $session->reference . ': stock movement halted', ['warehouse' => $warehouse], $session, 'Adjustment Session');
            $this->alert('stock_halted', $session, $request->user(), __('Stock Movement Halted'), __('Stock movement of :w was halted by :n for the count in :r.', ['w' => $warehouse, 'n' => $request->user()->name, 'r' => $session->reference]));

            return back()->with('message', __('Stock movement of :w is halted. Nobody can receive, issue or move stock there until the session ends or you release it.', ['w' => $warehouse]));
        }
        if (! Svc::release($request->user(), $session)) {
            return back()->with('error', $session->isOpen() ? __('Stock movement of this Project / Warehouse is not halted.') : __('This Adjustment Session has already ended.'));
        }
        log_activity('Adjustment Session ' . $session->reference . ': stock movement released', ['warehouse' => $warehouse], $session, 'Adjustment Session');
        $this->alert('stock_released', $session, $request->user(), __('Stock Movement Released'), __('Stock movement of :w was released by :n (:r).', ['w' => $warehouse, 'n' => $request->user()->name, 'r' => $session->reference]));

        return back()->with('message', __('Stock movement of :w is released. Stock can move again.', ['w' => $warehouse]));
    }

    /**
     * The audit trail of the session, oldest first: who started it, halted / released stock movement, made each Super Adjustment, approved or rejected it, deleted it, ended the session.
     * Built from the session's own columns and log (so it survives edits) and from the Super Adjustments it holds, deleted ones included.
     */
    private function history(AdjustmentSession $s, $docs): array
    {
        $ev = [];
        $add = function (?string $at, ?string $by, string $kind, string $text, ?int $docId = null, ?string $ref = null) use (&$ev) {
            if ($at) {
                $ev[] = ['at' => $at, 'by' => $by ?: __('System'), 'kind' => $kind, 'text' => $text, 'doc_id' => $docId, 'ref' => $ref];
            }
        };
        $generic = '/has been (created|updated)\.?$/i';
        $logs = \Spatie\Activitylog\Models\Activity::query()->where('subject_type', AdjustmentSession::class)->where('subject_id', $s->id)->with('causer:id,name')->orderBy('id')->get();
        $sessionLogs = $logs->reject(fn ($a) => preg_match($generic, (string) $a->description));
        foreach ($sessionLogs as $a) {
            $d = (string) $a->description;
            $kind = str_contains($d, 'started') ? 'started' : (str_contains($d, 'ended') ? 'ended' : (str_contains($d, 'released') ? 'released' : (str_contains($d, 'halted') ? 'halted' : (str_contains($d, 'refused') ? 'refused' : 'note'))));
            $add(optional($a->created_at)->toIso8601String(), optional($a->causer)->name, $kind, $d);
        }
        // the columns back the log up (a session started before the log was kept, or a log line that was cleared)
        if (! $sessionLogs->contains(fn ($a) => str_contains((string) $a->description, 'started'))) {
            $add(optional($s->started_at)->toIso8601String(), optional($s->startedBy)->name, 'started', 'Adjustment Session ' . $s->reference . ' started');
        }
        if ($s->ended_at && ! $sessionLogs->contains(fn ($a) => str_contains((string) $a->description, 'ended'))) {
            $add(optional($s->ended_at)->toIso8601String(), optional($s->endedBy)->name, 'ended', 'Adjustment Session ' . $s->reference . ' ended');
        }

        $all = SuperAdjustment::withTrashed()->withoutGlobalScope('mine')->where('adjustment_session_id', $s->id)->with(['user:id,name', 'approver:id,name', 'rejecter:id,name'])->get();
        foreach ($all as $d) {
            $add(optional($d->created_at)->toIso8601String(), optional($d->user)->name, 'count', __('Super Adjustment :r made', ['r' => $d->reference]) . ((int) $s->clearing_super_adjustment_id === (int) $d->id ? ' (' . __('clearing count') . ')' : ''), $d->id, $d->reference);
            $add(optional($d->approved_at)->toIso8601String(), optional($d->approver)->name, 'approved', __('Super Adjustment :r approved', ['r' => $d->reference]), $d->id, $d->reference);
            $add(optional($d->rejected_at)->toIso8601String(), optional($d->rejecter)->name, 'rejected', __('Super Adjustment :r rejected', ['r' => $d->reference]) . ($d->rejection_reason ? ': ' . $d->rejection_reason : ''), $d->id, $d->reference);
            if ($d->deleted_at) {
                $add(optional($d->deleted_at)->toIso8601String(), $d->deleted_by ? optional(\App\Models\User::find($d->deleted_by))->name : null, 'deleted', __('Super Adjustment :r deleted', ['r' => $d->reference]), $d->id, $d->reference);
            }
        }
        // what the log says about those Super Adjustments beyond made / approved / rejected (phase 1 approvals, status changes, refused passwords)
        if ($all->isNotEmpty()) {
            $byId = $all->keyBy('id');
            foreach (\Spatie\Activitylog\Models\Activity::query()->where('subject_type', SuperAdjustment::class)->whereIn('subject_id', $all->pluck('id'))->with('causer:id,name')->orderBy('id')->get() as $a) {
                $d = (string) $a->description;
                if (preg_match($generic, $d) || in_array($d, ['Super Adjustment Approved', 'Super Adjustment Rejected'], true) || str_contains($d, 'deleted')) {
                    continue;
                }
                $doc = $byId->get($a->subject_id);
                $add(optional($a->created_at)->toIso8601String(), optional($a->causer)->name, 'note', ($doc ? $doc->reference . ': ' : '') . $d, $a->subject_id, optional($doc)->reference);
            }
        }
        usort($ev, fn ($x, $y) => strcmp($x['at'], $y['at']));

        return $ev;
    }

    /** What the Show page, the print and the PDF all read. */
    private function payload(AdjustmentSession $s): array
    {
        $s->loadMissing(['warehouse:id,name,code', 'startedBy:id,name', 'endedBy:id,name', 'haltedBy:id,name', 'haltReleasedBy:id,name']);
        $clearingDoc = $s->clearing_super_adjustment_id ? SuperAdjustment::withoutGlobalScope('mine')->find($s->clearing_super_adjustment_id, ['id', 'reference', 'status']) : null;
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
                'halt' => ['active' => $s->isHalting(), 'ever' => $s->halted_at !== null, 'by' => optional($s->haltedBy)->name, 'at' => optional($s->halted_at)->toIso8601String(), 'released_by' => optional($s->haltReleasedBy)->name, 'released_at' => optional($s->halt_released_at)->toIso8601String()],
                'clearing' => $clearingDoc ? ['id' => $clearingDoc->id, 'reference' => $clearingDoc->reference, 'status' => $clearingDoc->status] : null,
            ],
            'documents' => $docs->map(fn ($d) => [
                'id' => $d->id, 'reference' => $d->reference, 'status' => $d->status, 'date' => (string) $d->date, 'by' => optional($d->user)->name, 'items' => (int) $d->items_count,
                'created_at' => optional($d->created_at)->toIso8601String(), 'reason' => $d->reason,
                'approved_by' => optional($d->approver)->name, 'approved_at' => optional($d->approved_at)->toIso8601String(),
                'rejected_by' => optional($d->rejecter)->name, 'rejected_at' => optional($d->rejected_at)->toIso8601String(), 'rejection_reason' => $d->rejection_reason,
                'is_clearing' => (int) $s->clearing_super_adjustment_id === (int) $d->id,
            ])->values(),
            'history' => $this->history($s, $docs),
            'pending' => $pending->pluck('reference')->values(),
            'uncounted' => $uncounted->values(),
            'stats' => ['not_counted_value' => round((float) $uncounted->sum('value'), 2), 'documents' => $docs->count(), 'counted' => $countedN, 'not_counted' => $notN, 'moved' => $uncounted->where('moved', true)->count(), 'cleared' => (int) ($snap['cleared_count'] ?? 0), 'kept' => (int) ($snap['kept_count'] ?? 0), 'percent' => $stocked > 0 ? round($countedN * 100 / $stocked, 1) : 0],
        ];
    }

    public function show(Request $request, AdjustmentSession $session)
    {
        $this->authorizeSession($session);
        $user = $request->user();

        return Inertia::render('AdjustmentSession/Show', $this->payload($session) + [
            'can' => ['end' => $user->can('end-adjustment-sessions'), 'clear' => $user->can('end-adjustment-sessions') && $user->can('create-super-adjustments'), 'count' => $user->can('create-super-adjustments'), 'print' => $user->can('print-adjustment-sessions'), 'stop' => $user->can('stop-stock-movement')],
        ]);
    }

    public function end(Request $request, AdjustmentSession $session)
    {
        $this->need('end-adjustment-sessions');
        $this->authorizeSession($session);
        $data = $request->validate(['clear_item_ids' => 'nullable|array|max:20000', 'clear_item_ids.*' => 'integer', 'note' => 'nullable|string|max:1000']);
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
            // ONE transaction: the session row is locked, so a count being saved at the same moment waits and then finds the session ended; if anything fails nothing is left half done
            [$ended, $clearing] = \Illuminate\Support\Facades\DB::transaction(function () use ($session, $request, $clear, $data) {
                $locked = AdjustmentSession::query()->whereKey($session->id)->lockForUpdate()->first();
                abort_unless($locked && $locked->isOpen(), 422, __('This Adjustment Session has already ended.'));

                $sac = app(SuperAdjustmentController::class);

                return Svc::end($request->user(), $locked, $clear, $data['note'] ?? null, function (array $d) use ($sac, $request, $locked) {
                    $sac->submitCount($request, $d, null, $locked);

                    return $sac->submitted;
                });
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
