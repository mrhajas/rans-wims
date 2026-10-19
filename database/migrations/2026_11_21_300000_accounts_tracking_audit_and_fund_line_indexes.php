<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts Tracking: two indexes for the lookups the pages make on every visit.
 *  - acct_audit (entity_type, entity_id, id): the "history" of one transaction, transfer, account or reconciliation reads its own audit rows
 *    newest first; with only single-column indexes it scanned every row of that entity type.
 *  - acct_transactions (fund_item_line_id): the Funds Allocation pages look up the payments made from a line.
 * Safe to run twice: an index that is already there is left alone.
 */
return new class extends Migration
{
    protected $connection = 'accounts';

    public function up(): void
    {
        $S = Schema::connection('accounts');
        if ($S->hasTable('acct_audit') && ! $this->has('acct_audit', 'acct_audit_entity_lookup')) {
            $S->table('acct_audit', fn ($t) => $t->index(['entity_type', 'entity_id', 'id'], 'acct_audit_entity_lookup'));
        }
        if ($S->hasTable('acct_transactions') && $S->hasColumn('acct_transactions', 'fund_item_line_id') && ! $this->has('acct_transactions', 'acct_transactions_fund_item_line_id_index')) {
            $S->table('acct_transactions', fn ($t) => $t->index('fund_item_line_id', 'acct_transactions_fund_item_line_id_index'));
        }
    }

    public function down(): void
    {
        $S = Schema::connection('accounts');
        if ($S->hasTable('acct_audit') && $this->has('acct_audit', 'acct_audit_entity_lookup')) {
            $S->table('acct_audit', fn ($t) => $t->dropIndex('acct_audit_entity_lookup'));
        }
        if ($S->hasTable('acct_transactions') && $this->has('acct_transactions', 'acct_transactions_fund_item_line_id_index')) {
            $S->table('acct_transactions', fn ($t) => $t->dropIndex('acct_transactions_fund_item_line_id_index'));
        }
    }

    private function has(string $table, string $index): bool
    {
        return collect(DB::connection('accounts')->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]))->isNotEmpty();
    }
};
