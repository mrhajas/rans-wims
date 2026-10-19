<?php

namespace App\Services\Subcontracts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Opening balances of the subcontractor accounts.
 *
 * An account's opening balance is two figures as at a date: the contract value (revised: original plus approved variations) and what has been paid to date.
 * The ledger already reads them without any change to its formulas:
 *   original_amount (+ approved variations)  = the contract,
 *   ONE counted historical payment of kind "opening_paid" = the paid to date,
 *   outstanding = contract - paid (live vouchers paid later are added to paid).
 * save() sets the two figures of one account; reset() takes every account's LAST amounts (the ledger's own revised and paid total), removes the payments
 * and the history (requests, variations, vouchers links, imported payment lines, reconciliations) and leaves exactly those two figures, then proves that every
 * account has the same contract, paid and outstanding as before (or undoes everything). The agreements themselves, their work schedules and the imported
 * workbook records are left as they are. A backup of what is removed is written first.
 */
class SubcontractOpening
{
    public const KIND = 'opening_paid';

    public const MAX = '9999999999999.99';

    /** One account's opening figures and where they stand now. */
    public static function state(int $agreementId): array
    {
        $a = DB::table('subcontract_agreements')->where('id', $agreementId)->first();
        $f = SubcontractLedger::forAgreement($agreementId);
        $rows = DB::table('subcontract_historical_payments')->where('agreement_id', $agreementId)->whereNull('deleted_at');
        $opening = (clone $rows)->where('counted', 1)->selectRaw('COUNT(*) n, COALESCE(SUM(amount),0) s, MAX(payment_date) d')->first();

        return [
            'contract' => $f['revised'] ?? '0.00', 'original' => $f['original'] ?? '0.00', 'variations_net' => $f['variations_net'] ?? '0.00',
            'opening_paid' => SubcontractAmounts::money($opening->s ?? 0), 'opening_rows' => (int) ($opening->n ?? 0),
            'dated_rows' => (int) (clone $rows)->where('counted', 0)->count(), 'paid_live' => $f['paid_live'] ?? '0.00', 'paid_total' => $f['paid_total'] ?? '0.00',
            'outstanding' => $f['commitment_left'] ?? '0.00', 'as_at' => $a->as_at_date ?? ($opening->d ?? null), 'currency' => $a->currency ?? 'TZS',
        ];
    }

    /** @return array{contract:string,paid:string} validated and rounded */
    public static function figures($contract, $paid): array
    {
        $out = [];
        foreach (['contract' => $contract, 'paid' => $paid] as $k => $v) {
            $raw = trim(str_replace([',', ' '], '', (string) $v));
            if ($raw === '' || ! preg_match('/^\d+(\.\d{1,6})?$/', $raw)) {
                throw ValidationException::withMessages([$k => [$k === 'contract' ? __('Type the contract value as a plain amount (digits and an optional decimal point).') : __('Type what was paid to date as a plain amount (digits and an optional decimal point). Use 0 if nothing was paid.')]]);
            }
            $m = SubcontractAmounts::money($raw);
            if (bccomp($m, self::MAX, 2) > 0) {
                throw ValidationException::withMessages([$k => [__('That amount is too large.')]]);
            }
            $out[$k] = $m;
        }

        return $out;
    }

    /** Sets the opening contract and paid-to-date of ONE account, as at a date. Everything it replaces is written to the activity log. */
    public static function save(int $agreementId, $contract, $paid, ?string $asAt, ?string $note, User $user): array
    {
        $fig = self::figures($contract, $paid);
        if ($asAt !== null && $asAt !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asAt)) {
            throw ValidationException::withMessages(['as_at' => [__('Choose a valid date.')]]);
        }
        $asAt = $asAt ?: now()->toDateString();

        return DB::transaction(function () use ($agreementId, $fig, $asAt, $note, $user) {
            $a = DB::table('subcontract_agreements')->where('id', $agreementId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($a, 404);
            $before = self::state($agreementId);
            $net = $before['variations_net'];
            $original = bcsub($fig['contract'], $net, 2);
            if (bccomp($original, '0', 2) < 0) {
                throw ValidationException::withMessages(['contract' => [__('The contract value is below the approved variations of this account (:v). Raise it or review the variations.', ['v' => SubcontractAmounts::group($net)])]]);
            }
            $replaced = DB::table('subcontract_historical_payments')->where('agreement_id', $agreementId)->where('kind', self::KIND)->get(['id', 'amount', 'amount_tzs', 'payment_date']);
            DB::table('subcontract_historical_payments')->where('agreement_id', $agreementId)->where('kind', self::KIND)->delete();
            // a foreign currency account: the TZS equivalent follows the new amount at the rate the old line implied (never the old TZS figure under a new amount); no old rate, no equivalent
            $oldAmount = (string) $replaced->sum('amount');
            $tzs = null;
            if ($a->currency === 'TZS') {
                $tzs = $fig['paid'];
            } elseif ($replaced->sum('amount_tzs') > 0 && is_numeric($oldAmount) && bccomp($oldAmount, '0', 2) > 0) {
                $tzs = SubcontractAmounts::money(bcdiv(bcmul((string) $replaced->sum('amount_tzs'), $fig['paid'], 6), $oldAmount, 6));
            }
            DB::table('subcontract_historical_payments')->insert([
                'agreement_id' => $agreementId, 'kind' => self::KIND, 'counted' => 1, 'payment_date' => $asAt, 'amount' => $fig['paid'], 'currency' => $a->currency,
                'amount_tzs' => $tzs, 'note' => $note ? mb_substr($note, 0, 500) : __('Opening balance'), 'imported_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('subcontract_agreements')->where('id', $agreementId)->update(['original_amount' => $original, 'as_at_date' => $asAt, 'updated_at' => now()]);
            $after = self::state($agreementId);
            log_activity('Subcontract opening balance set', [
                'agreement' => $a->reference, 'as_at' => $asAt, 'before' => ['contract' => $before['contract'], 'paid' => $before['opening_paid'], 'lines' => $before['opening_rows']],
                'after' => ['contract' => $after['contract'], 'paid' => $after['opening_paid'], 'outstanding' => $after['outstanding']], 'note' => $note,
            ], \App\Models\SubcontractAgreement::withoutGlobalScopes()->find($agreementId), 'Subcontracts');

            return $after;
        });
    }

    /** What a reset would do, changing nothing: the figures that must stay, the rows that go, and anything that blocks it. */
    public static function plan(): array
    {
        $ids = DB::table('subcontract_agreements')->whereNull('deleted_at')->pluck('id')->all();
        $fig = SubcontractLedger::forAgreements($ids);
        $tables = ['subcontract_historical_payments' => 'Imported payment lines', 'subcontract_payment_requests' => 'Payment requests', 'subcontract_request_items' => 'Request lines', 'subcontract_payments' => 'Voucher payments', 'subcontract_payment_allocations' => 'Payment allocations', 'subcontract_variations' => 'Variations', 'subcontract_reconciliations' => 'Reconciliations'];
        if (\App\Support\SchemaCache::hasTable('subcontract_claims')) {
            $tables += ['subcontract_claims' => 'Contract payment requests', 'subcontract_claim_lines' => 'Contract payment request lines'];
        }
        $counts = [];
        foreach ($tables as $t => $label) {
            $counts[$t] = ['label' => $label, 'rows' => (int) DB::table($t)->count()];
        }
        $counts['variation_items'] = ['label' => 'Work items added by variations', 'rows' => (int) DB::table('subcontract_work_items')->whereNotNull('variation_id')->count()];
        $by = [];
        $blockers = [];
        foreach ($fig as $id => $f) {
            $c = $f['currency'];
            foreach (['revised' => 'contract', 'paid_total' => 'paid', 'commitment_left' => 'outstanding'] as $k => $label) {
                $by[$c][$label] = bcadd($by[$c][$label] ?? '0.00', $f[$k], 2);
            }
            if (bccomp($f['approved_unpaid'], '0', 2) > 0 || bccomp($f['in_process'], '0', 2) > 0 || bccomp($f['claims_pending'], '0', 2) > 0) {
                $blockers[] = ['agreement_id' => $id, 'approved_unpaid' => $f['approved_unpaid'], 'in_process' => $f['in_process'], 'claims_pending' => $f['claims_pending'], 'currency' => $c];
            }
        }
        $kept = ['agreements' => count($ids), 'work_items' => (int) DB::table('subcontract_work_items')->whereNull('variation_id')->count(), 'work_groups' => (int) DB::table('subcontract_work_groups')->count(), 'import_batches' => (int) DB::table('subcontract_import_batches')->count()];

        return ['accounts' => count($ids), 'by_currency' => $by, 'counts' => $counts, 'kept' => $kept, 'blockers' => $blockers, 'figures' => $fig];
    }

    /**
     * Takes every account's last amounts as its opening balance (contract = revised, paid = paid in total), removes payments and history, and proves the
     * result. $backup = false only in tests. Throws (and changes nothing) when money is in motion (approved but unpaid, or in a voucher) unless $force.
     */
    public static function reset(User $user, string $asAt, bool $force = false, bool $backup = true): array
    {
        $plan = self::plan();
        if ($plan['blockers'] && ! $force) {
            throw ValidationException::withMessages(['reset' => [__(':n account(s) still have an approved request that is not paid, or a payment voucher in process. Finish or cancel those first.', ['n' => count($plan['blockers'])])]]);
        }
        $before = $plan['figures'];
        $dir = null;
        if ($backup) {
            $dir = 'subcontract-resets/' . now()->format('Ymd-His');
            self::backupTables($dir);
        }
        $summary = ['accounts' => count($before), 'removed' => []];
        DB::transaction(function () use ($before, $asAt, $user, &$summary) {
            foreach ($before as $id => $f) {
                $oldTzs = DB::table('subcontract_historical_payments')->where('agreement_id', $id)->where('counted', 1)->whereNull('deleted_at')->sum('amount_tzs');
                DB::table('subcontract_agreements')->where('id', $id)->update(['original_amount' => $f['revised'], 'as_at_date' => $asAt, 'updated_at' => now()]);
                DB::table('subcontract_historical_payments')->where('agreement_id', $id)->delete();
                DB::table('subcontract_historical_payments')->insert([
                    'agreement_id' => $id, 'kind' => self::KIND, 'counted' => 1, 'payment_date' => $asAt, 'amount' => $f['paid_total'], 'currency' => $f['currency'],
                    'amount_tzs' => $f['currency'] === 'TZS' ? $f['paid_total'] : ($oldTzs > 0 ? SubcontractAmounts::money($oldTzs) : null), 'note' => __('Opening balance (all payments and history up to :d)', ['d' => $asAt]),
                    'imported_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (['subcontract_payment_allocations', 'subcontract_request_items'] as $t) {
                $summary['removed'][$t] = DB::table($t)->delete();
            }
            DB::table('payment_vouchers')->whereNotNull('subcontract_payment_id')->update(['subcontract_payment_id' => null]);
            if (\App\Support\SchemaCache::hasTable('subcontract_claims')) {
                // contract payment requests point at the payment requests and payments removed below: they go with them (the backup keeps them)
                DB::table('payment_vouchers')->whereNotNull('subcontract_claim_id')->update(['subcontract_claim_id' => null]);
                foreach (['subcontract_claim_lines', 'subcontract_claims'] as $t) {
                    $summary['removed'][$t] = DB::table($t)->delete();
                }
            }
            foreach (['subcontract_payments', 'subcontract_payment_requests'] as $t) {
                $summary['removed'][$t] = DB::table($t)->delete();
            }
            $summary['removed']['variation_items'] = DB::table('subcontract_work_items')->whereNotNull('variation_id')->delete();
            $summary['removed']['subcontract_variations'] = DB::table('subcontract_variations')->delete();
            $summary['removed']['subcontract_reconciliations'] = DB::table('subcontract_reconciliations')->delete();

            // the proof: every account has the same contract, paid and outstanding as before, to the cent
            $after = SubcontractLedger::forAgreements(array_keys($before));
            foreach ($before as $id => $f) {
                foreach (['revised', 'paid_total', 'commitment_left'] as $k) {
                    if (($after[$id][$k] ?? null) !== $f[$k]) {
                        throw new \RuntimeException("Opening balance check failed for account #{$id}: {$k} was {$f[$k]} and would be " . ($after[$id][$k] ?? 'missing') . '. Nothing was changed.');
                    }
                }
            }
        }, 2);
        log_activity('Subcontract accounts reset to opening balances', ['as_at' => $asAt, 'accounts' => $summary['accounts'], 'removed' => $summary['removed'], 'backup' => $dir, 'by' => $user->name], null, 'Subcontracts');
        $summary['backup'] = $dir;

        return $summary;
    }

    /** One JSON file per table in storage/app/subcontract-resets/<stamp>/ (what the reset removes, plus the agreements' amounts), so nothing is lost for good. */
    private static function backupTables(string $dir): void
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory($dir);
        foreach (['subcontract_historical_payments', 'subcontract_payment_requests', 'subcontract_request_items', 'subcontract_payments', 'subcontract_payment_allocations', 'subcontract_variations', 'subcontract_reconciliations', 'subcontract_claims', 'subcontract_claim_lines'] as $t) {
            if (! \App\Support\SchemaCache::hasTable($t)) {
                continue;
            }
            $disk->put("$dir/$t.json", json_encode(DB::table($t)->get(), JSON_UNESCAPED_UNICODE));
        }
        $disk->put("$dir/variation_work_items.json", json_encode(DB::table('subcontract_work_items')->whereNotNull('variation_id')->get(), JSON_UNESCAPED_UNICODE));
        $disk->put("$dir/agreements.json", json_encode(DB::table('subcontract_agreements')->get(['id', 'reference', 'original_amount', 'as_at_date', 'currency', 'status']), JSON_UNESCAPED_UNICODE));
        $disk->put("$dir/voucher_links.json", json_encode(DB::table('payment_vouchers')->whereNotNull('subcontract_payment_id')->get(['id', 'subcontract_payment_id']), JSON_UNESCAPED_UNICODE));
        if (\App\Support\SchemaCache::hasColumn('payment_vouchers', 'subcontract_claim_id')) {
            $disk->put("$dir/voucher_claim_links.json", json_encode(DB::table('payment_vouchers')->whereNotNull('subcontract_claim_id')->get(['id', 'subcontract_claim_id']), JSON_UNESCAPED_UNICODE));
        }
    }
}
