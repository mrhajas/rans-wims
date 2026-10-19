<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctApproval;
use App\Models\Accounts\AcctAuditEvent;
use App\Models\Accounts\AcctReconciliation;
use App\Models\Accounts\AcctReconItem;
use App\Models\Accounts\AcctTransaction;
use App\Models\User;
use App\Services\Accounts\AcctAudit;
use App\Services\Accounts\AcctBalance;
use App\Services\Accounts\AcctRates;
use App\Services\Accounts\AcctReconciler;
use App\Services\Accounts\AcctSettings;
use App\Support\Accounts\AcctException;
use App\Support\Accounts\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Cash counts and bank statement reconciliations. A difference is recorded and explained, never silently overwritten. */
class ReconciliationController extends AcctController
{
    public function index(Request $request)
    {
        $this->need('perform-acct-reconciliations', 'approve-acct-reconciliations', 'read-acct-accounts');
        $q = $this->scopeAccounts(AcctReconciliation::query(), 'account_id')
            ->when(in_array($request->query('kind'), ['cash', 'bank'], true), fn ($w) => $w->where('kind', $request->query('kind')))
            ->when(in_array($request->query('status'), ['draft', 'submitted', 'approved', 'rejected'], true), fn ($w) => $w->where('status', $request->query('status')))
            ->when(ctype_digit((string) $request->query('account_id')), fn ($w) => $w->where('account_id', (int) $request->query('account_id')))
            ->when($request->query('with_difference') === '1', fn ($w) => $w->where('difference', '<>', 0));
        $accts = AcctAccount::query()->get(['id', 'name', 'currency'])->keyBy('id');
        $rows = $q->orderByDesc('id')->paginate(20)->withQueryString();
        $rows->getCollection()->transform(fn ($r) => $this->row($r, $accts));

        // Accounts that are due for a reconciliation (never done, or older than their own interval / the default).
        $dueAccounts = $this->scopeAccounts(AcctAccount::query()->where('status', 'active')->whereIn('kind', array_merge(['bank_account'], ['cash_in_hand', 'petty_cash', 'project_cash', 'office_cash', 'foreign_cash'])))->get();
        $lastApproved = AcctReconciler::lastApproved($dueAccounts->pluck('id'));
        $due = $dueAccounts->map(function ($a) use ($lastApproved) {
            $last = $lastApproved[$a->id] ?? null;
            $days = (int) ($a->reconcile_every_days ?: AcctSettings::get()['recon_overdue_days']);
            $age = $last ? now()->diffInDays(\Carbon\Carbon::parse($last)) : null;

            return ['id' => $a->id, 'name' => $a->name, 'kind' => $a->kind, 'currency' => $a->currency, 'last' => $last, 'days' => $days, 'age' => $age, 'overdue' => $age === null || $age > $days];
        })->where('overdue', true)->values();

        return Inertia::render('Accounts/Reconciliations/Index', $this->shared() + [
            'rows' => $rows, 'filters' => $request->only('kind', 'status', 'account_id', 'with_difference'), 'accounts' => $this->accountOptions(null, false), 'due' => $due,
            'can' => ['perform' => $this->me()->can('perform-acct-reconciliations')],
        ]);
    }

    public function create(Request $request)
    {
        $this->need('perform-acct-reconciliations');
        $a = ctype_digit((string) $request->query('account_id')) ? $this->accountOrFail((int) $request->query('account_id')) : null;

        return Inertia::render('Accounts/Reconciliations/Form', $this->shared() + [
            'accounts' => $this->accountOptions(), 'account' => $a ? ['id' => $a->id, 'name' => $a->name, 'currency' => $a->currency, 'kind' => $a->kind] : null, 'kind' => $request->query('kind') === 'bank' ? 'bank' : ($a && $a->kind === 'bank_account' ? 'bank' : 'cash'),
            'denominations' => AcctSettings::get()['denominations'], 'today' => now()->toDateString(),
        ]);
    }

    /** JSON: what WIMS says the balance is at a date (the figure the count or statement is compared with). */
    public function expected(Request $request)
    {
        $this->need('perform-acct-reconciliations');
        $a = $this->accountOrFail((int) $request->query('account_id'));
        $date = strtotime((string) $request->query('date')) ? $request->query('date') : now()->toDateString();

        return response()->json(['expected' => app(AcctBalance::class)->asOf($a, $date), 'currency' => $a->currency, 'precision' => AcctRates::precision($a->currency)]);
    }

    public function store(Request $request)
    {
        $this->need('perform-acct-reconciliations');
        $d = $request->validate(['account_id' => 'required|integer', 'kind' => 'required|in:cash,bank', 'period_from' => 'nullable|date', 'period_to' => 'required|date|before_or_equal:today', 'counted_balance' => ['nullable', 'regex:/^-?\d{1,15}(\.\d{1,4})?$/'],
            'denominations' => 'nullable|array', 'denominations.*' => 'nullable|integer|min:0|max:100000000', 'witness_name' => 'nullable|string|max:190', 'explanation' => 'nullable|string|max:2000']);
        $a = $this->accountOrFail((int) $d['account_id']);
        if ($d['kind'] === 'cash' && ! in_array($a->kind, ['cash_in_hand', 'petty_cash', 'project_cash', 'office_cash', 'foreign_cash', 'mobile_money', 'other'], true)) {
            return back()->with('error', e(__('A cash count is for a cash account. Use a bank reconciliation for a bank account.')));
        }
        if ($d['kind'] === 'bank' && $a->kind !== 'bank_account') {
            return back()->with('error', e(__('A bank reconciliation is for a bank account.')));
        }

        return $this->run(function () use ($a, $d) {
            $svc = app(AcctReconciler::class);
            $r = $d['kind'] === 'cash' ? $svc->startCash($a, $this->me(), $d) : $svc->startBank($a, $this->me(), $d);

            return redirect()->route('acct.reconciliations.show', $r->id)->with('message', __('Saved as a draft.'));
        });
    }

    public function show(int $id)
    {
        $this->need('perform-acct-reconciliations', 'approve-acct-reconciliations', 'read-acct-accounts');
        $r = $this->orFail($id);
        $a = AcctAccount::query()->find($r->account_id);
        $me = $this->me();
        $users = User::query()->whereIn('id', array_filter([$r->prepared_by, $r->counted_by, $r->approved_by]))->pluck('name', 'id');
        $items = AcctReconItem::query()->where('reconciliation_id', $r->id)->orderBy('entry_date')->orderBy('id')->get();
        $matchedTx = AcctTransaction::query()->whereIn('id', $items->pluck('matched_txn_id')->filter())->get(['id', 'reference', 'description', 'txn_date'])->keyBy('id');
        $trail = AcctApproval::query()->where('subject_type', 'reconciliation')->where('subject_id', $r->id)->orderBy('id')->get();
        $iprec = AcctRates::precision($a->currency);
        $unmatchedWims = $r->kind === 'bank' && $r->status === 'draft' ? AcctTransaction::query()->where('account_id', $r->account_id)->whereIn('status', AcctTransaction::LEDGER)->whereNull('reconciled_at')->whereDate('txn_date', '<=', $r->period_to)
            ->whereNotIn('id', $items->pluck('matched_txn_id')->filter())->orderByDesc('txn_date')->limit(100)->get(['id', 'reference', 'description', 'txn_date', 'direction', 'amount']) : collect();
        $self = in_array((int) $me->id, [(int) $r->prepared_by, (int) $r->counted_by], true);
        $history = $me->can('view-acct-audit') ? AcctAuditEvent::query()->where('entity_type', 'reconciliation')->where('entity_id', $r->id)->orderByDesc('id')->limit(30)->get() : collect();
        // every name this page shows, in one query (it used to ask for each person of each row)
        $names = User::query()->whereIn('id', $trail->pluck('user_id')->merge($history->pluck('user_id'))->filter()->unique())->pluck('name', 'id');

        return Inertia::render('Accounts/Reconciliations/Show', $this->shared() + [
            'r' => $this->row($r, collect([$a->id => $a])) + $r->only(['witness_name', 'explanation', 'reject_reason', 'denominations']) + ['opening_balance' => (string) $r->opening_balance, 'period_from' => optional($r->period_from)->toDateString(), 'precision' => $iprec,
                'prepared_by_name' => $users[$r->prepared_by] ?? null, 'counted_by_name' => $users[$r->counted_by] ?? null, 'approved_by_name' => $users[$r->approved_by] ?? null, 'approved_at' => optional($r->approved_at)->toDateTimeString(), 'adjustment_txn_id' => $r->adjustment_txn_id,
                'account_kind' => $a->kind, 'dual' => (function () use ($r, $a) { $big = AcctSettings::amountFor('discrepancy_dual_over', $a->currency); return $big !== null && Money::cmp(ltrim((string) $r->difference, '-'), $big) > 0; })()],
            'items' => $items->map(fn ($i) => ['id' => $i->id, 'date' => $i->entry_date->toDateString(), 'description' => $i->description, 'reference' => $i->reference, 'direction' => $i->direction, 'amount' => (string) $i->amount, 'status' => $i->status, 'note' => $i->note,
                'txn' => $i->matched_txn_id ? ($matchedTx[$i->matched_txn_id] ?? null) : null]),
            'unmatchedWims' => $unmatchedWims, 'timeline' => $trail->map(fn ($x) => ['action' => $x->action, 'user' => $names[$x->user_id] ?? null, 'at' => $x->created_at->toDateTimeString()])->values(),
            'history' => $history->map(fn ($e) => ['event' => $e->event, 'user' => $names[$e->user_id] ?? null, 'reason' => $e->reason, 'at' => $e->created_at->toDateTimeString()]),
            'can' => ['edit' => in_array($r->status, ['draft', 'rejected'], true) && $me->can('perform-acct-reconciliations'), 'submit' => in_array($r->status, ['draft', 'rejected'], true) && $me->can('perform-acct-reconciliations'),
                'approve' => $r->status === 'submitted' && $me->can('approve-acct-reconciliations') && (! $self || ! AcctSettings::get()['maker_checker']), 'reject' => $r->status === 'submitted' && $me->can('approve-acct-reconciliations')],
        ]);
    }

    public function explain(Request $request, int $id)
    {
        $this->need('perform-acct-reconciliations');
        $r = $this->orFail($id);
        abort_unless(in_array($r->status, ['draft', 'rejected'], true), 403);
        $old = (string) $r->explanation;
        $r->fill(['explanation' => $request->validate(['explanation' => 'nullable|string|max:2000'])['explanation'], 'status' => $r->status === 'rejected' ? 'draft' : $r->status])->save();
        AcctAudit::log('reconciliation.explained', 'reconciliation', $r->id, $r->reference, ['explanation' => $old], ['explanation' => (string) $r->explanation], null, $this->me());

        return back()->with('message', __('Saved.'));
    }

    public function import(Request $request, int $id)
    {
        $this->need('perform-acct-reconciliations');
        $r = $this->orFail($id);
        $request->validate(['file' => 'required|file|max:4096|mimes:csv,txt']);

        return $this->run(function () use ($r, $request) {
            $svc = app(AcctReconciler::class);
            $rows = $svc->parseCsv($request->file('file')->getRealPath());
            $n = $svc->addItems($r, $rows);
            $m = $svc->autoMatch($r);
            AcctAudit::log('reconciliation.imported', 'reconciliation', $r->id, $r->reference, null, ['lines' => $n, 'matched' => $m['matched'], 'unmatched' => $m['unmatched'], 'file' => substr((string) $request->file('file')->getClientOriginalName(), 0, 120)], null, $this->me());

            return back()->with('message', __(':n statement lines imported, :m matched, :u still unmatched.', ['n' => $n, 'm' => $m['matched'], 'u' => $m['unmatched']]));
        });
    }

    public function addItem(Request $request, int $id)
    {
        $this->need('perform-acct-reconciliations');
        $r = $this->orFail($id);
        $d = $request->validate(['entry_date' => 'required|date', 'description' => 'nullable|string|max:255', 'reference' => 'nullable|string|max:120', 'direction' => 'required|in:in,out', 'amount' => ['required', 'regex:/^\d{1,15}(\.\d{1,4})?$/']]);

        return $this->run(function () use ($r, $d) {
            $n = app(AcctReconciler::class)->addItems($r, [$d]);
            AcctAudit::log('reconciliation.item_added', 'reconciliation', $r->id, $r->reference, null, ['date' => $d['entry_date'], 'direction' => $d['direction'], 'amount' => $d['amount'], 'lines' => $n], null, $this->me());
        }, __('Line added.'));
    }

    public function match(Request $request, int $id)
    {
        $this->need('perform-acct-reconciliations');
        $r = $this->orFail($id);

        return $this->run(function () use ($r) {
            $m = app(AcctReconciler::class)->autoMatch($r);
            AcctAudit::log('reconciliation.matched', 'reconciliation', $r->id, $r->reference, null, ['matched' => $m['matched'], 'unmatched' => $m['unmatched']], null, $this->me());

            return back()->with('message', __(':m matched, :u still unmatched.', ['m' => $m['matched'], 'u' => $m['unmatched']]));
        });
    }

    /** Hand-match (or unmatch / ignore) one statement line. Only a posted, unreconciled transaction of the same account, direction and amount can be chosen. */
    public function matchItem(Request $request, int $id, int $item)
    {
        $this->need('perform-acct-reconciliations');
        $r = $this->orFail($id);
        abort_unless($r->status === 'draft', 403);
        $i = AcctReconItem::query()->where('reconciliation_id', $r->id)->findOrFail($item);
        $d = $request->validate(['action' => 'required|in:match,unmatch,ignore', 'txn_id' => 'nullable|integer']);

        if ($d['action'] === 'unmatch') {
            $i->forceFill(['matched_txn_id' => null, 'status' => 'unmatched', 'note' => null])->save();
        } elseif ($d['action'] === 'ignore') {
            $i->forceFill(['matched_txn_id' => null, 'status' => 'ignored', 'note' => __('Set aside by :u', ['u' => $this->me()->name])])->save();
        } else {
            $t = AcctTransaction::query()->where('account_id', $r->account_id)->whereIn('status', AcctTransaction::LEDGER)->whereNull('reconciled_at')->find($d['txn_id'] ?? 0);
            if (! $t || $t->direction !== $i->direction || Money::cmp((string) $t->amount, (string) $i->amount) !== 0) {
                return back()->with('error', e(__('That transaction does not match this statement line (account, direction and amount must agree).')));
            }
            if (AcctReconItem::query()->where('reconciliation_id', $r->id)->where('matched_txn_id', $t->id)->exists()) {
                return back()->with('error', e(__('That transaction is already matched to another line.')));
            }
            $i->forceFill(['matched_txn_id' => $t->id, 'status' => 'matched', 'note' => null])->save();
        }
        AcctAudit::log('reconciliation.item_' . $d['action'], 'reconciliation', $r->id, $r->reference, null, ['item' => $i->id, 'txn' => $d['txn_id'] ?? null], null, $this->me());

        return back()->with('message', __('Saved.'));
    }

    public function submit(int $id)
    {
        $r = $this->orFail($id);

        return $this->run(fn () => app(AcctReconciler::class)->submit($r, $this->me()), __('Submitted for approval.'));
    }

    public function approve(Request $request, int $id)
    {
        $r = $this->orFail($id);

        return $this->run(function () use ($r, $request) {
            $x = app(AcctReconciler::class)->approve($r, $this->me(), $request->boolean('propose'));

            return back()->with('message', $x->status === 'approved' ? __('Approved.') : __('Approval recorded. A second approver is needed for a difference this large.'));
        });
    }

    public function reject(Request $request, int $id)
    {
        $r = $this->orFail($id);

        return $this->run(fn () => app(AcctReconciler::class)->reject($r, $this->me(), (string) $request->validate(['reason' => 'required|string|max:500'])['reason']), __('Rejected.'));
    }

    private function orFail(int $id): AcctReconciliation
    {
        $r = AcctReconciliation::query()->findOrFail($id);
        $this->accountOrFail((int) $r->account_id);

        return $r;
    }

    private function row(AcctReconciliation $r, $accts): array
    {
        $a = $accts[$r->account_id] ?? null;

        return ['id' => $r->id, 'reference' => $r->reference, 'account_id' => $r->account_id, 'account' => $a->name ?? '-', 'currency' => $a->currency ?? '', 'kind' => $r->kind, 'period_to' => $r->period_to->toDateString(), 'expected' => (string) $r->expected_balance, 'counted' => (string) $r->counted_balance,
            'difference' => (string) $r->difference, 'status' => $r->status, 'status_label' => __(ucfirst($r->status))];
    }
}
