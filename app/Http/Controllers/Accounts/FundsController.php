<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Accounts\AcctTransaction;
use App\Models\FundAllocation;
use App\Services\Accounts\AcctAudit;
use App\Services\Accounts\AcctPosting;
use App\Services\Accounts\AcctRates;
use App\Support\Accounts\AcctException;
use App\Support\Accounts\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Funds Allocation, seen from the money side. An allocation is a plan: it never changes a balance. Money moves only when a transaction is posted:
 * cash received against an allocation is a receipt, and a paid line can be recorded once as a payment from a chosen account (unique source key).
 */
class FundsController extends AcctController
{
    public function index(Request $request)
    {
        $this->need('read-acct-transactions');
        $q = FundAllocation::query()->withoutGlobalScopes()->whereNull('deleted_at')->whereNotIn('status', [FundAllocation::STATUS_DRAFT, FundAllocation::STATUS_REJECTED, FundAllocation::STATUS_CANCELLED])
            ->when(trim((string) $request->query('q')) !== '', fn ($w) => $w->where(fn ($x) => $x->where('reference', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($request->query('q'))) . '%')->orWhere('title', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($request->query('q'))) . '%')));
        $rows = $q->orderByDesc('allocation_date')->orderByDesc('id')->paginate(15)->withQueryString();
        $ids = $rows->getCollection()->pluck('id');
        $alloc = DB::table('fund_allocation_items')->whereNull('deleted_at')->whereIn('fund_allocation_id', $ids)->selectRaw('fund_allocation_id a, SUM(allocated_amount) s, COUNT(*) n')->groupBy('a')->get()->keyBy('a');
        $flows = $this->flows($ids->all());
        $rows->getCollection()->transform(fn ($a) => ['id' => $a->id, 'reference' => $a->reference, 'title' => $a->title, 'date' => $a->allocation_date->toDateString(), 'status' => $a->status, 'allocated' => (string) ($alloc[$a->id]->s ?? 0), 'lines' => (int) ($alloc[$a->id]->n ?? 0), 'flows' => $flows[$a->id] ?? []]);

        return Inertia::render('Accounts/Funds/Index', $this->shared() + ['rows' => $rows, 'filters' => $request->only('q')]);
    }

    public function show(int $id)
    {
        $this->need('read-acct-transactions');
        $a = FundAllocation::query()->withoutGlobalScopes()->whereNull('deleted_at')->findOrFail($id);
        $lines = DB::table('fund_allocation_item_lines as l')->join('fund_allocation_items as i', 'i.id', '=', 'l.fund_allocation_item_id')->join('fund_payment_methods as m', 'm.id', '=', 'l.fund_payment_method_id')
            ->where('i.fund_allocation_id', $a->id)->whereNull('l.deleted_at')->whereNull('i.deleted_at')->orderBy('i.sort_order')->orderBy('l.id')
            ->get(['l.id', 'l.amount', 'l.is_paid', 'l.paid_at', 'l.reference', 'i.description', 'i.beneficiary', 'm.name as method', 'm.currency']);
        // only what happened on accounts this person may see: payments and totals on other accounts are not theirs to read
        $posted = $this->scopeAccounts(AcctTransaction::query(), 'account_id')->whereIn('fund_item_line_id', $lines->pluck('id'))->get(['id', 'reference', 'fund_item_line_id', 'status', 'account_id'])->groupBy('fund_item_line_id');
        $names = $this->visibleNames();
        $tx = $this->scopeAccounts(AcctTransaction::query(), 'account_id')->where('fund_allocation_id', $a->id)->whereNull('fund_item_line_id')->orderByDesc('id')->limit(100)->get(['id', 'reference', 'direction', 'amount', 'currency', 'status', 'txn_date', 'description', 'account_id']);
        $over = $this->flows([$a->id])[$a->id] ?? [];

        return Inertia::render('Accounts/Funds/Show', $this->shared() + [
            'a' => ['id' => $a->id, 'reference' => $a->reference, 'title' => $a->title, 'date' => $a->allocation_date->toDateString(), 'status' => $a->status, 'allocated' => (string) DB::table('fund_allocation_items')->whereNull('deleted_at')->where('fund_allocation_id', $a->id)->sum('allocated_amount')],
            'flows' => $over, 'lines' => $lines->map(fn ($l) => ['id' => $l->id, 'description' => $l->description, 'beneficiary' => $l->beneficiary, 'method' => $l->method, 'currency' => $l->currency, 'amount' => (string) $l->amount, 'paid' => (bool) $l->is_paid, 'paid_at' => $l->paid_at, 'reference' => $l->reference,
                'posted' => collect($posted[$l->id] ?? [])->map(fn ($p) => ['id' => $p->id, 'reference' => $p->reference, 'status' => $p->status, 'account' => $names[$p->account_id] ?? null])->values()]),
            'transactions' => $tx->map(fn ($t) => $t->toArray() + ['account' => $names[$t->account_id] ?? null]), 'accounts' => $this->accountOptions(), 'today' => now()->toDateString(),
            'can' => ['post' => $this->me()->can('create-acct-outflows'), 'receive' => $this->me()->can('create-acct-inflows')],
        ]);
    }

    /** Record a PAID line of an allocation as a payment from one of our accounts. Done once per line: a second attempt returns the first record. */
    public function postLine(Request $request, int $id, int $line)
    {
        $this->need('create-acct-outflows');
        $d = $request->validate(['account_id' => 'required|integer', 'txn_date' => 'required|date|before_or_equal:today', 'notes' => 'nullable|string|max:500']);
        $acct = $this->accountOrFail((int) $d['account_id']);
        // the line, its item and its allocation must all still exist, and the allocation must be a live one (not a draft, rejected or cancelled plan)
        $l = DB::table('fund_allocation_item_lines as l')->join('fund_allocation_items as i', 'i.id', '=', 'l.fund_allocation_item_id')->join('fund_allocations as fa', 'fa.id', '=', 'i.fund_allocation_id')->join('fund_payment_methods as m', 'm.id', '=', 'l.fund_payment_method_id')
            ->where('i.fund_allocation_id', $id)->where('l.id', $line)->whereNull('l.deleted_at')->whereNull('i.deleted_at')->whereNull('fa.deleted_at')
            ->whereNotIn('fa.status', [FundAllocation::STATUS_DRAFT, FundAllocation::STATUS_REJECTED, FundAllocation::STATUS_CANCELLED])
            ->first(['l.id', 'l.amount', 'l.is_paid', 'i.description', 'i.beneficiary', 'm.currency']);
        abort_unless($l, 404);
        if (! $l->is_paid) {
            return back()->with('error', e(__('Only a line marked as paid can be recorded as a payment.')));
        }
        if ($acct->currency !== $l->currency) {
            return back()->with('error', e(__('This line is in :l. Choose an account in :l.', ['l' => $l->currency])));
        }
        $existing = AcctTransaction::query()->where('source_module', 'fund_allocation')->where('source_type', 'item_line')->where('source_id', $line)->first();
        if ($existing && in_array($existing->status, ['cancelled', 'reversed'], true)) {
            // a payment that was cancelled or reversed (recorded before they let go of the line) frees the line to be recorded again
            $existing->forceFill(['source_module' => null, 'source_type' => null, 'source_id' => null, 'idempotency_key' => null])->save();
            $existing = null;
        }
        if ($existing) {
            $seen = $this->scopeAccounts(AcctTransaction::query()->whereKey($existing->id), 'account_id')->exists();

            return $seen ? redirect()->route('acct.transactions.show', $existing->id)->with('message', __('This line was already recorded as :r.', ['r' => $existing->reference])) : back()->with('message', __('This line was already recorded.'));
        }

        return $this->run(function () use ($acct, $l, $id, $line, $d) {
            $svc = app(AcctPosting::class);
            // the key cannot be guessed from the line number, so nobody can pre-register it; one line still has one key, so a double click records it once
            $key = 'fund-line-' . substr(hash_hmac('sha256', 'fund-line-' . $line, (string) config('app.key')), 0, 40);
            $t = $svc->create(['account_id' => $acct->id, 'direction' => 'out', 'kind' => 'payment', 'amount' => (string) $l->amount, 'txn_date' => $d['txn_date'], 'description' => mb_substr(__('Funds allocation payment: :d', ['d' => $l->description]), 0, 250), 'counterparty_name' => $l->beneficiary,
                'fund_allocation_id' => $id, 'fund_item_line_id' => $line, 'source_module' => 'fund_allocation', 'source_type' => 'item_line', 'source_id' => $line, 'idempotency_key' => $key, 'notes' => $d['notes'] ?? null], $this->me());
            AcctAudit::log('funds.line_recorded', 'transaction', $t->id, $t->reference, null, ['allocation' => $id, 'line' => $line], null, $this->me());

            return redirect()->route('acct.transactions.show', $t->id)->with('message', __('Recorded as a draft payment. Submit it to move the money.'));
        });
    }

    /** allocation id => [ {currency, received, spent, remaining, pending} ... ]. Only posted transactions count as received or spent. */
    private function flows(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $out = [];
        $rows = $this->scopeAccounts(AcctTransaction::query(), 'account_id')->whereIn('fund_allocation_id', $ids)->selectRaw("fund_allocation_id a, currency, 
            SUM(CASE WHEN status IN ('posted','reversed') AND direction='in' THEN amount ELSE 0 END) i, SUM(CASE WHEN status IN ('posted','reversed') AND direction='out' THEN amount ELSE 0 END) o,
            SUM(CASE WHEN status IN ('submitted','approved') THEN (CASE WHEN direction='in' THEN amount ELSE -amount END) ELSE 0 END) p")->groupBy('a', 'currency')->get();
        foreach ($rows as $r) {
            $out[$r->a][] = ['currency' => $r->currency, 'received' => (string) $r->i, 'spent' => (string) $r->o, 'remaining' => Money::sub((string) $r->i, (string) $r->o), 'pending' => (string) $r->p, 'overspent' => Money::cmp((string) $r->o, (string) $r->i) > 0];
        }

        return $out;
    }
}
