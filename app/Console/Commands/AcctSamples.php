<?php

namespace App\Console\Commands;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctExchangeRate;
use App\Models\Accounts\AcctTransaction;
use App\Models\User;
use App\Services\Accounts\AcctAlerts;
use App\Services\Accounts\AcctPosting;
use App\Services\Accounts\AcctReconciler;
use App\Support\Accounts\AcctException;
use Illuminate\Console\Command;

/**
 * Sample Accounts Tracking data for review. Every account is named "SAMPLE ..." with a code starting SMP-, so it is obvious and can be taken away again
 * with --remove. Everything goes through the real posting rules (nothing is written to a balance directly).
 */
class AcctSamples extends Command
{
    protected $signature = 'acct:samples {--remove : remove the sample accounts and everything posted on them}';

    protected $description = 'Make (or remove) clearly marked sample accounts and transactions for Accounts Tracking review';

    public function handle(AcctPosting $svc, AcctReconciler $rec): int
    {
        $admins = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->orderBy('id')->limit(2)->get();
        if ($admins->count() < 2) {
            $this->error('Two Super Admin users are needed so approvals can be made by someone other than the person who entered them.');

            return self::FAILURE;
        }
        [$a, $b] = [$admins[0], $admins[1]];
        auth()->setUser($a);
        $fundIds = \App\Models\FundAccount::withTrashed()->where('name', 'like', 'SAMPLE %')->pluck('id');
        $ids = AcctAccount::query()->where(fn ($q) => $q->whereIn('fund_account_id', $fundIds)->orWhere('code', 'like', 'SMP-%'))->pluck('id');
        if ($this->option('remove')) {
            $c = \DB::connection('accounts');
            $tr = $c->table('acct_transfers')->whereIn('from_account_id', $ids)->orWhereIn('to_account_id', $ids)->pluck('id');
            $tx = $c->table('acct_transactions')->whereIn('account_id', $ids)->pluck('id');
            $c->table('acct_recon_items')->whereIn('reconciliation_id', $c->table('acct_reconciliations')->whereIn('account_id', $ids)->pluck('id'))->delete();
            $c->table('acct_reconciliations')->whereIn('account_id', $ids)->delete();
            $c->table('acct_approvals')->where(fn ($q) => $q->where('subject_type', 'transaction')->whereIn('subject_id', $tx))->orWhere(fn ($q) => $q->where('subject_type', 'transfer')->whereIn('subject_id', $tr))->delete();
            $c->table('acct_alerts')->whereIn('account_id', $ids)->delete();
            $c->table('acct_transactions')->whereIn('account_id', $ids)->delete();
            $c->table('acct_transfers')->whereIn('id', $tr)->delete();
            AcctAccount::query()->whereIn('id', $ids)->delete();
            \App\Models\FundAccount::withTrashed()->whereIn('id', $fundIds)->get()->each->forceDelete();
            $this->info('Sample accounts removed (the audit trail keeps its record of them).');

            return self::SUCCESS;
        }
        if ($ids->isNotEmpty()) {
            $this->warn('Sample accounts already exist. Use --remove first to start again.');

            return self::SUCCESS;
        }
        $finish = function ($t, $by = null) use ($svc, $a, $b) {
            $t = $svc->submit($t, $a);
            $i = 0;
            while ($t->status === 'submitted' && $i++ < 4) {
                $t = $svc->approve($t, $i % 2 ? $b : $a, 'Sample');
            }

            return $t;
        };
        \Storage::disk('local')->put('acct-attachments/sample/sample-voucher.txt', "SAMPLE supporting document.\nThis stands in for a real receipt, voucher or invoice so that large sample payments can be submitted.\n");
        $attach = function ($t) use ($a) {
            \App\Models\Accounts\AcctAttachment::query()->create(['subject_type' => 'transaction', 'subject_id' => $t->id, 'doc_type' => 'voucher', 'title' => 'sample-voucher.txt', 'path' => 'acct-attachments/sample/sample-voucher.txt', 'mime' => 'text/plain', 'size' => 120, 'uploaded_by' => $a->id, 'created_at' => now()]);

            return $t;
        };
        $post = fn ($t) => $svc->post($finish($attach($t)), $a);
        // The accounts are Funds Allocation accounts (they appear in Accounts Tracking by themselves); what is set here is the money side.
        $mk = function (string $code, string $name, string $kind, string $cur, string $open, array $x = []) use ($a) {
            $type = ['bank_account' => 'bank', 'cash_in_hand' => 'cash', 'petty_cash' => 'cash', 'mobile_money' => 'mobile'][$kind] ?? 'other';
            $fund = \App\Models\FundAccount::query()->create(['name' => $name, 'type' => $type, 'currency' => $cur, 'account_number' => $x['account_number_plain'] ?? null, 'is_active' => true, 'created_by_user_id' => $a->id]);
            unset($x['account_number_plain']);
            $acct = AcctAccount::query()->where('fund_account_id', $fund->id)->firstOrFail();
            unset($x['account_number'], $x['account_number_masked']);
            $acct->forceFill($x + ['kind' => $kind, 'opening_balance' => $open, 'balance' => $open, 'opening_date' => now()->subDays(75)->toDateString(), 'created_by' => $a->id])->save();

            return $acct;
        };

        $bank = $mk('SMP-BANK-TZS', 'SAMPLE Main Bank (TZS)', 'bank_account', 'TZS', '25000000', ['institution' => 'Sample Bank', 'branch' => 'Dar es Salaam', 'account_holder' => 'RANS Sample Ltd', 'account_number_plain' => '0150123456700', 'is_primary' => true, 'min_balance' => '5000000', 'reconcile_every_days' => 30, 'custodian_user_id' => $a->id]);
        $usd = $mk('SMP-BANK-USD', 'SAMPLE Dollar Account (USD)', 'bank_account', 'USD', '8000', ['institution' => 'Sample Bank', 'account_number_plain' => '0250765432100', 'min_balance' => '1000', 'custodian_user_id' => $a->id]);
        $cash = $mk('SMP-CASH-HO', 'SAMPLE Cash in Hand (Head Office)', 'cash_in_hand', 'TZS', '1200000', ['min_balance' => '300000', 'max_balance' => '3000000', 'custodian_user_id' => $b->id, 'location' => 'Head office safe', 'reconcile_every_days' => 7]);
        $petty = $mk('SMP-PETTY-SITE', 'SAMPLE Site Petty Cash', 'petty_cash', 'TZS', '500000', ['min_balance' => '100000', 'custodian_user_id' => $b->id, 'location' => 'Site office']);
        $mobile = $mk('SMP-MOBILE', 'SAMPLE Mobile Money Wallet', 'mobile_money', 'TZS', '350000', ['institution' => 'Sample Mobile', 'custodian_user_id' => $a->id]);
        $this->info('Accounts created.');

        AcctExchangeRate::query()->create(['base_currency' => 'USD', 'quote_currency' => 'TZS', 'rate' => '2540', 'rate_type' => 'management', 'source' => 'manual', 'source_name' => 'Sample management rate', 'rate_at' => now()->subDays(30), 'effective_from' => now()->subDays(30)->toDateString(), 'status' => 'approved', 'entered_by' => $a->id, 'approved_by' => $b->id, 'approved_at' => now()->subDays(30)]);
        AcctExchangeRate::query()->create(['base_currency' => 'USD', 'quote_currency' => 'TZS', 'rate' => '2575', 'rate_type' => 'bank_transfer', 'source' => 'bank', 'source_name' => 'Sample Bank', 'rate_at' => now()->subDay(), 'effective_from' => now()->subDay()->toDateString(), 'status' => 'pending', 'entered_by' => $b->id]);
        $rate = AcctExchangeRate::query()->where('status', 'approved')->first();

        $cats = \App\Models\Accounts\AcctCategory::query()->pluck('id', 'name');
        $cat = fn (string $like) => $cats->first(fn ($id, $n) => stripos($n, $like) !== false);
        $d = fn (int $ago) => now()->subDays($ago)->toDateString();
        $rows = [
            [$bank, 'in', '12500000', 60, 'Client payment: Block A progress certificate', 'Sample Client Ltd', 'bank_transfer', ['bank_reference' => 'TT-88120']], [$bank, 'out', '4200000', 55, 'Supplier payment: cement delivery', 'Sample Cement Co', 'bank_transfer', ['bank_reference' => 'TT-88220']],
            [$bank, 'out', '1850000', 48, 'Payroll advance and allowances', 'Staff', 'bank_transfer', []], [$bank, 'in', '6800000', 40, 'Client payment: variation order', 'Sample Client Ltd', 'bank_transfer', []],
            [$bank, 'out', '2750000', 33, 'Equipment hire, excavator', 'Sample Hire Ltd', 'cheque', ['cheque_number' => '004512', 'cheque_date' => now()->subDays(33)->toDateString()]], [$bank, 'out', '900000', 20, 'Insurance premium', 'Sample Insurance', 'bank_transfer', []],
            [$bank, 'in', '3300000', 12, 'Retention release', 'Sample Client Ltd', 'bank_transfer', []], [$bank, 'out', '1450000', 6, 'Fuel and lubricants for the month', 'Sample Fuel Station', 'bank_transfer', []],
            [$cash, 'out', '180000', 28, 'Office supplies', 'Sample Stationers', 'cash', []], [$cash, 'out', '95000', 21, 'Courier and postage', 'Sample Courier', 'cash', []], [$cash, 'in', '400000', 14, 'Cash refund from supplier', 'Sample Supplier', 'cash', []], [$cash, 'out', '260000', 5, 'Visitors hospitality', 'Various', 'cash', []],
            [$petty, 'out', '45000', 18, 'Water and refreshments for site', 'Local shop', 'cash', []], [$petty, 'out', '72000', 9, 'Small tools and nails', 'Hardware shop', 'cash', []], [$petty, 'out', '38000', 3, 'Transport of workers', 'Local transport', 'cash', []],
            [$mobile, 'out', '60000', 10, 'Airtime and data bundles', 'Sample Mobile', 'mobile_money', ['mobile_reference' => 'MP2610A8']], [$usd, 'out', '350', 15, 'Software subscription (USD)', 'Sample Software Inc', 'bank_transfer', []], [$usd, 'in', '1200', 8, 'Consultancy fee received (USD)', 'Sample Consulting', 'bank_transfer', []],
        ];
        foreach ($rows as [$acct, $dir, $amt, $ago, $desc, $who, $method, $det]) {
            try {
                $post($svc->create(['account_id' => $acct->id, 'direction' => $dir, 'amount' => $amt, 'txn_date' => $d($ago), 'description' => $desc, 'counterparty_name' => $who, 'payment_method' => $method, 'method_details' => $det, 'external_ref' => in_array($method, ['bank_transfer', 'mobile_money'], true) && ! $det ? 'REF-' . random_int(10000, 99999) : null, 'category_id' => $cat($dir === 'in' ? 'receipt' : 'general') ?: null, 'receipt_no' => $dir === 'in' ? 'RC-' . random_int(1000, 9999) : null, 'invoice_no' => $dir === 'out' && $method === 'bank_transfer' ? 'INV-' . random_int(1000, 9999) : null], $a));
            } catch (AcctException $e) {
                $this->warn($desc . ': ' . $e->getMessage());
            }
        }
        $this->info('Posted transactions created.');

        // transfers: bank to cash (same currency), dollars to shillings (conversion), cash to petty
        $tr = fn (array $x) => (function ($t) use ($svc, $a, $b) { $t = $svc->submitTransfer($t, $a); $i = 0; while ($t->status === 'submitted' && $i++ < 4) { $t = $svc->approveTransfer($t, $i % 2 ? $b : $a); } return $t; })($svc->createTransfer($x, $a));
        $t1 = $tr(['from_account_id' => $bank->id, 'to_account_id' => $cash->id, 'from_amount' => '1500000', 'transfer_date' => $d(24), 'reason' => 'Top up office cash']); $svc->postTransfer($t1, $a);
        $t2 = $tr(['from_account_id' => $usd->id, 'to_account_id' => $bank->id, 'from_amount' => '1000', 'to_amount' => '2536000', 'fee_amount' => '15', 'rate_id' => $rate->id, 'transfer_date' => $d(16), 'reason' => 'Convert dollars for local payments', 'notes' => 'Bank rate slightly below management rate']); $svc->postTransfer($t2, $a);
        $t3 = $tr(['from_account_id' => $cash->id, 'to_account_id' => $petty->id, 'from_amount' => '300000', 'transfer_date' => $d(11), 'reason' => 'Float for site']); $svc->postTransfer($t3, $a);

        // things waiting: a payment awaiting approval, an approved payment waiting to be posted, a draft, a rejected one, a transfer waiting
        $p1 = $svc->submit($attach($svc->create(['account_id' => $bank->id, 'direction' => 'out', 'amount' => '8400000', 'txn_date' => $d(1), 'description' => 'Subcontractor payment, roofing works', 'counterparty_name' => 'Sample Roofing Ltd', 'payment_method' => 'bank_transfer', 'external_ref' => 'REQ-7710', 'invoice_no' => 'INV-7710'], $a)), $a);
        $p2 = $svc->create(['account_id' => $cash->id, 'direction' => 'out', 'amount' => '120000', 'txn_date' => $d(1), 'description' => 'Site meeting lunch', 'payment_method' => 'cash'], $b);
        $p3 = $svc->submit($svc->create(['account_id' => $petty->id, 'direction' => 'out', 'amount' => '55000', 'txn_date' => $d(2), 'description' => 'Pump repair'], $a), $a);
        try { $svc->reject($p3, $b, 'Please attach the repair invoice'); } catch (AcctException $e) { }
        $x = $svc->createTransfer(['from_account_id' => $bank->id, 'to_account_id' => $usd->id, 'from_amount' => '5000000', 'to_amount' => '1965', 'rate_id' => $rate->id, 'transfer_date' => $d(0), 'reason' => 'Buy dollars for equipment import'], $a);
        $svc->submitTransfer($x, $a);

        // reconciliation: cash count with a shortfall, waiting for approval
        $bal = $cash->fresh()->balance;
        $left = (int) bcsub($bal, '15000', 0);
        $notes = [];
        foreach ([10000, 5000, 2000, 1000, 500] as $face) {
            $notes[$face] = intdiv($left, $face);
            $left -= $notes[$face] * $face;
        }
        $r = $rec->startCash($cash->fresh(), $b, ['period_to' => $d(0), 'counted_balance' => bcsub($bal, '15000', 4), 'witness_name' => 'Sample Witness', 'denominations' => $notes, 'explanation' => 'Sample: change not yet booked']);
        AcctAlerts::sweep();
        $this->info('Samples ready: ' . \App\Models\FundAccount::query()->where('name', 'like', 'SAMPLE %')->count() . ' accounts, ' . AcctTransaction::query()->count() . ' ledger rows.');

        return self::SUCCESS;
    }
}
