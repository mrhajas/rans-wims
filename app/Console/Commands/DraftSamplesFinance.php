<?php

namespace App\Console\Commands;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctReconciliation;
use App\Models\Accounts\AcctTransaction;
use App\Models\Accounts\AcctTransfer;
use App\Models\LabourList;
use App\Models\LabourListItem;
use App\Models\ProjectCertificate;
use App\Models\ProjectCertificateContract;
use App\Models\ProjectCertificateContractAddendum;
use App\Models\ProjectCertificateLine;
use App\Models\SalaryPeriod;
use App\Models\SalaryRecord;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounts\AcctBalance;
use App\Services\Accounts\AcctNumbers;
use App\Support\ReferenceLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Draft SAMPLE records for the finance side, so the owner can try "remove the draft status and approve": two drafts of each of Salary Period (one with a
 * salary record, one with none: approving the empty one is refused), Labour List, Accounts transaction, Accounts transfer, Accounts reconciliation,
 * Project Certificate and Contract Addendum. Written straight to the tables, so no notification, SMS or email is fired. Every sample says SAMPLE in its
 * label, reference, description or notes; --fresh removes only those. Running it again adds nothing that already exists.
 *
 * Accounts samples are made by another real, active user (not Super Admin 1): with the maker-checker rule on, the approver cannot also be the maker.
 */
class DraftSamplesFinance extends Command
{
    protected $signature = 'draft-samples:finance {--fresh : remove the previous samples first}';

    protected $description = 'Create SAMPLE drafts (salary periods, labour lists, accounts, certificates, addendums) for trying "remove draft and approve"';

    private int $made = 0;

    private int $kept = 0;

    public function handle(): int
    {
        $admin = User::find(1);
        if (! $admin) {
            $this->error('User 1 (the Super Admin) was not found.');

            return self::FAILURE;
        }
        auth()->login($admin);

        if ($this->option('fresh')) {
            $this->removeSamples();
        }

        $maker = User::query()->where('id', '!=', 1)->where('is_active', 1)->whereNull('disabled_at')->orderBy('id')->first();

        $this->salary($admin);
        $this->labour($admin);
        $this->certificates($admin);
        if ($maker) {
            $this->accounts($maker);
        } else {
            $this->warn('No other active user was found, so the Accounts samples were skipped (they need a maker different from the approver).');
        }

        $this->info("Done: {$this->made} sample(s) created, {$this->kept} already there.");

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------------------------------------ Salary

    private function salary(User $admin): void
    {
        $specs = [
            ['SAL-BATCH-SAMPLE-0001', 'SAMPLE salary: with a salary record (can be approved)', true],
            ['SAL-BATCH-SAMPLE-0002', 'SAMPLE salary: no salary records (approval is refused)', false],
        ];
        $staffId = DB::table('staffs')->whereNull('deleted_at')->orderBy('id')->value('id');
        foreach ($specs as [$ref, $label, $withRecord]) {
            if (DB::table('salary_periods')->where('batch_reference', $ref)->exists()) {
                $this->kept++;

                continue;
            }
            $period = SalaryPeriod::forceCreate([
                'label' => $label, 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(), 'batch_reference' => $ref,
                'status' => SalaryPeriod::STATUS_DRAFT, 'created_by_user_id' => $admin->id, 'notes' => 'SAMPLE record to try "remove draft and approve". Safe to delete.',
            ]);
            if ($withRecord && $staffId) {
                SalaryRecord::forceCreate(['salary_period_id' => $period->id, 'staff_id' => $staffId]);
            }
            $this->made++;
            $this->line2($ref);
        }
    }

    // ------------------------------------------------------------------------------------------------ Labour

    private function labour(User $admin): void
    {
        $warehouseId = Warehouse::withoutGlobalScopes()->whereNull('deleted_at')->orderBy('id')->value('id');
        foreach ([['LAB-SAMPLE-0001', 'Site clearing, 3 labourers', 3, 25000], ['LAB-SAMPLE-0002', 'Block laying, 2 masons', 2, 40000]] as [$ref, $text, $people, $rate]) {
            if (DB::table('labour_lists')->where('reference', $ref)->exists()) {
                $this->kept++;

                continue;
            }
            $list = LabourList::forceCreate([
                'reference' => $ref, 'warehouse_id' => $warehouseId, 'period_start' => now()->startOfWeek()->toDateString(), 'period_end' => now()->endOfWeek()->toDateString(),
                'status' => LabourList::STATUS_DRAFT, 'description' => 'SAMPLE labour list: ' . $text, 'total_amount' => $people * $rate * 5, 'created_by_user_id' => $admin->id,
            ]);
            for ($i = 1; $i <= $people; $i++) {
                LabourListItem::forceCreate(['labour_list_id' => $list->id, 'payee_name' => 'SAMPLE labourer ' . $i, 'days' => 5, 'rate_type' => 'day', 'rate' => $rate, 'amount' => $rate * 5, 'work_description' => 'SAMPLE: ' . $text]);
            }
            $this->made++;
            $this->line2($ref);
        }
    }

    // ------------------------------------------------------------------------------------------------ Project certificates and addendums

    private function certificates(User $admin): void
    {
        $mainIds = Warehouse::withoutGlobalScopes()->mainWarehouses()->pluck('id');
        $warehouse = Warehouse::withoutGlobalScopes()->whereNull('deleted_at')->whereNotIn('id', $mainIds)->orderBy('id')->first();
        if (! $warehouse) {
            $this->warn('No project warehouse was found, so the certificate and addendum samples were skipped.');

            return;
        }
        $contract = ProjectCertificateContract::query()->where('warehouse_id', $warehouse->id)->first();
        if (! $contract) {
            $contract = ProjectCertificateContract::forceCreate([
                'warehouse_id' => $warehouse->id, 'contractor_name' => 'SAMPLE Contractor Ltd', 'contract_reference' => 'SAMPLE-CONTRACT-01', 'currency' => 'TZS',
                'original_contract_sum' => 100000000, 'revised_contract_sum' => 100000000, 'created_by' => $admin->id,
            ]);
        } elseif ($contract->contract_reference !== 'SAMPLE-CONTRACT-01') {
            $this->warn("{$warehouse->name} already has a real contract, so no samples were attached to it.");

            return;
        }

        foreach ([1, 2] as $n) {
            $ref = 'SAMPLE-CERT-000' . $n;
            if (ProjectCertificate::query()->where('reference', $ref)->exists()) {
                $this->kept++;

                continue;
            }
            $cert = ProjectCertificate::forceCreate([
                'warehouse_id' => $warehouse->id, 'contract_id' => $contract->id, 'certificate_number' => $n, 'reference' => $ref, 'certificate_type' => 'Interim Payment Certificate',
                'status' => ProjectCertificate::STATUS_DRAFT, 'valuation_date' => now()->toDateString(), 'certificate_date' => now()->toDateString(),
                'period_covered' => 'SAMPLE', 'description' => 'SAMPLE certificate ' . $n . ' to try "remove draft and approve"', 'notes' => 'SAMPLE', 'currency' => 'TZS', 'exchange_rate' => 1, 'created_by' => $admin->id,
            ]);
            foreach (\App\Http\Controllers\ProjectCertificateController::defaultLineTemplate() as $line) {
                ProjectCertificateLine::create(['certificate_id' => $cert->id, 'line_key' => $line['line_key'], 'label' => $line['label'], 'type' => $line['type'], 'sort_order' => $line['sort_order'],
                    'operands' => $line['operands'], 'value' => $line['line_key'] === 'works_executed' ? 12000000 * $n : 0]);
            }
            $this->made++;
            $this->line2($ref);
        }

        foreach ([1 => 'SAMPLE addendum: extra drainage works', 2 => 'SAMPLE addendum: change of paint specification'] as $n => $title) {
            if (ProjectCertificateContractAddendum::withTrashed()->where('contract_id', $contract->id)->where('addendum_number', $n)->exists()) {
                $this->kept++;

                continue;
            }
            ProjectCertificateContractAddendum::forceCreate([
                'contract_id' => $contract->id, 'addendum_number' => $n, 'title' => $title, 'reason' => 'SAMPLE', 'addendum_date' => now()->toDateString(), 'reference_number' => 'SAMPLE-ADD-' . $n,
                'status' => ProjectCertificateContractAddendum::STATUS_DRAFT, 'notes' => 'SAMPLE', 'total_amount' => 0, 'created_by' => $admin->id,
            ]);
            $this->made++;
            $this->line2('SAMPLE-ADD-' . $n);
        }
    }

    // ------------------------------------------------------------------------------------------------ Accounts

    private function accounts(User $maker): void
    {
        $accts = AcctAccount::query()->where('status', 'active')->where('currency', 'TZS')->orderBy('id')->get();
        if ($accts->count() < 2) {
            $this->warn('Fewer than two active TZS accounts exist, so the Accounts samples were skipped.');

            return;
        }
        [$a, $b] = [$accts[0], $accts[1]];
        $cash = AcctAccount::query()->where('status', 'active')->where('name', 'like', '%cash%')->orderBy('id')->get();

        foreach ([['in', 'receipt', 250000, 'SAMPLE receipt: customer deposit'], ['out', 'payment', 120000, 'SAMPLE payment: site fuel']] as [$dir, $kind, $amount, $desc]) {
            if (AcctTransaction::query()->where('description', $desc)->exists()) {
                $this->kept++;

                continue;
            }
            $t = ReferenceLock::run('acct_txn', fn () => AcctTransaction::query()->create([
                'reference' => AcctNumbers::next('acct_transactions', 'ACT'), 'account_id' => $a->id, 'direction' => $dir, 'kind' => $kind, 'currency' => $a->currency, 'amount' => $amount,
                'txn_date' => now()->toDateString(), 'description' => $desc, 'notes' => 'SAMPLE record to try "remove draft and approve". Safe to delete.', 'status' => 'draft', 'created_by' => $maker->id,
            ]));
            $this->made++;
            $this->line2($t->reference);
        }

        foreach ([[$a, $b, 500000, 'SAMPLE transfer between bank accounts'], [($cash->first() ?: $b), $a, 200000, 'SAMPLE transfer from cash to bank']] as [$from, $to, $amount, $reason]) {
            if ((int) $from->id === (int) $to->id || AcctTransfer::query()->where('reason', $reason)->exists()) {
                $this->kept++;

                continue;
            }
            $t = ReferenceLock::run('acct_transfer', fn () => AcctTransfer::query()->create([
                'reference' => AcctNumbers::next('acct_transfers', 'TRF'), 'from_account_id' => $from->id, 'to_account_id' => $to->id, 'from_currency' => $from->currency, 'to_currency' => $to->currency,
                'from_amount' => $amount, 'to_amount' => $amount, 'exchange_rate' => 1, 'transfer_date' => now()->toDateString(), 'reason' => $reason, 'notes' => 'SAMPLE record to try "remove draft and approve". Safe to delete.',
                'status' => 'draft', 'created_by' => $maker->id,
            ]));
            $this->made++;
            $this->line2($t->reference);
        }

        // a reconciliation is a draft until it is submitted; prepared and counted by the maker, so Super Admin 1 may approve it
        $recAccounts = $cash->take(2)->values();
        if ($recAccounts->count() < 2) {
            $recAccounts = $accts->take(2)->values();
        }
        foreach ($recAccounts as $i => $acct) {
            $note = 'SAMPLE reconciliation ' . ($i + 1) . ' of ' . $acct->code;
            if (AcctReconciliation::query()->where('explanation', $note)->exists()) {
                $this->kept++;

                continue;
            }
            $expected = app(AcctBalance::class)->asOf($acct, now()->toDateString());
            $r = ReferenceLock::run('acct_recon', fn () => AcctReconciliation::query()->create([
                'reference' => AcctNumbers::next('acct_reconciliations', 'REC'), 'account_id' => $acct->id, 'kind' => 'cash', 'period_to' => now()->toDateString(), 'opening_balance' => $acct->opening_balance,
                'expected_balance' => $expected, 'counted_balance' => $expected, 'difference' => 0, 'counted_by' => $maker->id, 'prepared_by' => $maker->id, 'status' => 'draft', 'explanation' => $note,
            ]));
            $this->made++;
            $this->line2($r->reference);
        }
    }

    // ------------------------------------------------------------------------------------------------ --fresh

    private function removeSamples(): void
    {
        $gone = 0;

        // these two models soft-delete: the samples are removed for good, or their unique references would block the next run
        foreach (DB::table('salary_periods')->where('batch_reference', 'like', 'SAL-BATCH-SAMPLE-%')->get(['id', 'batch_reference', 'status']) as $p) {
            if (in_array($p->status, [SalaryPeriod::STATUS_PAID, SalaryPeriod::STATUS_PARTIALLY_PAID], true)) {
                $this->warn("{$p->batch_reference} has payments, so it was kept.");

                continue;
            }
            DB::table('salary_records')->where('salary_period_id', $p->id)->delete();
            DB::table('salary_periods')->where('id', $p->id)->delete();
            $gone++;
        }

        foreach (DB::table('labour_lists')->where('reference', 'like', 'LAB-SAMPLE-%')->get(['id', 'reference', 'payment_voucher_batch_id']) as $l) {
            if ($l->payment_voucher_batch_id) {
                $this->warn("{$l->reference} has a payment voucher, so it was kept.");

                continue;
            }
            DB::table('labour_list_items')->where('labour_list_id', $l->id)->delete();
            DB::table('labour_lists')->where('id', $l->id)->delete();
            $gone++;
        }

        foreach (ProjectCertificate::query()->where('reference', 'like', 'SAMPLE-CERT-%')->get() as $c) {
            if (in_array($c->status, [ProjectCertificate::STATUS_ISSUED, ProjectCertificate::STATUS_REVISED], true)) {
                $this->warn("{$c->reference} was issued, so it was kept.");

                continue;
            }
            ProjectCertificateLine::query()->where('certificate_id', $c->id)->delete();
            $c->forceDelete();
            $gone++;
        }
        $contract = ProjectCertificateContract::query()->where('contract_reference', 'SAMPLE-CONTRACT-01')->first();
        if ($contract) {
            ProjectCertificateContractAddendum::withTrashed()->where('contract_id', $contract->id)->where('reference_number', 'like', 'SAMPLE-ADD-%')->forceDelete();
            if (! ProjectCertificate::query()->where('contract_id', $contract->id)->exists() && ! ProjectCertificateContractAddendum::withTrashed()->where('contract_id', $contract->id)->exists()) {
                $contract->delete();
            }
        }

        $acc = DB::connection('accounts');
        foreach (AcctTransaction::query()->where('notes', 'like', 'SAMPLE record%')->get() as $t) {
            if (in_array($t->status, ['posted', 'reversed'], true)) {
                $this->warn("{$t->reference} was posted (a balance changed), so it was kept. Reverse it first.");

                continue;
            }
            $this->dropTrail($acc, 'transaction', $t->id);
            $t->delete();
            $gone++;
        }
        foreach (AcctTransfer::query()->where('notes', 'like', 'SAMPLE record%')->get() as $t) {
            if (in_array($t->status, ['posted', 'reversed'], true)) {
                $this->warn("{$t->reference} was posted (balances changed), so it was kept. Reverse it first.");

                continue;
            }
            $this->dropTrail($acc, 'transfer', $t->id);
            $t->delete();
            $gone++;
        }
        foreach (AcctReconciliation::query()->where('explanation', 'like', 'SAMPLE reconciliation%')->get() as $r) {
            \App\Models\Accounts\AcctReconItem::query()->where('reconciliation_id', $r->id)->delete();
            $this->dropTrail($acc, 'reconciliation', $r->id);
            $r->delete();
            $gone++;
        }

        $this->info("Removed {$gone} earlier sample(s).");
    }

    /** The approvals and alerts that belong to a sample record (the tamper-evident audit log is never edited). */
    private function dropTrail($acc, string $subject, int $id): void
    {
        $acc->table('acct_approvals')->where('subject_type', $subject)->where('subject_id', $id)->delete();
        $acc->table('acct_alerts')->where('subject_type', $subject)->where('subject_id', $id)->delete();
    }

    private function line2(string $ref): void
    {
        $this->line('  + ' . $ref);
    }
}
