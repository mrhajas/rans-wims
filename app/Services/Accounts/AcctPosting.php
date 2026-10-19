<?php

namespace App\Services\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctApproval;
use App\Models\Accounts\AcctApprovalRule;
use App\Models\Accounts\AcctAttachment;
use App\Models\Accounts\AcctCategory;
use App\Models\Accounts\AcctTransaction;
use App\Models\Accounts\AcctTransfer;
use App\Models\User;
use App\Support\Accounts\AcctException;
use App\Support\Accounts\AcctPermissions;
use App\Support\Accounts\Money;
use App\Support\ReferenceLock;
use Illuminate\Support\Facades\DB;

/**
 * The financial engine: creating, approving, posting, reversing and transferring money between accounts.
 *
 *  - Lifecycle: draft -> submitted -> approved -> posted (rejected, cancelled and reversed branch off). Only POSTED rows change a balance.
 *  - Posting runs inside ONE database transaction on the accounts schema, under row locks on the transaction and the account(s), and is
 *    idempotent: posting an already-posted record returns it untouched, so a retry, a double click or two requests at once change the balance once.
 *  - Posted records are never edited or deleted. A correction is a linked reversal (or an adjustment).
 *  - Money is handled as decimal strings (see Money); a currency's own precision is enforced on every amount.
 */
class AcctPosting
{
    /** A database transaction that is tried again (up to 3 times) if MySQL picks it as the loser of a deadlock between two people posting at once. */
    private function tx(\Closure $f)
    {
        return $this->db()->transaction($f, 3);
    }

    private function db()
    {
        return DB::connection('accounts');
    }

    // ------------------------------------------------------------------ transactions

    /**
     * Create a DRAFT transaction. With an idempotency key, sending the same request again returns the record that was made the first time.
     *
     * @param array{account_id:int, direction:string, amount:string|numeric, txn_date:string} $d
     */
    public function create(array $d, User $by): AcctTransaction
    {
        // A repeat of the same request returns the record the first one made. The key only counts for the same person and the same account: a key that
        // someone else already used (or that was sent for another account) is ignored, so a guessed key can neither return nor block another person's record.
        if (! empty($d['idempotency_key'])) {
            $existing = AcctTransaction::query()->where('idempotency_key', $d['idempotency_key'])->first();
            if ($existing && (int) $existing->created_by === (int) $by->id && (int) $existing->account_id === (int) ($d['account_id'] ?? 0)) {
                return $existing;
            }
            if ($existing) {
                unset($d['idempotency_key']);
            }
        }
        if (! in_array($d['direction'] ?? null, ['in', 'out'], true)) {
            throw new AcctException(__('Choose whether the money comes in or goes out.'));
        }
        $kind = $d['kind'] ?? ($d['direction'] === 'in' ? 'receipt' : 'payment');
        // the kind never contradicts the direction: a "receipt" that takes money out (or a "payment" that brings it in) would be reported on the wrong side
        if (($kind === 'receipt' && $d['direction'] !== 'in') || ($kind === 'payment' && $d['direction'] !== 'out')) {
            throw new AcctException(__('A receipt brings money in and a payment takes money out.'));
        }
        $this->guardAbility($by, $d['direction'], $kind);
        $acct = AcctAccount::query()->find($d['account_id']) ?? throw new AcctException(__('That account does not exist.'));
        $this->guardUsable($acct, $by);
        $currency = $acct->currency;
        if (! empty($d['currency']) && $d['currency'] !== $currency) {
            throw new AcctException(__('The transaction currency (:c) must match the account currency (:a). Use a transfer to convert between currencies.', ['c' => $d['currency'], 'a' => $currency]));
        }
        $amount = $this->amount($d['amount'], $currency);
        $this->guardDate($d['txn_date']);
        $this->guardValueDate($d['value_date'] ?? null);
        $this->guardCategory($d['category_id'] ?? null, $d['direction']);
        $this->guardLimit($acct, $amount);

        $fields = array_intersect_key($d, array_flip([
            'txn_time', 'value_date', 'description', 'reason', 'payment_method', 'method_details', 'external_ref', 'receipt_no', 'invoice_no', 'voucher_no', 'counterparty_type', 'counterparty_name',
            'contact_id', 'staff_id', 'warehouse_id', 'department', 'cost_centre', 'fund_allocation_id', 'fund_item_line_id', 'source_module', 'source_type', 'source_id', 'notes', 'idempotency_key',
        ]));
        $this->guardMethod($fields);

        $repeat = false;
        $txn = ReferenceLock::run('acct_txn', function () use ($d, $kind, $acct, $currency, $amount, $fields, $by, &$repeat) {
            // two identical requests at the same moment both passed the check above: the one that gets the lock second finds the first one's record and returns it
            if (! empty($d['idempotency_key']) && ($first = AcctTransaction::query()->where('idempotency_key', $d['idempotency_key'])->first())) {
                if ((int) $first->created_by === (int) $by->id && (int) $first->account_id === (int) $acct->id) {
                    $repeat = true;

                    return $first;
                }
                unset($d['idempotency_key'], $fields['idempotency_key']); // taken by somebody else in the meantime: this request goes ahead without it
            }

            return $this->tx(fn () => AcctTransaction::query()->create($fields + [
                'reference' => AcctNumbers::next('acct_transactions', 'ACT'), 'account_id' => $acct->id, 'direction' => $d['direction'], 'kind' => $kind, 'currency' => $currency, 'amount' => $amount,
                'txn_date' => $d['txn_date'], 'category_id' => $d['category_id'] ?? null, 'status' => 'draft', 'created_by' => $by->id,
            ]));
        });
        if ($repeat) {
            return $txn;
        }
        AcctAudit::log('transaction.created', 'transaction', $txn->id, $txn->reference, null, $this->snap($txn), null, $by);

        return $txn;
    }

    /** Edit a transaction that has not been posted (draft, or rejected and being corrected). A posted record is never edited. */
    public function update(AcctTransaction $txn, array $d, User $by): AcctTransaction
    {
        // The status is read again under a lock: the copy handed in may be old (someone submitted, approved or posted it since), and an approved amount must never change.
        return $this->tx(function () use ($txn, $d, $by) {
            $txn = AcctTransaction::query()->lockForUpdate()->findOrFail($txn->id);
            if (! in_array($txn->status, ['draft', 'rejected'], true) || $txn->transfer_id) {
                throw new AcctException(__('Only a draft or rejected transaction can be edited. A posted one is corrected with a reversal or an adjustment.'));
            }
            if ((int) $txn->created_by !== (int) $by->id && ! $by->hasRole('Super Admin')) {
                throw new AcctException(__('Only the person who created this transaction can change it.'));
            }
            $old = $this->snap($txn);
            $acct = AcctAccount::query()->findOrFail($d['account_id'] ?? $txn->account_id);
            $this->guardUsable($acct, $by);
            $set = array_intersect_key($d, array_flip(['txn_time', 'value_date', 'description', 'reason', 'payment_method', 'method_details', 'external_ref', 'receipt_no', 'invoice_no', 'voucher_no',
                'counterparty_type', 'counterparty_name', 'contact_id', 'staff_id', 'warehouse_id', 'department', 'cost_centre', 'fund_allocation_id', 'notes', 'category_id', 'txn_date']));
            // the amount is checked again against the (possibly new) account: its currency precision and its one-transaction limit
            $set['amount'] = $this->amount($d['amount'] ?? $txn->amount, $acct->currency);
            $this->guardLimit($acct, $set['amount']);
            if (! empty($set['category_id'])) {
                $this->guardCategory($set['category_id'], $txn->direction);
            }
            if (isset($set['txn_date'])) {
                $this->guardDate($set['txn_date']);
            }
            $this->guardValueDate($set['value_date'] ?? null);
            $this->guardMethod($set + $txn->only(['payment_method', 'method_details']));
            if ($acct->id !== $txn->account_id) {
                $set['account_id'] = $acct->id;
                $set['currency'] = $acct->currency;
            }
            $txn->fill($set + ['status' => 'draft', 'reject_reason' => null])->save();
            AcctAudit::log('transaction.updated', 'transaction', $txn->id, $txn->reference, $old, $this->snap($txn), null, $by);

            return $txn;
        });
    }

    /** Send for approval. Works out how many approvals the rules ask for; none needed means it is approved at once. */
    public function submit(AcctTransaction $txn, User $by): AcctTransaction
    {
        return $this->tx(function () use ($txn, $by) {
            $t = AcctTransaction::query()->lockForUpdate()->findOrFail($txn->id);
            if (! in_array($t->status, ['draft', 'rejected'], true)) {
                return $t; // already on its way: a second click changes nothing
            }
            if (! $by->can('submit-acct-transactions') && (int) $t->created_by !== (int) $by->id) {
                throw new AcctException(__('You are not allowed to submit this transaction.'));
            }
            $acct = AcctAccount::query()->findOrFail($t->account_id);
            $this->guardUsable($acct, $by);
            $this->guardDate($t->txn_date->toDateString());
            $this->needsAttachment($t);
            $kind = $t->kind === 'adjustment' ? 'adjustment' : ($t->direction === 'in' ? 'inflow' : 'outflow');
            $required = $this->requiredApprovals($kind, $t->currency, (string) $t->amount, $acct->id); // never less than one: money in, money out and adjustments always need approval
            $t->fill(['status' => 'submitted', 'required_approvals' => $required, 'approvals_count' => 0, 'submitted_by' => $by->id, 'submitted_at' => now(), 'reject_reason' => null])->save();
            $this->trail('transaction', $t->id, 'submitted', $by, null, false);
            AcctAudit::log('transaction.submitted', 'transaction', $t->id, $t->reference, null, ['status' => $t->status, 'required_approvals' => $required], null, $by);
            $this->afterSubmit($t, $acct);

            return $t;
        });
    }

    public function approve(AcctTransaction $txn, User $by, ?string $comment = null): AcctTransaction
    {
        $this->denyUnlessCanApprove($txn, 'transaction', $by);

        return $this->tx(function () use ($txn, $by, $comment) {
            $t = AcctTransaction::query()->lockForUpdate()->findOrFail($txn->id);
            $this->approveLocked($t, 'transaction', $by, $comment);

            return $t;
        });
    }

    public function reject(AcctTransaction $txn, User $by, string $reason): AcctTransaction
    {
        return $this->tx(function () use ($txn, $by, $reason) {
            $t = AcctTransaction::query()->lockForUpdate()->findOrFail($txn->id);
            $this->rejectLocked($t, 'transaction', $by, $reason);

            return $t;
        });
    }

    public function cancel(AcctTransaction $txn, User $by, string $reason): AcctTransaction
    {
        return $this->tx(function () use ($txn, $by, $reason) {
            $t = AcctTransaction::query()->lockForUpdate()->findOrFail($txn->id);
            if (! in_array($t->status, ['draft', 'submitted', 'approved', 'rejected'], true) || $t->transfer_id) {
                throw new AcctException(__('Only a transaction that has not been posted can be cancelled. A posted one is reversed instead.'));
            }
            if (trim($reason) === '') {
                throw new AcctException(__('Give a reason for cancelling.'));
            }
            $freed = $this->releaseSource($t);
            $t->fill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();
            $this->trail('transaction', $t->id, 'cancelled', $by, $reason, false);
            AcctAudit::log('transaction.cancelled', 'transaction', $t->id, $t->reference, null, ['status' => 'cancelled'] + $freed, $reason, $by);

            return $t;
        });
    }

    /**
     * Post an APPROVED transaction: the one step that changes a balance. Atomic, locked and idempotent.
     */
    public function post(AcctTransaction $txn, User $by): AcctTransaction
    {
        try {
            return $this->tx(function () use ($txn, $by) {
                $t = AcctTransaction::query()->lockForUpdate()->findOrFail($txn->id);
                if ($t->status === 'posted') {
                    return $t; // posted already (retry, double click, second request): nothing more happens
                }
                if ($t->transfer_id) {
                    throw new AcctException(__('A transfer is posted as a whole, from the transfer.'));
                }
                if ($t->status !== 'approved') {
                    throw new AcctException(__('Only an approved transaction can be posted.'));
                }
                if (! $by->can(AcctPermissions::need('post', $t))) {
                    throw new AcctException(__('You are not allowed to post this kind of transaction.'));
                }
                $acct = AcctAccount::query()->lockForUpdate()->findOrFail($t->account_id);
                $this->guardValueDate($t->value_date?->toDateString());
                $this->applyToAccount($acct, $t->direction, (string) $t->amount, $t->currency, $t->txn_date->toDateString());
                $t->fill(['status' => 'posted', 'posted_by' => $by->id, 'posted_at' => now(), 'reporting_amount' => null])->save();
                $this->reportingValue($t);
                $this->trail('transaction', $t->id, 'posted', $by, null, false);
                AcctAudit::log('transaction.posted', 'transaction', $t->id, $t->reference, null, ['balance_after' => (string) $acct->balance], null, $by);
                $this->db()->afterCommit(fn () => $this->afterPost($t->fresh(), $acct->fresh()));

                return $t;
            });
        } catch (AcctException $e) {
            $this->failed($txn, $by, $e->getMessage());
            throw $e;
        }
    }

    /** Reverse a POSTED transaction: the original stays as it is (marked reversed) and a linked, opposite, posted entry corrects the balance. */
    public function reverse(AcctTransaction $txn, User $by, string $reason): AcctTransaction
    {
        if (! $by->can('reverse-acct-transactions')) {
            throw new AcctException(__('You are not allowed to reverse transactions.'));
        }
        if (trim($reason) === '') {
            throw new AcctException(__('Give a reason for the reversal.'));
        }

        return ReferenceLock::run('acct_txn', fn () => $this->tx(function () use ($txn, $by, $reason) {
            $t = AcctTransaction::query()->lockForUpdate()->findOrFail($txn->id);
            if ($t->status === 'reversed') {
                return AcctTransaction::query()->findOrFail($t->reversed_by_id);
            }
            if ($t->status !== 'posted') {
                throw new AcctException(__('Only a posted transaction can be reversed.'));
            }
            if ($t->kind === 'reversal') {
                throw new AcctException(__('A reversal cannot itself be reversed. Record a new transaction instead.'));
            }
            if ($t->transfer_id) {
                throw new AcctException(__('Reverse the whole transfer instead of one side of it.'));
            }
            $rev = $this->reverseOne($t, $by, $reason);
            AcctAudit::log('transaction.reversed', 'transaction', $t->id, $t->reference, ['status' => 'posted'], ['status' => 'reversed', 'reversal' => $rev->reference], $reason, $by);

            return $rev;
        }));
    }

    // ------------------------------------------------------------------ transfers

    /**
     * Create a DRAFT transfer between two company accounts. Same currency: the credit equals the debit. Different currencies: an APPROVED exchange rate
     * is required (nothing is assumed), the actual credited amount is entered, and any difference from amount x rate is recorded as an exchange difference.
     */
    public function createTransfer(array $d, User $by): AcctTransfer
    {
        if (! empty($d['idempotency_key'])) {
            $existing = AcctTransfer::query()->where('idempotency_key', $d['idempotency_key'])->first();
            if ($existing && (int) $existing->created_by === (int) $by->id && (int) $existing->from_account_id === (int) ($d['from_account_id'] ?? 0) && (int) $existing->to_account_id === (int) ($d['to_account_id'] ?? 0)) {
                return $existing;
            }
            if ($existing) {
                unset($d['idempotency_key']); // somebody else's key (or another pair of accounts): ignored, never returned
            }
        }
        if (! $by->can('create-acct-transfers')) {
            throw new AcctException(__('You are not allowed to create transfers.'));
        }
        $a = $this->transferAttrs($d, $by);

        $repeat = false;
        $tr = ReferenceLock::run('acct_transfer', function () use ($a, $d, $by, &$repeat) {
            if (! empty($d['idempotency_key']) && ($first = AcctTransfer::query()->where('idempotency_key', $d['idempotency_key'])->first())) {
                if ((int) $first->created_by === (int) $by->id && (int) $first->from_account_id === (int) $a['from_account_id'] && (int) $first->to_account_id === (int) $a['to_account_id']) {
                    $repeat = true; // the same request arrived twice at once: return the record the first one made

                    return $first;
                }
                unset($d['idempotency_key']);
            }

            return $this->db()->transaction(fn () => AcctTransfer::query()->create($a + [
                'reference' => AcctNumbers::next('acct_transfers', 'TRF'), 'idempotency_key' => $d['idempotency_key'] ?? null, 'status' => 'draft', 'created_by' => $by->id,
            ]));
        });
        if ($repeat) {
            return $tr;
        }
        AcctAudit::log('transfer.created', 'transfer', $tr->id, $tr->reference, null, $this->snap($tr), null, $by);

        return $tr;
    }

    /** Change a draft or rejected transfer. Every rule is applied again to the new figures. */
    public function updateTransfer(AcctTransfer $tr, array $d, User $by): AcctTransfer
    {
        return $this->tx(function () use ($tr, $d, $by) {
            $t = AcctTransfer::query()->lockForUpdate()->findOrFail($tr->id);
            if (! in_array($t->status, ['draft', 'rejected'], true)) {
                throw new AcctException(__('Only a draft or rejected transfer can be edited. A posted one is corrected with a reversal.'));
            }
            if ((int) $t->created_by !== (int) $by->id && ! $by->hasRole('Super Admin')) {
                throw new AcctException(__('Only the person who created this transfer can change it.'));
            }
            $old = $this->snap($t);
            $t->fill($this->transferAttrs($d, $by) + ['status' => 'draft', 'reject_reason' => null, 'approvals_count' => 0])->save();
            AcctAudit::log('transfer.updated', 'transfer', $t->id, $t->reference, $old, $this->snap($t), null, $by);

            return $t;
        });
    }

    /** Validate a transfer request and work out everything stored on it (accounts, amounts, rate, exchange difference, approvals needed). */
    private function transferAttrs(array $d, User $by): array
    {
        $from = AcctAccount::query()->find($d['from_account_id']) ?? throw new AcctException(__('Choose the account the money leaves.'));
        $to = AcctAccount::query()->find($d['to_account_id']) ?? throw new AcctException(__('Choose the account the money goes to.'));
        if ($from->id === $to->id) {
            throw new AcctException(__('The two accounts must be different.'));
        }
        $this->guardUsable($from, $by);
        $this->guardUsable($to, $by, false);
        $this->guardDate($d['transfer_date']);
        $fromAmt = $this->amount($d['from_amount'], $from->currency);
        $this->guardLimit($from, $fromAmt); // the one-transaction limit of the account the money leaves applies to a transfer as it does to a payment
        $fee = isset($d['fee_amount']) && $d['fee_amount'] !== '' ? $this->amount($d['fee_amount'], $from->currency, true) : '0.0000';
        $rateId = null;
        $rate = null;
        $inverse = false;
        $diff = '0.0000';
        if ($from->currency === $to->currency) {
            $toAmt = $fromAmt;
        } else {
            $found = ! empty($d['rate_id']) ? \App\Models\Accounts\AcctExchangeRate::query()->where('status', 'approved')->find($d['rate_id']) : null;
            if (! $found) {
                throw new AcctException(__('A currency transfer needs an approved exchange rate from :a to :b. Enter one under Currencies and Exchange Rates and have it approved first.', ['a' => $from->currency, 'b' => $to->currency]));
            }
            $on = \Carbon\Carbon::parse($d['transfer_date'])->toDateString();
            if ($found->effective_from->toDateString() > $on || ($found->effective_to !== null && $found->effective_to->toDateString() < $on)) {
                throw new AcctException(__('That exchange rate is not in force on :d (it applies from :f:t). Choose the rate for that date.', ['d' => $on, 'f' => $found->effective_from->toDateString(), 't' => $found->effective_to ? ' ' . __('to') . ' ' . $found->effective_to->toDateString() : '']));
            }
            $pair = [$found->base_currency, $found->quote_currency];
            if ($pair === [$from->currency, $to->currency]) {
                $rate = bcadd((string) $found->rate, '0', 8);
            } elseif ($pair === [$to->currency, $from->currency] && AcctSettings::get()['allow_inverse_rates']) {
                $rate = bcdiv('1', (string) $found->rate, 8);
                $inverse = true;
            } else {
                throw new AcctException(__('That exchange rate is for a different pair of currencies.'));
            }
            $rateId = $found->id;
            if (! isset($d['to_amount']) || $d['to_amount'] === '') {
                throw new AcctException(__('Enter the amount that is actually credited to the destination account.'));
            }
            $toAmt = $this->amount($d['to_amount'], $to->currency);
            // against the opposite rate the credit is amount / rate, worked out exactly: the shown inverse rate has only 8 decimals and would invent a small "exchange difference"
            $expected = $inverse ? Money::round(bcdiv($fromAmt, (string) $found->rate, 12), AcctRates::precision($to->currency)) : Money::convert($fromAmt, $rate, AcctRates::precision($to->currency));
            if (Money::cmp($expected, Money::MAX_BALANCE) > 0) {
                throw new AcctException(__('That amount is too large once it is converted to :c.', ['c' => $to->currency]));
            }
            $diff = Money::sub($toAmt, $expected);
            $tolerance = Money::convert($expected, bcdiv((string) AcctSettings::get()['rate_tolerance_pct'], '100', 8), AcctRates::precision($to->currency));
            if (Money::cmp(ltrim($diff, '-') === '' ? '0' : ltrim($diff, '-'), $tolerance) > 0 && trim((string) ($d['notes'] ?? '')) === '') {
                throw new AcctException(__('The credited amount differs from amount x rate by :d :c, more than the allowed :p%. Explain the difference in the notes, or correct the amounts.', ['d' => Money::format($diff, AcctRates::precision($to->currency)), 'c' => $to->currency, 'p' => AcctSettings::get()['rate_tolerance_pct']]));
            }
        }
        $this->reportingFor($fromAmt, $from->currency, \Carbon\Carbon::parse($d['transfer_date'])->toDateString());
        $required = $this->requiredApprovals('transfer', $from->currency, $fromAmt, $from->id);

        return [
            'from_account_id' => $from->id, 'to_account_id' => $to->id, 'from_currency' => $from->currency, 'to_currency' => $to->currency, 'from_amount' => $fromAmt, 'to_amount' => $toAmt, 'rate_id' => $rateId, 'exchange_rate' => $rate,
            'fee_amount' => $fee, 'rounding_difference' => $diff, 'transfer_date' => $d['transfer_date'], 'required_approvals' => $required,
            'reason' => $d['reason'] ?? null, 'warehouse_id' => $d['warehouse_id'] ?? null, 'department' => $d['department'] ?? null, 'external_ref' => $d['external_ref'] ?? null, 'notes' => $d['notes'] ?? null,
        ];
    }

    public function submitTransfer(AcctTransfer $tr, User $by): AcctTransfer
    {
        return $this->tx(function () use ($tr, $by) {
            $t = AcctTransfer::query()->lockForUpdate()->findOrFail($tr->id);
            if (! in_array($t->status, ['draft', 'rejected'], true)) {
                return $t;
            }
            $from = AcctAccount::query()->findOrFail($t->from_account_id);
            $required = $this->requiredApprovals('transfer', $t->from_currency, (string) $t->from_amount, $from->id);
            $t->fill(['status' => $required === 0 ? 'approved' : 'submitted', 'required_approvals' => $required, 'approvals_count' => 0, 'submitted_by' => $by->id, 'submitted_at' => now(), 'reject_reason' => null])->save();
            $this->trail('transfer', $t->id, 'submitted', $by, null, false);
            AcctAudit::log('transfer.submitted', 'transfer', $t->id, $t->reference, null, ['status' => $t->status, 'required_approvals' => $required], null, $by);
            if ($t->status === 'submitted') {
                AcctAlerts::raise('transfer', 'transfer_waiting', 'low', __('Transfer :r is waiting for approval', ['r' => $t->reference]), __('A transfer of :a :c is waiting for approval.', ['a' => Money::format($t->from_amount, AcctRates::precision($t->from_currency)), 'c' => $t->from_currency]), ['account_id' => $t->from_account_id, 'subject_type' => 'transfer', 'subject_id' => $t->id, 'amount' => $t->from_amount, 'currency' => $t->from_currency, 'dedupe' => 'transfer-wait-' . $t->id, 'notify_event' => 'approval_needed', 'approver_permission' => 'approve-acct-transfers']);
            }

            return $t;
        });
    }

    public function approveTransfer(AcctTransfer $tr, User $by, ?string $comment = null): AcctTransfer
    {
        $this->denyUnlessCanApprove($tr, 'transfer', $by);

        return $this->tx(function () use ($tr, $by, $comment) {
            $t = AcctTransfer::query()->lockForUpdate()->findOrFail($tr->id);
            $this->approveLocked($t, 'transfer', $by, $comment);

            return $t;
        });
    }

    public function rejectTransfer(AcctTransfer $tr, User $by, string $reason): AcctTransfer
    {
        return $this->tx(function () use ($tr, $by, $reason) {
            $t = AcctTransfer::query()->lockForUpdate()->findOrFail($tr->id);
            $this->rejectLocked($t, 'transfer', $by, $reason);

            return $t;
        });
    }

    public function cancelTransfer(AcctTransfer $tr, User $by, string $reason): AcctTransfer
    {
        return $this->tx(function () use ($tr, $by, $reason) {
            $t = AcctTransfer::query()->lockForUpdate()->findOrFail($tr->id);
            if (! in_array($t->status, ['draft', 'submitted', 'approved', 'rejected'], true)) {
                throw new AcctException(__('Only a transfer that has not been posted can be cancelled.'));
            }
            if (trim($reason) === '') {
                throw new AcctException(__('Give a reason for cancelling.'));
            }
            $t->fill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();
            $this->trail('transfer', $t->id, 'cancelled', $by, $reason, false);
            AcctAudit::log('transfer.cancelled', 'transfer', $t->id, $t->reference, null, ['status' => 'cancelled'], $reason, $by);

            return $t;
        });
    }

    /** Post a transfer: the debit, the credit and the fee are written together or not at all, and the transfer is never counted as income or expense. */
    public function postTransfer(AcctTransfer $tr, User $by): AcctTransfer
    {
        try {
            return ReferenceLock::run('acct_txn', fn () => $this->tx(function () use ($tr, $by) {
                $t = AcctTransfer::query()->lockForUpdate()->findOrFail($tr->id);
                if ($t->status === 'posted') {
                    return $t;
                }
                if ($t->status !== 'approved') {
                    throw new AcctException(__('Only an approved transfer can be posted.'));
                }
                if (! $by->can('post-acct-transfers')) {
                    throw new AcctException(__('You are not allowed to post transfers.'));
                }
                // lock both accounts in id order, so two transfers crossing each other can never deadlock
                $ids = [$t->from_account_id, $t->to_account_id];
                sort($ids);
                $locked = AcctAccount::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $from = $locked[$t->from_account_id];
                $to = $locked[$t->to_account_id];
                $date = $t->transfer_date->toDateString();
                $catId = AcctCategory::query()->where('name', 'Transfer between accounts')->value('id');
                $feeCat = AcctCategory::query()->where('name', 'Transfer / conversion fee')->value('id');
                $common = ['kind' => 'transfer', 'txn_date' => $date, 'transfer_id' => $t->id, 'status' => 'posted', 'posted_by' => $by->id, 'posted_at' => now(), 'created_by' => $t->created_by, 'submitted_by' => $t->submitted_by, 'required_approvals' => $t->required_approvals, 'approvals_count' => $t->approvals_count, 'category_id' => $catId, 'warehouse_id' => $t->warehouse_id, 'department' => $t->department, 'external_ref' => $t->external_ref, 'description' => __('Transfer :r', ['r' => $t->reference])];

                $this->applyToAccount($from, 'out', (string) $t->from_amount, $t->from_currency, $date);
                AcctTransaction::query()->create($common + ['reference' => AcctNumbers::next('acct_transactions', 'ACT'), 'account_id' => $from->id, 'direction' => 'out', 'currency' => $t->from_currency, 'amount' => $t->from_amount, 'rate_id' => $t->rate_id, 'exchange_rate' => $t->exchange_rate]);
                if (Money::isPositive($t->fee_amount)) {
                    $this->applyToAccount($from, 'out', (string) $t->fee_amount, $t->from_currency, $date);
                    AcctTransaction::query()->create(array_merge($common, ['kind' => 'fee', 'category_id' => $feeCat, 'description' => __('Fee for transfer :r', ['r' => $t->reference])]) + ['reference' => AcctNumbers::next('acct_transactions', 'ACT'), 'account_id' => $from->id, 'direction' => 'out', 'currency' => $t->from_currency, 'amount' => $t->fee_amount]);
                }
                $this->applyToAccount($to, 'in', (string) $t->to_amount, $t->to_currency, $date);
                AcctTransaction::query()->create($common + ['reference' => AcctNumbers::next('acct_transactions', 'ACT'), 'account_id' => $to->id, 'direction' => 'in', 'currency' => $t->to_currency, 'amount' => $t->to_amount, 'rate_id' => $t->rate_id, 'exchange_rate' => $t->exchange_rate]);

                $rep = $this->reportingFor((string) $t->from_amount, $t->from_currency, $date);
                $t->fill(['status' => 'posted', 'posted_by' => $by->id, 'posted_at' => now(), 'reporting_amount' => $rep['amount'] ?? null, 'reporting_currency' => $rep['currency'] ?? null])->save();
                $this->trail('transfer', $t->id, 'posted', $by, null, false);
                AcctAudit::log('transfer.posted', 'transfer', $t->id, $t->reference, null, ['from_balance' => (string) $from->balance, 'to_balance' => (string) $to->balance], null, $by);
                $this->db()->afterCommit(function () use ($t, $from, $to) {
                    AcctAlerts::scanAccount($from->fresh());
                    AcctAlerts::scanAccount($to->fresh());
                });

                return $t;
            }));
        } catch (AcctException $e) {
            AcctAudit::log('transfer.post_failed', 'transfer', $tr->id, $tr->reference, null, null, $e->getMessage(), $by);
            throw $e;
        }
    }

    public function reverseTransfer(AcctTransfer $tr, User $by, string $reason): AcctTransfer
    {
        if (! $by->can('reverse-acct-transactions')) {
            throw new AcctException(__('You are not allowed to reverse transactions.'));
        }
        if (trim($reason) === '') {
            throw new AcctException(__('Give a reason for the reversal.'));
        }

        return ReferenceLock::run('acct_txn', fn () => $this->tx(function () use ($tr, $by, $reason) {
            $t = AcctTransfer::query()->lockForUpdate()->findOrFail($tr->id);
            if ($t->status === 'reversed') {
                return $t;
            }
            if ($t->status !== 'posted') {
                throw new AcctException(__('Only a posted transfer can be reversed.'));
            }
            $legs = AcctTransaction::query()->where('transfer_id', $t->id)->where('status', 'posted')->orderBy('id')->lockForUpdate()->get();
            // lock the accounts in id order before touching them
            AcctAccount::query()->whereIn('id', $legs->pluck('account_id')->unique()->sort()->values())->orderBy('id')->lockForUpdate()->get();
            foreach ($legs as $leg) {
                $this->reverseOne($leg, $by, $reason);
            }
            $t->fill(['status' => 'reversed'])->save();
            $this->trail('transfer', $t->id, 'reversed', $by, $reason, false);
            AcctAudit::log('transfer.reversed', 'transfer', $t->id, $t->reference, ['status' => 'posted'], ['status' => 'reversed'], $reason, $by);

            return $t;
        }));
    }

    // ------------------------------------------------------------------ shared pieces

    /** Does this person hold the ability for this kind of entry? (Controllers check too; the engine never relies on them alone.) */
    private function guardAbility(User $by, string $direction, string $kind): void
    {
        $need = $kind === 'adjustment' ? 'create-acct-adjustments' : ($direction === 'in' ? 'create-acct-inflows' : 'create-acct-outflows');
        if (! $by->can($need)) {
            throw new AcctException(__('You are not allowed to create this kind of transaction.'));
        }
    }

    /** The account must exist, be open, and be one this person may use. */
    private function guardUsable(AcctAccount $a, User $by, bool $strictUser = true): void
    {
        if ($a->status !== 'active') {
            throw new AcctException(__('The account :a is :s, so it cannot be used.', ['a' => $a->name, 's' => __($a->status)]));
        }
        $allowed = (array) $a->allowed_user_ids;
        if ($strictUser && $allowed && ! in_array($by->id, array_map('intval', $allowed), true) && ! $by->can('view-all-acct-accounts')) {
            throw new AcctException(__('You are not authorised to use the account :a.', ['a' => $a->name]));
        }
    }

    private function guardDate(string $date): void
    {
        $closed = AcctSettings::get()['closed_through'];
        // compare real dates, never text: "31 Jan 2026" or "2026-01-31 10:00:00" must not slip past a close on 2026-01-31
        $date = \Carbon\Carbon::parse($date)->toDateString();
        if ($closed && $date <= \Carbon\Carbon::parse($closed)->toDateString()) {
            throw new AcctException(__('That date is in a closed period (up to :d). Choose a later date.', ['d' => $closed]));
        }
    }

    private function guardCategory($categoryId, string $direction): void
    {
        if (empty($categoryId)) {
            return;
        }
        $cat = AcctCategory::query()->where('is_active', true)->find($categoryId) ?? throw new AcctException(__('Choose an active category.'));
        if ($cat->direction !== 'both' && $cat->direction !== $direction) {
            throw new AcctException(__('That category is not for money :d.', ['d' => $direction === 'in' ? __('in') : __('out')]));
        }
    }

    /** The "one transaction" limit of an account, applied when a transaction is created and again whenever its amount is edited. */
    private function guardLimit(AcctAccount $acct, string $amount): void
    {
        if ($acct->txn_limit !== null && Money::cmp($amount, $acct->txn_limit) > 0) {
            throw new AcctException(__('This amount is above the limit for one transaction on this account (:l :c).', ['l' => Money::format($acct->txn_limit, AcctRates::precision($acct->currency)), 'c' => $acct->currency]));
        }
    }

    /** Under the "value date" accounting rule the value date is the day a balance sees the entry, so a closed period is closed to it as well. */
    private function guardValueDate($valueDate): void
    {
        if (! empty($valueDate) && AcctSettings::get()['date_rule'] === 'value_date') {
            $this->guardDate((string) $valueDate);
        }
    }

    private function amount($v, string $currency, bool $allowZero = false): string
    {
        // digits beyond the ledger's four decimals are refused, never silently cut off (100.00001 must not become 100)
        if (preg_match('/^\s*-?[\d,]*\.\d{4}(\d+)\s*$/', (string) $v, $m) && trim($m[1], '0') !== '') {
            throw new AcctException(__(':c amounts have at most :p decimal places.', ['c' => $currency, 'p' => AcctRates::precision($currency)]));
        }
        try {
            $n = Money::norm($v);
        } catch (\InvalidArgumentException) {
            throw new AcctException(__('Enter a valid amount.'));
        }
        if (Money::cmp($n, Money::MAX_ENTRY) > 0) {
            throw new AcctException(__('That amount is too large.'));
        }
        if (! $allowZero && ! Money::isPositive($n)) {
            throw new AcctException(__('The amount must be greater than zero.'));
        }
        if ($allowZero && Money::cmp($n, '0') < 0) {
            throw new AcctException(__('The amount cannot be negative.'));
        }
        $p = AcctRates::precision($currency);
        if (! Money::fitsPrecision($n, $p)) {
            throw new AcctException(__(':c amounts have at most :p decimal places.', ['c' => $currency, 'p' => $p]));
        }

        return $n;
    }

    /** Payment-method specific fields: a cheque needs its number and date, a bank transfer its reference. */
    private function guardMethod(array $f): void
    {
        $m = $f['payment_method'] ?? null;
        $d = (array) ($f['method_details'] ?? []);
        if ($m === 'cheque' && (empty($d['cheque_number']) || empty($d['cheque_date']))) {
            throw new AcctException(__('A cheque needs its cheque number and cheque date.'));
        }
        if ($m === 'bank_transfer' && empty($d['bank_reference']) && empty($f['external_ref'])) {
            throw new AcctException(__('A bank transfer needs its bank reference.'));
        }
        if ($m === 'mobile_money' && empty($d['mobile_reference']) && empty($f['external_ref'])) {
            throw new AcctException(__('Mobile money needs its transaction reference.'));
        }
    }

    private function needsAttachment(AcctTransaction $t): void
    {
        $over = AcctSettings::amountFor('attachment_required_over', $t->currency);
        if ($t->direction === 'out' && $over !== null && Money::cmp($t->amount, $over) > 0
            && ! AcctAttachment::query()->where('subject_type', 'transaction')->where('subject_id', $t->id)->exists()) {
            throw new AcctException(__('A payment above :a :c needs a supporting document (receipt, voucher or invoice). Attach one first.', ['a' => Money::format($over, AcctRates::precision($t->currency)), 'c' => $t->currency]));
        }
    }

    /** The strictest matching rule decides how many approvals are needed. Thresholds are compared in the rule's own currency only. */
    public function requiredApprovals(string $appliesTo, string $currency, string $amount, ?int $accountId = null): int
    {
        $rules = AcctApprovalRule::query()->where('is_active', true)->where('applies_to', $appliesTo)
            ->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $accountId))->get()
            ->filter(fn ($r) => ($r->currency === null || $r->currency === $currency)
                && ($r->currency === null || (Money::cmp($amount, $r->min_amount) >= 0 && ($r->max_amount === null || Money::cmp($amount, $r->max_amount) <= 0))));

        // Approval is mandatory for every money in, money out, transfer and adjustment: the rules can ask for more than one, never for none.
        return max(1, (int) ($rules->max('approvals_required') ?? 1));
    }

    private function approveLocked($t, string $type, User $by, ?string $comment): void
    {
        if ($t->status === 'approved') {
            return; // already approved: nothing to add
        }
        if ($t->status !== 'submitted') {
            throw new AcctException(__('This record is not waiting for approval.'));
        }
        if (! $by->can(AcctPermissions::need('approve', $t))) {
            throw new AcctException(__('You are not allowed to approve this kind of record.'));
        }
        $settings = AcctSettings::get();
        $self = in_array((int) $by->id, [(int) $t->created_by, (int) $t->submitted_by], true);
        if ($self && $settings['maker_checker'] && ! ($by->hasRole('Super Admin') && $settings['super_admin_self_approval'])) {
            throw new AcctException(__('You cannot approve what you created or submitted. Another person must approve it.'));
        }
        // approvals given before the last (re)submission belong to an earlier round and neither block nor count
        $since = (int) AcctApproval::query()->where('subject_type', $type)->where('subject_id', $t->id)->where('action', 'submitted')->max('id');
        if (AcctApproval::query()->where('subject_type', $type)->where('subject_id', $t->id)->where('action', 'approved')->where('id', '>', $since)->where('user_id', $by->id)->exists()) {
            throw new AcctException(__('You have already approved this. A different person must give the next approval.'));
        }
        $this->trail($type, $t->id, 'approved', $by, $comment, true);
        $count = (int) $t->approvals_count + 1;
        $t->fill(['approvals_count' => $count, 'status' => $count >= (int) $t->required_approvals ? 'approved' : 'submitted'])->save();
        AcctAudit::log($type . '.approved', $type, $t->id, $t->reference, null, ['approvals' => $count . '/' . $t->required_approvals, 'status' => $t->status, 'self' => $self], $comment, $by);
    }

    /** A person without the approval ability is refused BEFORE any transaction starts, so the refusal itself is kept in the audit trail and raised as a security alert. */
    private function denyUnlessCanApprove($t, string $type, User $by): void
    {
        if ($by->can(AcctPermissions::need('approve', $t))) {
            return;
        }
        AcctAudit::log($type . '.approval_denied', $type, $t->id, $t->reference, null, null, __('The person lacks the approval ability.'), $by);
        AcctAlerts::raise('security', 'unauthorized_approval', 'high', __('Approval attempted without permission'), __(':u tried to approve :r.', ['u' => $by->name, 'r' => $t->reference]), ['subject_type' => $type, 'subject_id' => $t->id, 'dedupe' => 'unauth-' . $by->id . '-' . $t->id, 'notify_event' => 'security_alert']);
        throw new AcctException(__('You are not allowed to approve.'));
    }

    private function rejectLocked($t, string $type, User $by, string $reason): void
    {
        if (! in_array($t->status, ['submitted', 'approved'], true)) {
            throw new AcctException(__('This record is not waiting for a decision.'));
        }
        if (! $by->can(AcctPermissions::need('approve', $t))) {
            throw new AcctException(__('You are not allowed to reject this kind of record.'));
        }
        if (trim($reason) === '') {
            throw new AcctException(__('Give a reason for rejecting.'));
        }
        $t->fill(['status' => 'rejected', 'reject_reason' => $reason, 'approvals_count' => 0])->save();
        $this->trail($type, $t->id, 'rejected', $by, $reason, false);
        AcctAudit::log($type . '.rejected', $type, $t->id, $t->reference, null, ['status' => 'rejected'], $reason, $by);
    }

    /** Move a balance (the account row is already locked by the caller). Refuses a negative result unless the account allows it. */
    private function applyToAccount(AcctAccount $a, string $direction, string $amount, string $currency, string $date): void
    {
        if ($a->status !== 'active') {
            throw new AcctException(__('The account :a is :s, so nothing can be posted to it.', ['a' => $a->name, 's' => __($a->status)]));
        }
        if ($a->currency !== $currency) {
            throw new AcctException(__('The currency does not match the account :a (:c).', ['a' => $a->name, 'c' => $a->currency]));
        }
        $this->guardDate($date);
        $new = $direction === 'in' ? Money::add($a->balance, $amount) : Money::sub($a->balance, $amount);
        if (Money::cmp(ltrim($new, '-'), Money::MAX_BALANCE) > 0) {
            throw new AcctException(__('The balance of :a cannot hold that much.', ['a' => $a->name]));
        }
        if (Money::cmp($new, '0') < 0 && ! $a->allow_negative) {
            throw new AcctException(__('This would take :a below zero (it holds :b :c). Negative balances are not allowed on this account.', ['a' => $a->name, 'b' => Money::format($a->balance, AcctRates::precision($a->currency)), 'c' => $a->currency]));
        }
        $a->forceFill(['balance' => $new, 'balance_updated_at' => now()])->save();
    }

    /** The linked opposite entry of a posted transaction (caller holds the locks). */
    private function reverseOne(AcctTransaction $t, User $by, string $reason): AcctTransaction
    {
        $acct = AcctAccount::query()->lockForUpdate()->findOrFail($t->account_id);
        $dir = $t->direction === 'in' ? 'out' : 'in';
        $this->applyToAccount($acct, $dir, (string) $t->amount, $t->currency, now()->toDateString());
        $revCat = AcctCategory::query()->where('name', 'Reversal')->value('id');
        $rev = AcctTransaction::query()->create([
            'reference' => AcctNumbers::next('acct_transactions', 'ACT'), 'account_id' => $t->account_id, 'direction' => $dir, 'kind' => 'reversal', 'currency' => $t->currency, 'amount' => $t->amount,
            'txn_date' => now()->toDateString(), 'category_id' => $revCat, 'description' => __('Reversal of :r', ['r' => $t->reference]), 'reason' => $reason, 'reverses_id' => $t->id, 'transfer_id' => $t->transfer_id,
            'status' => 'posted', 'created_by' => $by->id, 'posted_by' => $by->id, 'posted_at' => now(), 'required_approvals' => 0, 'warehouse_id' => $t->warehouse_id, 'department' => $t->department,
            'fund_allocation_id' => $t->fund_allocation_id,
        ]);
        $this->releaseSource($t);
        $t->fill(['status' => 'reversed', 'reversed_by_id' => $rev->id])->save();
        $this->trail('transaction', $t->id, 'reversed', $by, $reason, false);

        return $rev;
    }

    /**
     * A cancelled or reversed payment lets go of the record it was made from (a Funds Allocation line), so that source can be recorded again:
     * the source key is unique, and a dead payment must not hold it for ever. The Funds line link stays for the history. Returns what was released.
     */
    private function releaseSource(AcctTransaction $t): array
    {
        if ($t->source_module !== 'fund_allocation') {
            return [];
        }
        $was = ['released_source' => $t->source_module . '/' . $t->source_type . '/' . $t->source_id];
        // the request key goes with it, otherwise recording the line again would find the dead payment by its key and hand it back
        $t->forceFill(['source_module' => null, 'source_type' => null, 'source_id' => null, 'idempotency_key' => null]);

        return $was;
    }

    private function reportingValue(AcctTransaction $t): void
    {
        $r = $this->reportingFor((string) $t->amount, $t->currency, $t->txn_date->toDateString());
        if ($r) {
            $t->forceFill(['reporting_amount' => $r['amount'], 'reporting_currency' => $r['currency'], 'rate_id' => $t->rate_id ?: $r['rate']['id'], 'exchange_rate' => $t->exchange_rate ?: ($t->currency === $r['currency'] ? null : $r['rate']['rate'])])->save();
        }
    }

    /**
     * An amount in the reporting currency (null when no approved rate exists). A figure that does not fit the ledger columns once converted
     * (a very large amount in a strong currency) is refused with a message, never left to fail inside the database.
     */
    private function reportingFor(string $amount, string $currency, string $date): ?array
    {
        $r = AcctRates::toReporting($amount, $currency, $date);
        if ($r && Money::cmp(ltrim((string) $r['amount'], '-'), Money::MAX_BALANCE) > 0) {
            throw new AcctException(__('That amount is too large once it is converted to the reporting currency (:c).', ['c' => $r['currency']]));
        }

        return $r;
    }

    private function trail(string $type, int $id, string $action, User $by, ?string $comment, bool $unique): void
    {
        AcctApproval::query()->create(['subject_type' => $type, 'subject_id' => $id, 'action' => $action, 'user_id' => $by->id, 'role_context' => $by->getRoleNames()->implode(', '), 'comment' => $comment, 'created_at' => now()]);
    }

    private function afterSubmit(AcctTransaction $t, AcctAccount $acct): void
    {
        $large = AcctSettings::amountFor('large_txn', $t->currency);
        if ($large !== null && Money::cmp($t->amount, $large) >= 0) {
            AcctAlerts::raise('transaction', 'large_transaction', 'medium', __('Large :d of :a :c', ['d' => $t->direction === 'in' ? __('receipt') : __('payment'), 'a' => Money::format($t->amount, AcctRates::precision($t->currency)), 'c' => $t->currency]), __(':r on :acc is above the large-transaction limit.', ['r' => $t->reference, 'acc' => $acct->name]), ['account_id' => $acct->id, 'subject_type' => 'transaction', 'subject_id' => $t->id, 'amount' => $t->amount, 'currency' => $t->currency, 'dedupe' => 'large-' . $t->id, 'notify_event' => 'large_transaction']);
        }
        if ($t->status === 'submitted') {
            AcctAlerts::raise('transaction', 'approval_needed', 'low', __('Transaction :r is waiting for approval', ['r' => $t->reference]), __(':a :c on :acc needs :n approval(s).', ['a' => Money::format($t->amount, AcctRates::precision($t->currency)), 'c' => $t->currency, 'acc' => $acct->name, 'n' => $t->required_approvals]), ['account_id' => $acct->id, 'subject_type' => 'transaction', 'subject_id' => $t->id, 'amount' => $t->amount, 'currency' => $t->currency, 'dedupe' => 'approval-' . $t->id, 'notify_event' => 'approval_needed', 'approver_permission' => AcctPermissions::need('approve', $t)]);
        }
        if ($t->direction === 'out' && $t->kind === 'payment') {
            $dup = AcctTransaction::query()->where('id', '<>', $t->id)->where('account_id', $t->account_id)->where('amount', $t->amount)->where('txn_date', $t->txn_date->toDateString())
                ->whereIn('status', ['submitted', 'approved', 'posted'])->where(fn ($q) => $q->whereNotNull('external_ref')->where('external_ref', $t->external_ref)->orWhere('description', $t->description))->exists();
            if ($dup) {
                AcctAlerts::raise('transaction', 'possible_duplicate', 'medium', __('Possible duplicate: :r', ['r' => $t->reference]), __('Another transaction with the same account, amount, date and reference or description exists.'), ['account_id' => $acct->id, 'subject_type' => 'transaction', 'subject_id' => $t->id, 'dedupe' => 'dup-' . $t->id]);
            }
        }
    }

    private function afterPost(AcctTransaction $t, AcctAccount $acct): void
    {
        AcctAlerts::scanAccount($acct);
        AcctAlerts::unusual($t, $acct);
    }

    private function failed(AcctTransaction $t, User $by, string $why): void
    {
        AcctAudit::log('transaction.post_failed', 'transaction', $t->id, $t->reference, null, null, $why, $by);
        $n = \App\Models\Accounts\AcctAuditEvent::query()->where('event', 'transaction.post_failed')->where('entity_id', $t->id)->where('created_at', '>=', now()->subHour())->count();
        if ($n >= 5) {
            AcctAlerts::raise('security', 'excessive_failed_posting', 'high', __('Repeated failed posting of :r', ['r' => $t->reference]), __(':n failed attempts in the last hour.', ['n' => $n]), ['subject_type' => 'transaction', 'subject_id' => $t->id, 'dedupe' => 'failpost-' . $t->id]);
        }
    }

    /** A compact copy of a record for the audit trail. */
    private function snap($m): array
    {
        return array_intersect_key($m->getAttributes(), array_flip(['reference', 'account_id', 'direction', 'kind', 'currency', 'amount', 'txn_date', 'status', 'category_id', 'description', 'from_account_id', 'to_account_id', 'from_amount', 'to_amount', 'exchange_rate', 'fee_amount']));
    }
}
