<?php

namespace App\Services\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctApproval;
use App\Models\Accounts\AcctReconciliation;
use App\Models\Accounts\AcctReconItem;
use App\Models\Accounts\AcctTransaction;
use App\Models\User;
use App\Support\Accounts\AcctException;
use App\Support\Accounts\Money;
use App\Support\ReferenceLock;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation: cash counts and bank statements against what WIMS says. A difference is never hidden: it is recorded, alerted, explained,
 * approved by someone else and, when it has to be corrected, put right with an ordinary adjustment that goes through approval and posting.
 * The system balance is never overwritten to make a count agree.
 */
class AcctReconciler
{
    private function db()
    {
        return DB::connection('accounts');
    }

    /** account id => the period end of its latest APPROVED reconciliation, for many accounts in one query (the lists that used to ask one account at a time). */
    public static function lastApproved(?iterable $accountIds = null): array
    {
        $q = AcctReconciliation::query()->where('status', 'approved')->groupBy('account_id')->selectRaw('account_id, MAX(period_to) as last');
        if ($accountIds !== null) {
            $q->whereIn('account_id', collect($accountIds)->all() ?: [0]);
        }

        return $q->pluck('last', 'account_id')->all();
    }

    public function startCash(AcctAccount $a, User $by, array $d): AcctReconciliation
    {
        $this->guard($by);
        $cur = $a->currency;
        $counted = null;
        $denoms = null;
        if (! empty($d['denominations']) && is_array($d['denominations'])) {
            $sum = '0';
            foreach ($d['denominations'] as $face => $count) {
                // the face value is a plain positive number: "abc" or "-5000" is refused with a message (it used to reach the arithmetic and crash, or count negative cash)
                $face = trim((string) $face);
                if (! preg_match('/^\d{1,12}(\.\d{1,4})?$/', $face) || ! Money::isPositive($face)) {
                    throw new AcctException(__('Each note or coin value must be a positive number.'));
                }
                $n = (int) $count;
                if ($n < 0) {
                    throw new AcctException(__('A count of notes or coins cannot be negative.'));
                }
                $sum = Money::add($sum, bcmul($face, (string) $n, 4));
                $this->guardCounted($sum);
            }
            $denoms = array_filter(array_map('intval', $d['denominations']));
            $counted = $sum;
            if (isset($d['counted_balance']) && $d['counted_balance'] !== '' && Money::cmp($d['counted_balance'], $sum) !== 0) {
                throw new AcctException(__('The notes and coins add up to :s but the counted total is :t. Make them agree.', ['s' => Money::format($sum, AcctRates::precision($cur)), 't' => Money::format($d['counted_balance'], AcctRates::precision($cur))]));
            }
        }
        $counted ??= $this->counted($d['counted_balance'] ?? throw new AcctException(__('Enter the cash counted.')));

        return $this->start($a, $by, 'cash', $d['period_to'], $counted, $d + ['denominations' => $denoms]);
    }

    public function startBank(AcctAccount $a, User $by, array $d): AcctReconciliation
    {
        $this->guard($by);

        return $this->start($a, $by, 'bank', $d['period_to'], $this->counted($d['counted_balance'] ?? throw new AcctException(__('Enter the closing balance on the bank statement.'))), $d);
    }

    /** A counted figure as a clean amount: a number the ledger can hold, never one that would overflow the column. */
    private function counted($v): string
    {
        try {
            $n = Money::norm($v);
        } catch (\InvalidArgumentException) {
            throw new AcctException(__('Enter a valid amount.'));
        }
        $this->guardCounted($n);

        return $n;
    }

    private function guardCounted(string $n): void
    {
        if (Money::cmp(ltrim($n, '-'), Money::MAX_ENTRY) > 0) {
            throw new AcctException(__('That amount is too large.'));
        }
    }

    private function start(AcctAccount $a, User $by, string $kind, string $to, string $counted, array $d): AcctReconciliation
    {
        $bal = app(AcctBalance::class);
        $expected = $bal->asOf($a, $to);
        $diff = Money::sub($counted, $expected);
        if (Money::cmp(ltrim($diff, '-'), Money::MAX_BALANCE) > 0) {
            throw new AcctException(__('That amount is too large.'));
        }

        $r = ReferenceLock::run('acct_recon', fn () => $this->db()->transaction(fn () => AcctReconciliation::query()->create([
            'reference' => AcctNumbers::next('acct_reconciliations', 'REC'), 'account_id' => $a->id, 'kind' => $kind, 'period_from' => $d['period_from'] ?? null, 'period_to' => $to,
            'opening_balance' => $a->opening_balance, 'expected_balance' => $expected, 'counted_balance' => $counted, 'difference' => $diff, 'denominations' => $d['denominations'] ?? null,
            'counted_by' => $by->id, 'witness_name' => $d['witness_name'] ?? null, 'status' => 'draft', 'explanation' => $d['explanation'] ?? null, 'prepared_by' => $by->id,
        ])));
        AcctAudit::log('reconciliation.created', 'reconciliation', $r->id, $r->reference, null, ['account' => $a->name, 'expected' => $expected, 'counted' => $counted, 'difference' => $diff], null, $by);
        if (Money::cmp($diff, '0') !== 0) {
            $this->discrepancy($r, $a);
        }

        return $r;
    }

    private function discrepancy(AcctReconciliation $r, AcctAccount $a): void
    {
        $big = AcctSettings::amountFor('discrepancy_dual_over', $a->currency);
        $sev = ($big !== null && Money::cmp(ltrim((string) $r->difference, '-'), $big) > 0) ? 'high' : 'medium';
        AcctAlerts::raise('reconciliation', $r->kind === 'cash' ? 'cash_discrepancy' : 'bank_discrepancy', $sev,
            $r->kind === 'cash' ? __('Cash difference on :a', ['a' => $a->name]) : __('Bank statement difference on :a', ['a' => $a->name]),
            __('Counted :c, WIMS says :e: a difference of :d :cur.', ['c' => Money::format($r->counted_balance, AcctRates::precision($a->currency)), 'e' => Money::format($r->expected_balance, AcctRates::precision($a->currency)), 'd' => Money::format($r->difference, AcctRates::precision($a->currency)), 'cur' => $a->currency]),
            ['account_id' => $a->id, 'subject_type' => 'reconciliation', 'subject_id' => $r->id, 'amount' => $r->difference, 'currency' => $a->currency, 'dedupe' => 'recon-diff-' . $r->id, 'notify_event' => 'reconciliation_alert']);
    }

    /** The most statement lines accepted in one go (the matching and the page stay quick, and a wrong file is noticed early). */
    public const MAX_IMPORT_LINES = 5000;

    /**
     * Add statement lines (manual entry or a parsed CSV). A repeated line is flagged as a possible duplicate, not silently dropped.
     * The lines already on the reconciliation are read once, repeats are found in memory and the new rows go in together in one transaction.
     */
    public function addItems(AcctReconciliation $r, array $rows): int
    {
        if ($r->status !== 'draft') {
            throw new AcctException(__('Items can only be added while the reconciliation is a draft.'));
        }
        if (count($rows) > self::MAX_IMPORT_LINES) {
            throw new AcctException(__('A statement can have at most :n lines at a time. Split the file and import it in parts.', ['n' => number_format(self::MAX_IMPORT_LINES)]));
        }
        // date|direction|amount => the references seen with it (null = a line without a reference)
        $seen = [];
        foreach (AcctReconItem::query()->where('reconciliation_id', $r->id)->get(['entry_date', 'direction', 'amount', 'reference']) as $e) {
            $seen[$e->entry_date->toDateString() . '|' . $e->direction . '|' . Money::norm((string) $e->amount)][] = $e->reference;
        }
        $new = [];
        $now = now();
        foreach ($rows as $x) {
            $amt = Money::norm($x['amount']);
            if (! Money::isPositive($amt) || ! in_array($x['direction'], ['in', 'out'], true) || Money::cmp($amt, Money::MAX_ENTRY) > 0) {
                continue;
            }
            try {
                $date = \Carbon\Carbon::parse($x['entry_date'])->toDateString();
            } catch (\Throwable) {
                continue;
            }
            $ref = $x['reference'] ?? null;
            $key = $date . '|' . $x['direction'] . '|' . $amt;
            // a repeat: same day, direction and amount, and the same reference (or either side has none)
            $dup = false;
            foreach ($seen[$key] ?? [] as $other) {
                if ($other === null || ($ref !== null && $other === $ref)) {
                    $dup = true;
                    break;
                }
            }
            $seen[$key][] = $ref;
            $new[] = ['reconciliation_id' => $r->id, 'entry_date' => $date, 'description' => $x['description'] ?? null, 'reference' => $ref, 'direction' => $x['direction'], 'amount' => $amt,
                'status' => $dup ? 'duplicate' : 'unmatched', 'note' => $dup ? __('Looks like a repeat of another line.') : null, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($new) {
            $this->db()->transaction(function () use ($new) {
                foreach (array_chunk($new, 500) as $chunk) {
                    AcctReconItem::query()->insert($chunk);
                }
            });
        }

        return count($new);
    }

    /** Parse a bank statement CSV: needs a date column and either debit/credit columns or one signed amount column. */
    public function parseCsv(string $path): array
    {
        $h = fopen($path, 'r');
        $head = fgetcsv($h) ?: [];
        if ($head) {
            $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $head[0]); // Excel's "CSV UTF-8" starts the file with a byte-order mark that would hide the first heading
        }
        $idx = fn (array $names) => collect($head)->search(fn ($c) => in_array(strtolower(trim((string) $c)), $names, true));
        $iDate = $idx(['date', 'transaction date', 'value date', 'posting date']);
        $iDesc = $idx(['description', 'details', 'narration', 'particulars']);
        $iRef = $idx(['reference', 'ref', 'cheque', 'transaction reference']);
        $iDr = $idx(['debit', 'withdrawal', 'withdrawals', 'paid out', 'dr']);
        $iCr = $idx(['credit', 'deposit', 'deposits', 'paid in', 'cr']);
        $iAmt = $idx(['amount']);
        if ($iDate === false || ($iDr === false && $iCr === false && $iAmt === false)) {
            fclose($h);
            throw new AcctException(__('The file needs a Date column and Debit and Credit columns (or one Amount column).'));
        }
        $rows = [];
        while (($c = fgetcsv($h)) !== false) {
            if (count(array_filter($c, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            try {
                $date = \Carbon\Carbon::parse($c[$iDate])->toDateString();
                $num = function ($i) use ($c) {
                    $v = $i === false ? '' : trim((string) ($c[$i] ?? ''));
                    if ($v === '') {
                        return '0';
                    }
                    // accounting negatives: (500.00) and 500.00-; a comma followed by one or two digits at the end is a decimal comma (1.234,56)
                    $neg = (str_starts_with($v, '(') && str_ends_with($v, ')')) || str_ends_with($v, '-');
                    if (preg_match('/,\d{1,2}$/', $v) && ! preg_match('/\.\d+$/', $v)) {
                        $v = str_replace('.', '', $v);
                        $v = str_replace(',', '.', $v);
                    }
                    $n = preg_replace('/[^\d.\-]/', '', $v);
                    $n = ltrim($n, '-');

                    return Money::norm(($neg || str_starts_with($v, '-') ? '-' : '') . rtrim($n, '-'));
                };
                if ($iAmt !== false && $iDr === false && $iCr === false) {
                    $v = $num($iAmt);
                    $dir = Money::cmp($v, '0') < 0 ? 'out' : 'in';
                    $amt = ltrim($v, '-');
                } else {
                    $dr = $num($iDr);
                    $cr = $num($iCr);
                    [$dir, $amt] = Money::isPositive($dr) ? ['out', $dr] : ['in', $cr];
                }
            } catch (\Throwable) {
                continue; // a line that cannot be read is skipped, not guessed
            }
            if (count($rows) >= self::MAX_IMPORT_LINES) {
                fclose($h);
                throw new AcctException(__('A statement can have at most :n lines at a time. Split the file and import it in parts.', ['n' => number_format(self::MAX_IMPORT_LINES)]));
            }
            $rows[] = ['entry_date' => $date, 'description' => $iDesc === false ? null : ($c[$iDesc] ?? null), 'reference' => $iRef === false ? null : (trim((string) ($c[$iRef] ?? '')) ?: null), 'direction' => $dir, 'amount' => $amt];
        }
        fclose($h);

        return $rows;
    }

    /**
     * Match statement lines to posted WIMS transactions (same direction and amount, within 3 days, or the same reference). Nothing is created or changed in the ledger.
     * The candidate transactions are read once and the lines are matched in memory; the matches are saved together.
     */
    public function autoMatch(AcctReconciliation $r): array
    {
        if ($r->status !== 'draft') {
            throw new AcctException(__('Only a draft reconciliation can be matched.'));
        }
        $used = array_flip(AcctReconItem::query()->where('reconciliation_id', $r->id)->whereNotNull('matched_txn_id')->pluck('matched_txn_id')->all());
        $items = AcctReconItem::query()->where('reconciliation_id', $r->id)->where('status', 'unmatched')->orderBy('entry_date')->orderBy('id')->get();
        $matches = [];
        if ($items->isNotEmpty()) {
            $from = \Carbon\Carbon::parse($items->min(fn ($i) => $i->entry_date->toDateString()))->subDays(3)->toDateString();
            $to = \Carbon\Carbon::parse($items->max(fn ($i) => $i->entry_date->toDateString()))->addDays(3)->toDateString();
            $pool = [];
            foreach (AcctTransaction::query()->where('account_id', $r->account_id)->whereIn('status', AcctTransaction::LEDGER)->whereNull('reconciled_at')->whereBetween('txn_date', [$from, $to])
                ->orderBy('txn_date')->orderBy('id')->get(['id', 'direction', 'amount', 'txn_date', 'external_ref']) as $t) {
                $pool[$t->direction . '|' . Money::norm((string) $t->amount)][] = $t;
            }
            foreach ($items as $it) {
                $lo = $it->entry_date->copy()->subDays(3)->toDateString();
                $hi = $it->entry_date->copy()->addDays(3)->toDateString();
                $best = null;
                foreach ($pool[$it->direction . '|' . Money::norm((string) $it->amount)] ?? [] as $c) {
                    $d = $c->txn_date->toDateString();
                    if (isset($used[$c->id]) || $d < $lo || $d > $hi) {
                        continue;
                    }
                    if ($it->reference && mb_strtolower((string) $c->external_ref) === mb_strtolower((string) $it->reference)) {
                        $best = $c; // the same reference wins over anything else
                        break;
                    }
                    $best ??= $c; // otherwise the earliest date (the pool is in date order)
                }
                if ($best) {
                    $matches[$it->id] = $best->id;
                    $used[$best->id] = true;
                }
            }
        }
        if ($matches) {
            $this->db()->transaction(function () use ($matches) {
                foreach (array_chunk($matches, 500, true) as $chunk) {
                    $case = 'CASE id'; // ids come from the database: whole numbers, written straight into the statement
                    foreach ($chunk as $itemId => $txnId) {
                        $case .= ' WHEN ' . (int) $itemId . ' THEN ' . (int) $txnId;
                    }
                    $case .= ' END';
                    AcctReconItem::query()->whereIn('id', array_keys($chunk))->update(['matched_txn_id' => DB::raw("({$case})"), 'status' => 'matched', 'note' => null, 'updated_at' => now()]);
                }
            });
        }
        $matched = count($matches);
        $left = AcctReconItem::query()->where('reconciliation_id', $r->id)->where('status', 'unmatched')->count();
        if ($left > 0) {
            AcctAlerts::raise('reconciliation', 'unmatched_items', 'medium', __(':n statement line(s) are unmatched on :r', ['n' => $left, 'r' => $r->reference]), null, ['account_id' => $r->account_id, 'subject_type' => 'reconciliation', 'subject_id' => $r->id, 'dedupe' => 'recon-unmatched-' . $r->id, 'notify_event' => 'reconciliation_alert']);
        }

        return ['matched' => $matched, 'unmatched' => $left];
    }

    public function submit(AcctReconciliation $r, User $by): AcctReconciliation
    {
        $this->guard($by);
        if (! in_array($r->status, ['draft', 'rejected'], true)) {
            return $r;
        }
        if (Money::cmp($r->difference, '0') !== 0 && trim((string) $r->explanation) === '') {
            throw new AcctException(__('Explain the difference before submitting.'));
        }
        $r->fill(['status' => 'submitted', 'submitted_at' => now(), 'reject_reason' => null])->save();
        AcctApproval::query()->create(['subject_type' => 'reconciliation', 'subject_id' => $r->id, 'action' => 'submitted', 'user_id' => $by->id, 'created_at' => now()]);
        AcctAudit::log('reconciliation.submitted', 'reconciliation', $r->id, $r->reference, null, ['difference' => (string) $r->difference], null, $by);

        return $r;
    }

    /**
     * Approve a reconciliation. The preparer and the counter can never approve their own; a difference above the limit needs two different approvers.
     * With $propose, an adjustment transaction for the difference is created (submitted for its own approval and posting): the balance is not touched here.
     */
    public function approve(AcctReconciliation $r, User $by, bool $propose = false): AcctReconciliation
    {
        return $this->db()->transaction(function () use ($r, $by, $propose) {
            $r = AcctReconciliation::query()->lockForUpdate()->findOrFail($r->id);
            if ($r->status === 'approved') {
                return $r;
            }
            if ($r->status !== 'submitted') {
                throw new AcctException(__('This reconciliation is not waiting for approval.'));
            }
            if (! $by->can('approve-acct-reconciliations')) {
                throw new AcctException(__('You are not allowed to approve reconciliations.'));
            }
            if (in_array((int) $by->id, [(int) $r->prepared_by, (int) $r->counted_by], true) && AcctSettings::get()['maker_checker']) {
                throw new AcctException(__('Someone other than the person who prepared or counted must approve this.'));
            }
            // only approvals given since the last submission count (a rejected and re-sent reconciliation starts its approvals again)
            $since = (int) AcctApproval::query()->where('subject_type', 'reconciliation')->where('subject_id', $r->id)->where('action', 'submitted')->max('id');
            if (AcctApproval::query()->where('subject_type', 'reconciliation')->where('subject_id', $r->id)->where('action', 'approved')->where('id', '>', $since)->where('user_id', $by->id)->exists()) {
                throw new AcctException(__('You have already approved this. A second, different approver is needed.'));
            }
            AcctApproval::query()->create(['subject_type' => 'reconciliation', 'subject_id' => $r->id, 'action' => 'approved', 'user_id' => $by->id, 'created_at' => now()]);
            $acct = AcctAccount::query()->findOrFail($r->account_id);
            $need = 1;
            $big = AcctSettings::amountFor('discrepancy_dual_over', $acct->currency);
            if ($big !== null && Money::cmp(ltrim((string) $r->difference, '-'), $big) > 0) {
                $need = 2;
            }
            $have = AcctApproval::query()->where('subject_type', 'reconciliation')->where('subject_id', $r->id)->where('action', 'approved')->where('id', '>', $since)->count();
            if ($have < $need) {
                AcctAudit::log('reconciliation.approval_given', 'reconciliation', $r->id, $r->reference, null, ['approvals' => "$have/$need"], null, $by);

                return $r;
            }
            $r->fill(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()])->save();
            $ids = AcctReconItem::query()->where('reconciliation_id', $r->id)->whereNotNull('matched_txn_id')->pluck('matched_txn_id');
            AcctTransaction::query()->whereIn('id', $ids)->update(['reconciliation_id' => $r->id, 'reconciled_at' => now()]);
            if ($propose && Money::cmp($r->difference, '0') !== 0) {
                $svc = app(AcctPosting::class);
                $dir = Money::cmp($r->difference, '0') > 0 ? 'in' : 'out';
                $adj = $svc->create(['account_id' => $r->account_id, 'direction' => $dir, 'kind' => 'adjustment', 'amount' => ltrim((string) $r->difference, '-'), 'txn_date' => now()->toDateString(),
                    'description' => __('Adjustment for :r', ['r' => $r->reference]), 'reason' => $r->explanation, 'source_module' => 'reconciliation', 'source_type' => 'reconciliation', 'source_id' => $r->id], $by);
                $svc->submit($adj, $by);
                $r->forceFill(['adjustment_txn_id' => $adj->id])->save();
            }
            AcctAlerts::clear('recon-overdue-' . $r->account_id, 'The account was reconciled.');
            AcctAudit::log('reconciliation.approved', 'reconciliation', $r->id, $r->reference, null, ['difference' => (string) $r->difference, 'adjustment' => $r->adjustment_txn_id], null, $by);

            return $r;
        });
    }

    public function reject(AcctReconciliation $r, User $by, string $reason): AcctReconciliation
    {
        if ($r->status !== 'submitted' || ! $by->can('approve-acct-reconciliations')) {
            throw new AcctException(__('This reconciliation cannot be rejected by you now.'));
        }
        if (trim($reason) === '') {
            throw new AcctException(__('Give a reason for rejecting.'));
        }
        $r->fill(['status' => 'rejected', 'reject_reason' => $reason])->save();
        AcctAudit::log('reconciliation.rejected', 'reconciliation', $r->id, $r->reference, null, null, $reason, $by);

        return $r;
    }

    private function guard(User $by): void
    {
        if (! $by->can('perform-acct-reconciliations')) {
            throw new AcctException(__('You are not allowed to prepare reconciliations.'));
        }
    }
}
