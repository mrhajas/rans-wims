<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctApproval;
use App\Models\Accounts\AcctAuditEvent;
use App\Models\Accounts\AcctExchangeRate;
use App\Models\Accounts\AcctTransaction;
use App\Models\Accounts\AcctTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounts\AcctPosting;
use App\Services\Accounts\AcctRates;
use App\Services\Accounts\AcctSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Money moving between two company accounts. Never income or expense. Same currency or across currencies at an approved rate. */
class TransferController extends AcctController
{
    public function index(Request $request)
    {
        $this->need('read-acct-transfers', 'create-acct-transfers', 'approve-acct-transfers', 'post-acct-transfers');
        $q = $this->scopeTransfers(AcctTransfer::query())
            ->when(array_key_exists((string) $request->query('status'), AcctTransaction::STATUS), fn ($w) => $w->where('status', $request->query('status')))
            ->when(ctype_digit((string) $request->query('account_id')), fn ($w) => $w->where(fn ($x) => $x->where('from_account_id', (int) $request->query('account_id'))->orWhere('to_account_id', (int) $request->query('account_id'))))
            ->when($request->query('from'), fn ($w, $v) => strtotime($v) ? $w->whereDate('transfer_date', '>=', $v) : $w)->when($request->query('to'), fn ($w, $v) => strtotime($v) ? $w->whereDate('transfer_date', '<=', $v) : $w)
            ->when(trim((string) $request->query('q')) !== '', fn ($w) => $w->where(fn ($x) => $x->where('reference', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($request->query('q'))) . '%')->orWhere('reason', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($request->query('q'))) . '%')));
        $names = $this->visibleNames();
        $rows = $q->orderByDesc('id')->paginate(20)->withQueryString();
        $rows->getCollection()->transform(fn ($t) => $this->row($t, $names));

        return Inertia::render('Accounts/Transfers/Index', $this->shared() + [
            'rows' => $rows, 'filters' => $request->only('q', 'status', 'account_id', 'from', 'to'), 'accounts' => $this->accountOptions(null, false),
            'statuses' => collect(AcctTransaction::STATUS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(), 'can' => ['create' => $this->me()->can('create-acct-transfers')],
        ]);
    }

    public function create(Request $request)
    {
        $this->need('create-acct-transfers');
        $all = $this->accountOptions();

        return Inertia::render('Accounts/Transfers/Form', $this->shared() + $this->formProps(null) + ['preset' => ['from' => (int) $request->query('from_account_id') ?: null]]);
    }

    public function store(Request $request)
    {
        $this->need('create-acct-transfers');
        $d = $this->validated($request);

        return $this->run(function () use ($d, $request) {
            $svc = app(AcctPosting::class);
            $tr = $svc->createTransfer($d, $this->me());
            $msg = __('Saved as a draft.');
            if ($request->boolean('submit')) {
                $tr = $svc->submitTransfer($tr, $this->me());
                $msg = __('Submitted for approval. Both balances change only after it is approved and posted.');
            }

            return redirect()->route('acct.transfers.show', $tr->id)->with('message', $msg);
        });
    }

    public function show(int $id)
    {
        $this->need('read-acct-transfers', 'create-acct-transfers', 'approve-acct-transfers', 'post-acct-transfers');
        $t = $this->orFail($id);
        $me = $this->me();
        $trail = AcctApproval::query()->where('subject_type', 'transfer')->where('subject_id', $t->id)->orderBy('id')->get();
        $history = $me->can('view-acct-audit') ? AcctAuditEvent::query()->where('entity_type', 'transfer')->where('entity_id', $t->id)->orderByDesc('id')->limit(30)->get() : collect();
        $users = User::query()->whereIn('id', array_filter(array_merge([$t->created_by, $t->submitted_by, $t->posted_by], $trail->pluck('user_id')->all(), $history->pluck('user_id')->all())))->pluck('name', 'id');
        $names = $this->visibleNames();
        $self = in_array((int) $me->id, [(int) $t->created_by, (int) $t->submitted_by], true);

        return Inertia::render('Accounts/Transfers/Show', $this->shared() + [
            't' => $this->row($t, $names) + $t->only(['reason', 'notes', 'external_ref', 'department', 'reject_reason', 'cancel_reason', 'required_approvals', 'approvals_count', 'rounding_difference', 'fee_amount', 'exchange_rate'])
                + ['created_by_name' => $users[$t->created_by] ?? null, 'posted_by_name' => $users[$t->posted_by] ?? null, 'posted_at' => optional($t->posted_at)->toDateTimeString(), 'created_at' => $t->created_at->toDateTimeString(),
                    'rate_type' => $t->rate_id ? optional(AcctExchangeRate::query()->find($t->rate_id))->rate_type : null, 'project' => $t->warehouse_id ? optional(Warehouse::withoutGlobalScopes()->find($t->warehouse_id))->name : null],
            'legs' => AcctTransaction::query()->where('transfer_id', $t->id)->orderBy('id')->get(['id', 'reference', 'account_id', 'direction', 'kind', 'amount', 'currency', 'status'])->map(fn ($l) => $l->toArray() + ['account' => $names[$l->account_id] ?? null]),
            'timeline' => $trail->map(fn ($a) => ['action' => $a->action, 'user' => $users[$a->user_id] ?? '-', 'comment' => $a->comment, 'at' => $a->created_at->toDateTimeString()])->values(),
            'history' => $history->map(fn ($e) => ['event' => $e->event, 'user' => $users[$e->user_id] ?? null, 'reason' => $e->reason, 'at' => $e->created_at->toDateTimeString()])->values(),
            'can' => [
                'edit' => in_array($t->status, ['draft', 'rejected'], true) && ((int) $t->created_by === (int) $me->id || $me->hasRole('Super Admin')),
                'submit' => in_array($t->status, ['draft', 'rejected'], true) && $me->can('create-acct-transfers'),
                'approve' => $t->status === 'submitted' && $me->can('approve-acct-transfers') && (! $self || ! AcctSettings::get()['maker_checker']) && ! $trail->where('action', 'approved')->where('user_id', $me->id)->count(),
                'reject' => in_array($t->status, ['submitted', 'approved'], true) && $me->can('approve-acct-transfers'),
                'post' => $t->status === 'approved' && $me->can('post-acct-transfers'),
                'cancel' => in_array($t->status, ['draft', 'submitted', 'approved', 'rejected'], true) && ((int) $t->created_by === (int) $me->id || $me->can('approve-acct-transfers')),
                'reverse' => $t->status === 'posted' && $me->can('reverse-acct-transactions'),
            ],
        ]);
    }

    public function edit(int $id)
    {
        $t = $this->orFail($id);
        abort_unless(in_array($t->status, ['draft', 'rejected'], true) && ((int) $t->created_by === (int) $this->me()->id || $this->me()->hasRole('Super Admin')), 403);

        return Inertia::render('Accounts/Transfers/Form', $this->shared() + $this->formProps($t));
    }

    public function update(Request $request, int $id)
    {
        $t = $this->orFail($id);
        abort_unless(in_array($t->status, ['draft', 'rejected'], true) && ((int) $t->created_by === (int) $this->me()->id || $this->me()->hasRole('Super Admin')), 403);
        $d = $this->validated($request);

        // A draft is replaced as a whole: cancel nothing, edit in place by recomputing through a fresh create then removing the old draft only if unused.
        return $this->run(function () use ($t, $d, $request) {
            $svc = app(AcctPosting::class);
            $new = $svc->updateTransfer($t, $d, $this->me());
            if ($request->boolean('submit')) {
                $svc->submitTransfer($new, $this->me());
            }

            return redirect()->route('acct.transfers.show', $new->id)->with('message', __('Saved.'));
        });
    }

    public function submit(int $id)
    {
        $t = $this->orFail($id);

        return $this->run(function () use ($t) {
            $r = app(AcctPosting::class)->submitTransfer($t, $this->me());

            return back()->with('message', $r->status === 'approved' ? __('Submitted. No approval is needed: it can be posted.') : __('Submitted for approval.'));
        });
    }

    public function approve(Request $request, int $id)
    {
        $t = $this->orFail($id);
        $c = $request->validate(['comment' => 'nullable|string|max:500'])['comment'] ?? null;

        return $this->run(function () use ($t, $c) {
            $r = app(AcctPosting::class)->approveTransfer($t, $this->me(), $c);

            return back()->with('message', $r->status === 'approved' ? __('Approved. It can now be posted.') : __('Approval recorded (:a of :n).', ['a' => $r->approvals_count, 'n' => $r->required_approvals]));
        });
    }

    public function reject(Request $request, int $id)
    {
        $t = $this->orFail($id);

        return $this->run(fn () => app(AcctPosting::class)->rejectTransfer($t, $this->me(), $request->validate(['reason' => 'required|string|max:500'])['reason']), __('Rejected.'));
    }

    public function cancel(Request $request, int $id)
    {
        $t = $this->orFail($id);

        return $this->run(fn () => app(AcctPosting::class)->cancelTransfer($t, $this->me(), $request->validate(['reason' => 'required|string|max:500'])['reason']), __('Cancelled.'));
    }

    public function post(int $id)
    {
        $t = $this->orFail($id);

        return $this->run(fn () => app(AcctPosting::class)->postTransfer($t, $this->me()), __('Posted. Both balances are updated.'));
    }

    public function reverse(Request $request, int $id)
    {
        $t = $this->orFail($id);

        return $this->run(fn () => app(AcctPosting::class)->reverseTransfer($t, $this->me(), $request->validate(['reason' => 'required|string|max:500'])['reason']), __('Reversed. The original is kept.'));
    }

    /** JSON for the form: the approved rate between two currencies, so a currency transfer shows what applies before anything is posted. */
    public function rate(Request $request)
    {
        $this->need('create-acct-transfers');
        $f = strtoupper((string) $request->query('from'));
        $t = strtoupper((string) $request->query('to'));
        $r = preg_match('/^[A-Z]{3}$/', $f) && preg_match('/^[A-Z]{3}$/', $t) ? AcctRates::find($f, $t, $request->query('date')) : null;

        return response()->json(['rate' => $r]);
    }

    // ---------------------------------------------------------------------------------------------

    private function orFail(int $id): AcctTransfer
    {
        $t = AcctTransfer::query()->findOrFail($id);
        // a transfer is open only to someone who can see BOTH accounts: seeing one end must never let them read or decide on the other
        $ids = $this->visibleAccountIds();
        abort_if($ids !== null && (! in_array((int) $t->from_account_id, $ids, true) || ! in_array((int) $t->to_account_id, $ids, true)), 404);

        return $t;
    }

    private function row(AcctTransfer $t, $names): array
    {
        return ['id' => $t->id, 'reference' => $t->reference, 'date' => $t->transfer_date->toDateString(), 'from_id' => $t->from_account_id, 'to_id' => $t->to_account_id, 'from' => $names[$t->from_account_id] ?? '-', 'to' => $names[$t->to_account_id] ?? '-',
            'from_amount' => (string) $t->from_amount, 'from_currency' => $t->from_currency, 'to_amount' => (string) $t->to_amount, 'to_currency' => $t->to_currency, 'status' => $t->status, 'status_label' => __(AcctTransaction::STATUS[$t->status] ?? $t->status), 'cross' => $t->from_currency !== $t->to_currency, 'approvals' => $t->approvals_count . '/' . $t->required_approvals];
    }

    private function formProps(?AcctTransfer $t): array
    {
        $rates = AcctExchangeRate::query()->where('status', 'approved')->orderByDesc('effective_from')->orderByDesc('id')->limit(200)->get(['id', 'base_currency', 'quote_currency', 'rate', 'rate_type', 'effective_from', 'effective_to', 'rate_at']);

        return ['t' => $t ? $t->only(['id', 'reference', 'from_account_id', 'to_account_id', 'rate_id', 'reason', 'notes', 'external_ref', 'department', 'warehouse_id']) + ['from_amount' => rtrim(rtrim((string) $t->from_amount, '0'), '.'), 'to_amount' => rtrim(rtrim((string) $t->to_amount, '0'), '.'), 'fee_amount' => rtrim(rtrim((string) $t->fee_amount, '0'), '.'), 'transfer_date' => $t->transfer_date->toDateString()] : null,
            'accounts' => $this->accountOptions(), 'rates' => $rates->map(fn ($r) => ['id' => $r->id, 'base' => $r->base_currency, 'quote' => $r->quote_currency, 'rate' => rtrim(rtrim((string) $r->rate, '0'), '.'), 'type' => $r->rate_type, 'from' => $r->effective_from->toDateString(), 'to' => optional($r->effective_to)->toDateString()]),
            'allowInverse' => (bool) AcctSettings::get()['allow_inverse_rates'], 'tolerance' => AcctSettings::get()['rate_tolerance_pct'], 'idem' => (string) \Illuminate\Support\Str::uuid(), 'can' => ['post' => $this->me()->can('post-acct-transfers')],
            'projects' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'status'])->map(fn ($w) => ['value' => $w->id, 'label' => $w->name, 'status' => $w->status])];
    }

    private function validated(Request $request): array
    {
        $d = $request->validate(['from_account_id' => 'required|integer', 'to_account_id' => 'required|integer|different:from_account_id', 'from_amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'], 'to_amount' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'], 'fee_amount' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'rate_id' => 'nullable|integer', 'transfer_date' => 'required|date', 'reason' => 'nullable|string|max:255', 'external_ref' => 'nullable|string|max:120', 'department' => 'nullable|string|max:190', 'warehouse_id' => 'nullable|integer|exists:warehouses,id', 'notes' => 'nullable|string|max:2000', 'idempotency_key' => 'nullable|string|max:80']);
        $this->accountOrFail((int) $d['from_account_id']);
        $this->accountOrFail((int) $d['to_account_id']);
        foreach (['to_amount', 'fee_amount', 'rate_id', 'warehouse_id'] as $k) {
            if (($d[$k] ?? '') === '') {
                $d[$k] = null;
            }
        }

        return $d;
    }
}
