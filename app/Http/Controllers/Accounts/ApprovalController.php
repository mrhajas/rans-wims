<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctExchangeRate;
use App\Models\Accounts\AcctReconciliation;
use App\Models\Accounts\AcctTransaction;
use App\Models\Accounts\AcctTransfer;
use App\Models\User;
use App\Support\Accounts\AcctPermissions;
use Inertia\Inertia;

/** One inbox of everything waiting for a decision: payments and receipts, transfers, exchange rates, reconciliations, approved items waiting to be posted. */
class ApprovalController extends AcctController
{
    public function index()
    {
        $me = $this->me();
        // each kind is listed only to people who may approve or post THAT kind (money in, money out, adjustment, transfer)
        $subjects = array_values(array_unique(array_merge(AcctPermissions::subjectsFor($me, 'approve'), AcctPermissions::subjectsFor($me, 'post'))));
        $txnSubjects = array_values(array_intersect($subjects, ['inflow', 'outflow', 'adjustment']));
        $canTxn = (bool) $txnSubjects;
        $canTrf = in_array('transfer', $subjects, true);
        abort_unless($canTxn || $canTrf || $me->can('approve-acct-rates') || $me->can('approve-acct-reconciliations'), 403);
        $ids = $this->visibleAccountIds();
        $names = $this->visibleNames();
        $users = User::query()->pluck('name', 'id');
        $mine = fn ($uid) => (int) $uid === (int) $me->id;

        $txns = $canTxn ? AcctPermissions::scopeTransactions(AcctTransaction::query(), $txnSubjects)->whereNull('transfer_id')->whereIn('status', ['submitted', 'approved'])->when($ids !== null, fn ($q) => $q->whereIn('account_id', $ids ?: [0]))->orderBy('submitted_at')->limit(100)->get()
            ->map(fn ($t) => ['type' => 'transaction', 'id' => $t->id, 'reference' => $t->reference, 'title' => $t->description, 'account' => $names[$t->account_id] ?? '-', 'amount' => (string) $t->amount, 'currency' => $t->currency, 'direction' => $t->direction, 'status' => $t->status,
                'by' => $users[$t->created_by] ?? '-', 'since' => optional($t->submitted_at)->diffForHumans(), 'approvals' => $t->approvals_count . '/' . $t->required_approvals, 'own' => $mine($t->created_by) || $mine($t->submitted_by), 'url' => route('acct.transactions.show', $t->id)]) : collect();
        $trfs = $canTrf ? $this->scopeTransfers(AcctTransfer::query())->whereIn('status', ['submitted', 'approved'])->orderBy('submitted_at')->limit(100)->get()
            ->map(fn ($t) => ['type' => 'transfer', 'id' => $t->id, 'reference' => $t->reference, 'title' => ($names[$t->from_account_id] ?? '-') . ' to ' . ($names[$t->to_account_id] ?? '-'), 'account' => $names[$t->from_account_id] ?? '-', 'amount' => (string) $t->from_amount, 'currency' => $t->from_currency, 'direction' => 'out', 'status' => $t->status,
                'by' => $users[$t->created_by] ?? '-', 'since' => optional($t->submitted_at)->diffForHumans(), 'approvals' => $t->approvals_count . '/' . $t->required_approvals, 'own' => $mine($t->created_by) || $mine($t->submitted_by), 'url' => route('acct.transfers.show', $t->id)]) : collect();
        $rates = $me->can('approve-acct-rates') ? AcctExchangeRate::query()->where('status', 'pending')->orderBy('id')->limit(50)->get()
            ->map(fn ($r) => ['type' => 'rate', 'id' => $r->id, 'reference' => $r->base_currency . '/' . $r->quote_currency, 'title' => '1 ' . $r->base_currency . ' = ' . rtrim(rtrim((string) $r->rate, '0'), '.') . ' ' . $r->quote_currency, 'account' => ucfirst($r->rate_type), 'amount' => null, 'currency' => null, 'direction' => null, 'status' => 'submitted',
                'by' => $users[$r->entered_by] ?? '-', 'since' => $r->created_at->diffForHumans(), 'approvals' => '0/1', 'own' => $mine($r->entered_by), 'url' => route('acct.currencies.index')]) : collect();
        $recs = $me->can('approve-acct-reconciliations') ? AcctReconciliation::query()->where('status', 'submitted')->when($ids !== null, fn ($q) => $q->whereIn('account_id', $ids ?: [0]))->orderBy('submitted_at')->limit(50)->get()
            ->map(fn ($r) => ['type' => 'reconciliation', 'id' => $r->id, 'reference' => $r->reference, 'title' => ucfirst($r->kind) . ' reconciliation to ' . $r->period_to->toDateString(), 'account' => $names[$r->account_id] ?? '-', 'amount' => (string) $r->difference, 'currency' => optional(AcctAccount::query()->find($r->account_id))->currency, 'direction' => null, 'status' => 'submitted',
                'by' => $users[$r->prepared_by] ?? '-', 'since' => optional($r->submitted_at)->diffForHumans(), 'approvals' => '', 'own' => $mine($r->prepared_by) || $mine($r->counted_by), 'url' => route('acct.reconciliations.show', $r->id)]) : collect();

        $items = $txns->concat($trfs)->concat($rates)->concat($recs)->values();

        return Inertia::render('Accounts/Approvals', $this->shared() + ['items' => $items, 'counts' => ['transactions' => $txns->count(), 'transfers' => $trfs->count(), 'rates' => $rates->count(), 'reconciliations' => $recs->count(), 'toPost' => $items->where('status', 'approved')->count()]]);
    }
}
