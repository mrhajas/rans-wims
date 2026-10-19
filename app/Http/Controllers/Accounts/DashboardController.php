<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctExchangeRate;
use App\Models\Accounts\AcctTransaction;
use App\Models\Accounts\AcctTransfer;
use App\Services\Accounts\AcctBalance;
use App\Services\Accounts\AcctReconciler;
use App\Services\Accounts\AcctSettings;
use App\Support\Accounts\Money;
use Inertia\Inertia;

/** The financial dashboard: native balances per currency (never added across currencies), the reporting-currency view with its rate and time, flows and what needs attention. */
class DashboardController extends AcctController
{
    public function index(\Illuminate\Http\Request $request, AcctBalance $bal)
    {
        $this->need('view-accounts-tracking');
        $ids = $this->visibleAccountIds();
        $f = [
            'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', ''), 'account_id' => (string) $request->query('account_id', ''), 'currency' => (string) $request->query('currency', ''),
            'type' => (string) $request->query('type', ''), 'warehouse_id' => (string) $request->query('warehouse_id', ''), 'department' => (string) $request->query('department', ''), 'status' => (string) $request->query('status', ''), 'creator' => (string) $request->query('creator', ''),
        ];
        $accountId = $f['account_id'] !== '' && ctype_digit($f['account_id']) && ($ids === null || in_array((int) $f['account_id'], $ids, true)) ? (int) $f['account_id'] : null;
        $scope = $accountId ? [$accountId] : $ids;

        $native = $bal->native($scope);
        if ($f['currency'] !== '') {
            $native['totals'] = array_intersect_key($native['totals'], [$f['currency'] => 1]);
        }
        $flowFilters = ['account_ids' => $scope, 'currency' => $f['currency'] ?: null, 'warehouse_id' => $f['warehouse_id'] ?: null, 'department' => $f['department'] ?: null];
        $today = now()->toDateString();
        $period = [
            'today' => $bal->flows($today, $today, $flowFilters),
            'week' => $bal->flows(now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString(), $flowFilters),
            'month' => $bal->flows(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(), $flowFilters),
        ];
        if ($f['from'] !== '' && $f['to'] !== '' && strtotime($f['from']) && strtotime($f['to'])) {
            $period['custom'] = $bal->flows($f['from'], $f['to'], $flowFilters);
        }

        $pendingTxn = AcctTransaction::query()->where('status', 'submitted')->whereNull('transfer_id')->when($scope !== null, fn ($q) => $q->whereIn('account_id', $scope ?: [0]));
        // a transfer counts for a person only when they can see BOTH of its accounts; the account filter then narrows it to the ones touching that account
        $pendingTr = $this->scopeTransfers(AcctTransfer::query())->when($accountId, fn ($q) => $q->where(fn ($w) => $w->where('from_account_id', $accountId)->orWhere('to_account_id', $accountId)))->where('status', 'submitted');
        $me = $this->me();
        $mine = fn ($q) => $q->where('created_by', '<>', $me->id)->where(fn ($w) => $w->whereNull('submitted_by')->orWhere('submitted_by', '<>', $me->id));
        $apprSubjects = \App\Support\Accounts\AcctPermissions::subjectsFor($me, 'approve');
        $waiting = $apprSubjects
            ? (clone \App\Support\Accounts\AcctPermissions::scopeTransactions($mine($pendingTxn), $apprSubjects))->count() + (in_array('transfer', $apprSubjects, true) ? (clone $mine($pendingTr))->count() : 0)
            : null;

        $accounts = $this->scopeAccounts(AcctAccount::query()->whereNotIn('status', ['closed']))->get();
        $low = $accounts->filter(fn ($a) => $a->min_balance !== null && Money::cmp($a->balance, $a->min_balance) < 0)->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'currency' => $a->currency, 'balance' => (string) $a->balance, 'min' => (string) $a->min_balance])->values();
        $lastRecon = AcctReconciler::lastApproved($accounts->filter(fn ($a) => $a->reconcile_every_days)->pluck('id'));
        $due = $accounts->filter(function ($a) use ($lastRecon) {
            if (! $a->reconcile_every_days) {
                return false;
            }
            $last = $lastRecon[$a->id] ?? null;

            return \Carbon\Carbon::parse($last ?: ($a->opening_date ?: $a->created_at))->addDays($a->reconcile_every_days)->isPast();
        })->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values();

        $recent = AcctTransaction::query()->with('account:id,name,currency')->when($scope !== null, fn ($q) => $q->whereIn('account_id', $scope ?: [0]))
            ->when($f['currency'], fn ($q, $v) => $q->where('currency', $v))->when(in_array($f['type'], ['in', 'out'], true), fn ($q) => $q->where('direction', $f['type']))
            ->when($f['status'] !== '', fn ($q) => $q->where('status', $f['status']))->when($f['creator'] !== '' && ctype_digit($f['creator']), fn ($q) => $q->where('created_by', (int) $f['creator']))
            ->when($f['warehouse_id'] !== '' && ctype_digit($f['warehouse_id']), fn ($q) => $q->where('warehouse_id', (int) $f['warehouse_id']))->when($f['department'] !== '', fn ($q) => $q->where('department', $f['department']))
            ->when($f['from'] !== '' && strtotime($f['from']), fn ($q) => $q->whereDate('txn_date', '>=', $f['from']))->when($f['to'] !== '' && strtotime($f['to']), fn ($q) => $q->whereDate('txn_date', '<=', $f['to']))
            ->orderByDesc('id')->limit(10)->get()->map(fn ($t) => $this->row($t));

        // the counters are scoped like the Alerts page: alerts about accounts the person cannot see are not counted
        $alerts = $this->scopedAlerts()->whereIn('status', ['open', 'acknowledged'])->selectRaw('severity, COUNT(*) as n')->groupBy('severity')->pluck('n', 'severity');
        $unusual = $this->scopedAlerts()->whereIn('status', ['open', 'acknowledged'])->whereIn('type', ['unusual_amount', 'possible_duplicate', 'large_transaction'])->count();
        $diffs = $this->scopedAlerts()->whereIn('status', ['open', 'acknowledged'])->whereIn('type', ['cash_discrepancy', 'bank_discrepancy'])->count();
        $pending = $bal->pending(null, $scope);
        $pending['transfers'] = $this->scopeTransfers(AcctTransfer::query())->whereIn('status', ['submitted', 'approved'])->when($accountId, fn ($q) => $q->where(fn ($w) => $w->where('from_account_id', $accountId)->orWhere('to_account_id', $accountId)))->count();

        return Inertia::render('Accounts/Dashboard', $this->shared() + [
            'native' => $native, 'reporting' => $me->can('read-acct-reports') || $me->can('view-accounts-tracking') ? $bal->reporting($native['totals']) : null,
            'period' => $period, 'filters' => $f, 'pending' => $pending, 'pendingTransfers' => (clone $pendingTr)->count(), 'waiting' => $waiting,
            'low' => $low, 'due' => $due, 'unusual' => $unusual, 'diffs' => $diffs, 'alerts' => ['by' => $alerts, 'open' => (int) $alerts->sum(), 'critical' => (int) ($alerts['critical'] ?? 0) + (int) ($alerts['high'] ?? 0)],
            'recent' => $me->can('read-acct-transactions') ? $recent : [], 'accountsList' => $this->accountOptions(null, false), 'pendingRates' => AcctExchangeRate::query()->where('status', 'pending')->count(),
            'asOf' => now()->toDateTimeString(), 'can' => ['reports' => $me->can('read-acct-reports'), 'approve' => (bool) $apprSubjects, 'inflow' => $me->can('create-acct-inflows'), 'outflow' => $me->can('create-acct-outflows'), 'transfer' => $me->can('create-acct-transfers'), 'transfers' => $me->can('read-acct-transfers'), 'accounts' => $me->can('read-acct-accounts'), 'recon' => $me->can('perform-acct-reconciliations') || $me->can('approve-acct-reconciliations'), 'currencies' => $me->can('read-acct-accounts') || $me->can('create-acct-rates') || $me->can('approve-acct-rates'), 'alerts' => $me->can('read-acct-alerts'), 'transactions' => $me->can('read-acct-transactions')],
        ]);
    }

    private function row(AcctTransaction $t): array
    {
        return ['id' => $t->id, 'reference' => $t->reference, 'date' => $t->txn_date->toDateString(), 'account' => optional($t->account)->name, 'direction' => $t->direction, 'kind' => $t->kind, 'amount' => (string) $t->amount, 'currency' => $t->currency, 'status' => $t->status, 'description' => $t->description];
    }
}
