<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts Tracking: the money actually held (bank, cash, other) and every movement of it, in its OWN schema (connection `accounts`).
 *
 *  - acct_accounts is the account register. An account may be LINKED to a Funds Allocation account (fund_account_id) so the two stay consistent;
 *    nothing in the WIMS database is altered.
 *  - acct_transactions is the ledger: one row per account per movement. Only POSTED rows change a balance.
 *  - acct_transfers groups the rows of a movement between two company accounts (debit, credit, fee).
 *  Amounts are decimal(20,4); the currency's own precision is applied by the application (never floats).
 *  Other records (users, projects, contacts, funds allocations) are referred to by id and checked by the application: a foreign key cannot
 *  cross schemas safely, and the financial schema must be restorable on its own.
 */
return new class extends Migration
{
    protected $connection = 'accounts';

    public function up(): void
    {
        $S = Schema::connection('accounts');

        $S->create('acct_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name');
            $t->string('kind', 30)->default('bank_account')->index(); // bank_account | cash_in_hand | petty_cash | project_cash | office_cash | foreign_cash | mobile_money | other
            $t->string('currency', 3);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('fund_account_id')->nullable()->unique(); // the Funds Allocation account this one mirrors
            $t->string('institution')->nullable();
            $t->string('branch')->nullable();
            $t->string('account_holder')->nullable();
            $t->text('account_number')->nullable();          // stored encrypted by the application
            $t->string('account_number_masked', 40)->nullable();
            $t->string('swift', 40)->nullable();
            $t->string('iban', 60)->nullable();
            $t->string('legal_entity')->nullable();
            $t->string('location')->nullable();
            $t->string('department')->nullable();
            $t->unsignedBigInteger('warehouse_id')->nullable()->index();
            $t->unsignedBigInteger('custodian_user_id')->nullable()->index();
            $t->boolean('is_primary')->default(false);
            $t->string('status', 20)->default('active')->index(); // active | inactive | suspended | closed
            $t->decimal('opening_balance', 20, 4)->default(0);
            $t->date('opening_date')->nullable();
            $t->decimal('min_balance', 20, 4)->nullable();
            $t->decimal('max_balance', 20, 4)->nullable();
            $t->decimal('txn_limit', 20, 4)->nullable();
            $t->boolean('allow_negative')->default(false);
            $t->boolean('requires_approval')->default(false); // true = at least one approval, whatever the rules say
            $t->unsignedSmallInteger('reconcile_every_days')->nullable();
            $t->json('alert_user_ids')->nullable();
            $t->json('allowed_user_ids')->nullable();       // who may use this account (empty = anybody with the ability)
            $t->decimal('balance', 20, 4)->default(0);       // the posted balance, kept in step with the ledger inside the posting transaction
            $t->timestamp('balance_updated_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->text('closed_reason')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        $S->create('acct_currencies', function (Blueprint $t) {
            $t->id();
            $t->string('code', 3)->unique();
            $t->string('name');
            $t->string('symbol', 10);
            $t->unsignedTinyInteger('precision')->default(2);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_reporting')->default(false);
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        $S->create('acct_exchange_rates', function (Blueprint $t) {
            $t->id();
            $t->string('base_currency', 3);
            $t->string('quote_currency', 3);
            $t->decimal('rate', 20, 8);                         // 1 base = rate quote
            $t->string('rate_type', 24)->default('management'); // management | buying | selling | bank_transfer | cash | other
            $t->string('source', 24)->default('manual');        // manual | bank | provider | other
            $t->string('source_name')->nullable();
            $t->string('reference')->nullable();
            $t->dateTime('rate_at');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('status', 16)->default('pending');       // pending | approved | rejected
            $t->unsignedBigInteger('entered_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->text('reject_reason')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['base_currency', 'quote_currency', 'status', 'effective_from'], 'acct_rate_lookup');
        });

        $S->create('acct_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('direction', 8)->default('both'); // in | out | both
            $t->unsignedBigInteger('parent_id')->nullable()->index();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_system')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });

        $S->create('acct_transfers', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40)->unique();
            $t->string('idempotency_key', 80)->nullable()->unique();
            $t->unsignedBigInteger('from_account_id')->index();
            $t->unsignedBigInteger('to_account_id')->index();
            $t->string('from_currency', 3);
            $t->string('to_currency', 3);
            $t->decimal('from_amount', 20, 4);
            $t->decimal('to_amount', 20, 4);
            $t->unsignedBigInteger('rate_id')->nullable();
            $t->decimal('exchange_rate', 20, 8)->nullable();
            $t->decimal('fee_amount', 20, 4)->default(0);        // charged to the source account, in its currency
            $t->decimal('rounding_difference', 20, 4)->default(0);
            $t->decimal('reporting_amount', 20, 4)->nullable();
            $t->string('reporting_currency', 3)->nullable();
            $t->date('transfer_date');
            $t->string('status', 16)->default('draft')->index(); // draft | submitted | approved | posted | rejected | cancelled | reversed
            $t->unsignedTinyInteger('required_approvals')->default(1);
            $t->unsignedTinyInteger('approvals_count')->default(0);
            $t->string('reason')->nullable();
            $t->unsignedBigInteger('warehouse_id')->nullable();
            $t->string('department')->nullable();
            $t->string('external_ref')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable()->index();
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->unsignedBigInteger('posted_by')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->text('reject_reason')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->timestamps();
        });

        $S->create('acct_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40)->unique();
            $t->string('idempotency_key', 80)->nullable()->unique();
            $t->unsignedBigInteger('account_id')->index();
            $t->string('direction', 3);                        // in | out
            $t->string('kind', 16)->default('receipt');        // receipt | payment | transfer | fee | adjustment | reversal
            $t->string('currency', 3);
            $t->decimal('amount', 20, 4);
            $t->date('txn_date')->index();
            $t->time('txn_time')->nullable();
            $t->date('value_date')->nullable()->index();
            $t->unsignedBigInteger('category_id')->nullable()->index();
            $t->string('description')->nullable();
            $t->text('reason')->nullable();
            $t->string('payment_method', 24)->nullable();
            $t->json('method_details')->nullable();
            $t->string('external_ref')->nullable();
            $t->string('receipt_no')->nullable();
            $t->string('invoice_no')->nullable();
            $t->string('voucher_no')->nullable();
            $t->string('counterparty_type', 16)->nullable();   // contact | staff | other
            $t->string('counterparty_name')->nullable();
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->unsignedBigInteger('staff_id')->nullable();
            $t->unsignedBigInteger('warehouse_id')->nullable()->index();
            $t->string('department')->nullable();
            $t->string('cost_centre')->nullable();
            $t->unsignedBigInteger('fund_allocation_id')->nullable()->index();
            $t->unsignedBigInteger('fund_item_line_id')->nullable();
            $t->string('source_module', 40)->nullable();
            $t->string('source_type', 60)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->unsignedBigInteger('transfer_id')->nullable()->index();
            $t->unsignedBigInteger('reverses_id')->nullable()->index();
            $t->unsignedBigInteger('reversed_by_id')->nullable();
            $t->string('status', 16)->default('draft')->index(); // draft | submitted | approved | posted | rejected | cancelled | reversed
            $t->unsignedTinyInteger('required_approvals')->default(1);
            $t->unsignedTinyInteger('approvals_count')->default(0);
            $t->unsignedBigInteger('rate_id')->nullable();
            $t->decimal('exchange_rate', 20, 8)->nullable();
            $t->decimal('reporting_amount', 20, 4)->nullable();
            $t->string('reporting_currency', 3)->nullable();
            $t->unsignedBigInteger('created_by')->nullable()->index();
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->unsignedBigInteger('posted_by')->nullable();
            $t->timestamp('posted_at')->nullable()->index();
            $t->text('reject_reason')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->unsignedBigInteger('reconciliation_id')->nullable()->index();
            $t->timestamp('reconciled_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['source_module', 'source_type', 'source_id'], 'acct_source_once');
            $t->index(['account_id', 'status', 'txn_date'], 'acct_acc_status_date');
        });

        $S->create('acct_approvals', function (Blueprint $t) {
            $t->id();
            $t->string('subject_type', 20);   // transaction | transfer | rate | reconciliation
            $t->unsignedBigInteger('subject_id');
            $t->string('action', 20);         // submitted | approved | rejected | posted | cancelled | reversed
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('role_context')->nullable();
            $t->text('comment')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id']);
        });

        $S->create('acct_approval_rules', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('applies_to', 12)->default('outflow'); // outflow | inflow | transfer | adjustment
            $t->string('currency', 3)->nullable();           // thresholds are compared in this currency only; null = any currency, any amount
            $t->decimal('min_amount', 20, 4)->default(0);
            $t->decimal('max_amount', 20, 4)->nullable();
            $t->unsignedTinyInteger('approvals_required')->default(1); // at least 1: every money in, money out, transfer and adjustment needs approval
            $t->unsignedBigInteger('account_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });

        $S->create('acct_attachments', function (Blueprint $t) {
            $t->id();
            $t->string('subject_type', 20);
            $t->unsignedBigInteger('subject_id');
            $t->string('doc_type', 30)->nullable();           // receipt | voucher | invoice | bank_confirmation | approval | signed_cash_voucher | other
            $t->string('title');
            $t->string('path');
            $t->string('mime', 100)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->boolean('restricted')->default(false);
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id']);
        });

        $S->create('acct_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40)->unique();
            $t->unsignedBigInteger('account_id')->index();
            $t->string('kind', 8);                              // bank | cash
            $t->date('period_from')->nullable();
            $t->date('period_to');
            $t->decimal('opening_balance', 20, 4)->default(0);
            $t->decimal('expected_balance', 20, 4)->default(0); // what WIMS says at period_to
            $t->decimal('counted_balance', 20, 4)->nullable();  // statement closing balance, or physical cash counted
            $t->decimal('difference', 20, 4)->default(0);       // counted - expected
            $t->json('denominations')->nullable();
            $t->unsignedBigInteger('counted_by')->nullable();
            $t->string('witness_name')->nullable();
            $t->string('status', 16)->default('draft')->index(); // draft | submitted | approved | rejected
            $t->text('explanation')->nullable();
            $t->unsignedBigInteger('adjustment_txn_id')->nullable();
            $t->unsignedBigInteger('prepared_by')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->text('reject_reason')->nullable();
            $t->timestamps();
        });

        $S->create('acct_recon_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('reconciliation_id')->index();
            $t->date('entry_date');
            $t->string('description')->nullable();
            $t->string('reference')->nullable();
            $t->string('direction', 3);
            $t->decimal('amount', 20, 4);
            $t->unsignedBigInteger('matched_txn_id')->nullable();
            $t->string('status', 12)->default('unmatched'); // matched | unmatched | duplicate | ignored
            $t->string('note')->nullable();
            $t->timestamps();
        });

        $S->create('acct_alerts', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40)->unique();
            $t->string('dedupe_key', 120)->index();
            $t->string('category', 20)->index();   // balance | transaction | transfer | reconciliation | currency | security
            $t->string('type', 40);
            $t->string('severity', 10)->index();   // info | low | medium | high | critical
            $t->string('title');
            $t->text('description')->nullable();
            $t->unsignedBigInteger('account_id')->nullable()->index();
            $t->string('subject_type', 20)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->decimal('amount', 20, 4)->nullable();
            $t->string('currency', 3)->nullable();
            $t->string('trigger')->nullable();
            $t->string('status', 14)->default('open')->index(); // open | acknowledged | resolved
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->unsignedBigInteger('acknowledged_by')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->text('resolution_notes')->nullable();
            $t->unsignedInteger('occurrences')->default(1);
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('escalated_at')->nullable();
            $t->timestamps();
        });

        $S->create('acct_audit', function (Blueprint $t) {
            $t->id();
            $t->string('event', 60)->index();
            $t->string('entity_type', 30)->index();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->string('reference', 60)->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('role_context')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->text('reason')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('device', 255)->nullable();
            $t->string('correlation_id', 40)->nullable();
            $t->string('hash', 64)->nullable();     // sha256 of this row + the previous row's hash: an edit anywhere breaks the chain
            $t->timestamp('created_at')->useCurrent()->index();
        });

        // ---- starting data ----
        $C = DB::connection('accounts');
        $now = now();
        $C->table('acct_currencies')->insert([
            ['code' => 'TZS', 'name' => 'Tanzanian Shilling', 'symbol' => 'TSh', 'precision' => 2, 'is_active' => 1, 'is_reporting' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'precision' => 2, 'is_active' => 1, 'is_reporting' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $in = ['Cash received', 'Bank deposit', 'Client / customer payment', 'Project funds received', 'Funds from another company account', 'Refund received', 'Loan received', 'Interest received', 'Other income'];
        $out = ['Cash payment', 'Bank withdrawal', 'Supplier payment', 'Employee payment', 'Labour payment', 'Project expenditure', 'Transport expense', 'Office expense', 'Utility payment', 'Bank charges', 'Tax payment', 'Loan repayment', 'Refund issued', 'Petty cash expenditure', 'Other expense'];
        foreach ($in as $i => $n) {
            $C->table('acct_categories')->insert(['name' => $n, 'direction' => 'in', 'is_system' => 1, 'sort_order' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ($out as $i => $n) {
            $C->table('acct_categories')->insert(['name' => $n, 'direction' => 'out', 'is_system' => 1, 'sort_order' => 200 + ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now]);
        }
        $C->table('acct_categories')->insert([
            ['name' => 'Transfer between accounts', 'direction' => 'both', 'is_system' => 1, 'sort_order' => 900, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Transfer / conversion fee', 'direction' => 'out', 'is_system' => 1, 'sort_order' => 910, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Adjustment', 'direction' => 'both', 'is_system' => 1, 'sort_order' => 920, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Reversal', 'direction' => 'both', 'is_system' => 1, 'sort_order' => 930, 'created_at' => $now, 'updated_at' => $now],
        ]);
        // sensible approval defaults, all editable: receipts post without approval, payments and transfers need one, adjustments two
        $C->table('acct_approval_rules')->insert([
            ['name' => 'Receipts', 'applies_to' => 'inflow', 'approvals_required' => 1, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Payments', 'applies_to' => 'outflow', 'approvals_required' => 1, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Transfers', 'applies_to' => 'transfer', 'approvals_required' => 1, 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Adjustments', 'applies_to' => 'adjustment', 'approvals_required' => 2, 'sort_order' => 40, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        foreach (['acct_audit', 'acct_alerts', 'acct_recon_items', 'acct_reconciliations', 'acct_attachments', 'acct_approval_rules', 'acct_approvals', 'acct_transactions', 'acct_transfers', 'acct_categories', 'acct_exchange_rates', 'acct_currencies', 'acct_accounts'] as $t) {
            Schema::connection('accounts')->dropIfExists($t);
        }
    }
};
