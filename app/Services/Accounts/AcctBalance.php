<?php

namespace App\Services\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctTransaction;
use App\Models\Accounts\AcctTransfer;
use App\Support\Accounts\Money;
use Illuminate\Support\Facades\DB;

/**
 * Every balance figure, from the authoritative ledger.
 *   closing = opening + posted inflows - posted outflows (adjustments and reversals are ordinary posted entries)
 * Transfers touch both accounts but are not income or expense (flows() leaves them out). Currencies are never added together: totals are per
 * currency, and the reporting-currency view converts each one with a stated, approved rate or says the rate is missing.
 */
class AcctBalance
{
    private function db()
    {
        return DB::connection('accounts');
    }

    /** The SQL expression of a ledger row's effective date under the accounting-date rule in Settings (reports use the same one as balances). */
    public function dateColumn(): string
    {
        return $this->dateExpr();
    }

    /** The effective date column of a ledger row under the accounting-date rule in Settings. */
    private function dateExpr(): string
    {
        return AcctSettings::get()['date_rule'] === 'value_date' ? 'COALESCE(value_date, txn_date)' : 'txn_date';
    }

    /** What the account held at the end of $date, from its opening balance and the POSTED entries effective on or before that day. */
    public function asOf($account, string $date): string
    {
        $a = $account instanceof AcctAccount ? $account : AcctAccount::query()->findOrFail($account);
        $open = ($a->opening_date === null || $a->opening_date->toDateString() <= $date) ? (string) $a->opening_balance : '0';
        $row = $this->db()->table('acct_transactions')->where('account_id', $a->id)->whereIn('status', AcctTransaction::LEDGER)->whereRaw($this->dateExpr() . ' <= ?', [$date])
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0) as ins, COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) as outs")->first();

        return Money::sub(Money::add($open, $row->ins), $row->outs);
    }

    /**
     * The same figure for many accounts in one query per 1,000 accounts (id => balance): the lists and checks that used to ask one account at a time.
     *
     * @param iterable<AcctAccount> $accounts
     */
    public function asOfMany(iterable $accounts, string $date): array
    {
        $accounts = collect($accounts)->keyBy('id');
        $sums = [];
        foreach ($accounts->keys()->chunk(1000) as $chunk) {
            foreach ($this->db()->table('acct_transactions')->whereIn('account_id', $chunk->all())->whereIn('status', AcctTransaction::LEDGER)->whereRaw($this->dateExpr() . ' <= ?', [$date])
                ->selectRaw("account_id, COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0) as ins, COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) as outs")->groupBy('account_id')->get() as $r) {
                $sums[$r->account_id] = $r;
            }
        }
        $out = [];
        foreach ($accounts as $id => $a) {
            $open = ($a->opening_date === null || $a->opening_date->toDateString() <= $date) ? (string) $a->opening_balance : '0';
            $out[$id] = Money::sub(Money::add($open, $sums[$id]->ins ?? '0'), $sums[$id]->outs ?? '0');
        }

        return $out;
    }

    /** Native-currency position: per account group and per currency, and the total per currency. */
    public function native(?array $accountIds = null): array
    {
        $q = AcctAccount::query()->whereNotIn('status', ['closed'])->when($accountIds !== null, fn ($w) => $w->whereIn('id', $accountIds));
        $groups = ['bank' => [], 'cash' => [], 'other' => []];
        $totals = [];
        foreach ($q->get(['id', 'kind', 'currency', 'balance']) as $a) {
            $g = AcctAccount::groupOf($a->kind);
            $groups[$g][$a->currency] = Money::add($groups[$g][$a->currency] ?? '0', $a->balance);
            $totals[$a->currency] = Money::add($totals[$a->currency] ?? '0', $a->balance);
        }
        ksort($totals);

        return ['groups' => $groups, 'totals' => $totals, 'accounts' => $q->count()];
    }

    /** The same totals in the reporting currency, each with the rate and timestamp used (or a missing-rate flag). Never mixed up with real holdings. */
    public function reporting(array $totals): array
    {
        $rep = AcctSettings::get()['reporting_currency'];
        $sum = '0';
        $lines = [];
        $missing = [];
        foreach ($totals as $cur => $amt) {
            $r = AcctRates::toReporting((string) $amt, $cur);
            if (! $r) {
                $missing[] = $cur;
                $lines[] = ['currency' => $cur, 'amount' => (string) $amt, 'converted' => null, 'rate' => null];
                continue;
            }
            $sum = Money::add($sum, $r['amount']);
            $lines[] = ['currency' => $cur, 'amount' => (string) $amt, 'converted' => $r['amount'], 'rate' => $r['rate']['rate'], 'rate_type' => $r['rate']['type'], 'rate_at' => optional($r['rate']['at'])->toDateTimeString(), 'derived' => $r['rate']['derived']];
        }

        return ['currency' => $rep, 'total' => $sum, 'lines' => $lines, 'missing' => $missing, 'complete' => ! $missing, 'valued_at' => now()->toDateTimeString()];
    }

    /** Posted money in and out of a period, per currency, without transfers between company accounts. */
    public function flows(string $from, string $to, array $filters = []): array
    {
        $q = AcctTransaction::operating($this->db()->table('acct_transactions')->whereIn('status', AcctTransaction::LEDGER))->whereRaw($this->dateExpr() . ' between ? and ?', [$from, $to]);
        if (isset($filters['account_ids']) && $filters['account_ids'] !== null) {
            $q->whereIn('account_id', $filters['account_ids'] ?: [0]);
        }
        if (! empty($filters['currency'])) {
            $q->where('currency', $filters['currency']);
        }
        foreach (['account_id', 'warehouse_id', 'department', 'category_id'] as $k) {
            if (! empty($filters[$k])) {
                $q->where($k, $filters[$k]);
            }
        }
        $out = [];
        foreach ($q->selectRaw("currency, direction, COALESCE(SUM(amount),0) as total, COUNT(*) as n")->groupBy('currency', 'direction')->get() as $r) {
            $out[$r->currency][$r->direction] = (string) $r->total;
            $out[$r->currency]['n_' . $r->direction] = (int) $r->n;
        }
        foreach ($out as $c => &$v) {
            $v += ['in' => '0.0000', 'out' => '0.0000', 'n_in' => 0, 'n_out' => 0];
            $v['net'] = Money::sub($v['in'], $v['out']);
        }

        return $out;
    }

    /** Submitted or approved but not yet posted: shown beside the balance, never in it. */
    public function pending(?int $accountId = null, ?array $accountIds = null): array
    {
        $q = $this->db()->table('acct_transactions')->whereIn('status', ['submitted', 'approved'])->whereNull('transfer_id')->when($accountId, fn ($w) => $w->where('account_id', $accountId))->when($accountIds !== null, fn ($w) => $w->whereIn('account_id', $accountIds ?: [0]));
        $by = [];
        $n = 0;
        foreach ((clone $q)->selectRaw("currency, COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0) as ins, COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) as outs, COUNT(*) as n")->groupBy('currency')->get() as $r) {
            $by[$r->currency] = ['in' => (string) $r->ins, 'out' => (string) $r->outs, 'count' => (int) $r->n];
            $n += (int) $r->n;
        }
        // with an account scope a transfer counts only when BOTH of its accounts are inside it (one end alone would count a movement the person cannot see)
        $t = AcctTransfer::query()->whereIn('status', ['submitted', 'approved'])->when($accountId, fn ($w) => $w->where(fn ($x) => $x->where('from_account_id', $accountId)->orWhere('to_account_id', $accountId)))
            ->when($accountIds !== null, fn ($w) => $w->whereIn('from_account_id', $accountIds ?: [0])->whereIn('to_account_id', $accountIds ?: [0]))->count();

        // per currency: pending amounts of different currencies are never added together
        return ['by' => $by, 'count' => $n, 'transfers' => $t];
    }

    /** Every account whose stored balance disagrees with opening + ledger (empty = healthy). */
    public function integrity(): array
    {
        $bad = [];
        $all = AcctAccount::query()->get();
        $calcs = $this->asOfMany($all, '2999-12-31');
        foreach ($all as $a) {
            $calc = $calcs[$a->id];
            if (Money::cmp($calc, $a->balance) !== 0) {
                $bad[] = ['id' => $a->id, 'currency' => $a->currency, 'account' => $a->name, 'stored' => (string) $a->balance, 'ledger' => $calc, 'difference' => Money::sub($a->balance, $calc)];
            }
        }

        return $bad;
    }

    /** Put right a stored balance that disagrees with the ledger (the ledger is the truth). Logged. */
    public function repair(AcctAccount $a, ?\App\Models\User $by = null): string
    {
        return $this->db()->transaction(function () use ($a, $by) {
            $l = AcctAccount::query()->lockForUpdate()->findOrFail($a->id);
            $calc = $this->asOf($l, '2999-12-31');
            if (Money::cmp($calc, $l->balance) !== 0) {
                AcctAudit::log('account.balance_repaired', 'account', $l->id, $l->code, ['balance' => (string) $l->balance], ['balance' => $calc], __('Recalculated from the ledger.'), $by);
                $l->forceFill(['balance' => $calc, 'balance_updated_at' => now()])->save();
            }

            return $calc;
        });
    }
}
