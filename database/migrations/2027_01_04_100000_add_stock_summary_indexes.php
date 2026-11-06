<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two covering indexes on `stocks`, for the two sums that every Items page and Dashboard load makes over all ~30,000 stock lines:
 *   - the stock of every item (Items tiles): variation_id, item_id, deleted_at, account_id, quantity
 *   - the stock value of every warehouse (Dashboard):  warehouse_id, deleted_at, account_id, quantity, item_id
 * With them the database answers from the index alone (no table lookups, no temporary table): on the live-data copy the Dashboard sum fell from about 49 ms to 13 ms and the Items tiles by a
 * third. Nothing is changed in any record. Reversible: down() drops them. Writes to stocks maintain two more small indexes (about 1.5 MB each).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stocks')) {
            return;
        }
        if (! $this->has('stocks_item_totals_idx')) {
            Schema::table('stocks', fn (Blueprint $t) => $t->index(['variation_id', 'item_id', 'deleted_at', 'account_id', 'quantity'], 'stocks_item_totals_idx'));
        }
        if (! $this->has('stocks_warehouse_value_idx')) {
            Schema::table('stocks', fn (Blueprint $t) => $t->index(['warehouse_id', 'deleted_at', 'account_id', 'quantity', 'item_id'], 'stocks_warehouse_value_idx'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stocks')) {
            return;
        }
        foreach (['stocks_item_totals_idx', 'stocks_warehouse_value_idx'] as $name) {
            if ($this->has($name)) {
                Schema::table('stocks', fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }

    private function has(string $index): bool
    {
        return collect(Schema::getIndexes('stocks'))->contains(fn ($i) => ($i['name'] ?? null) === $index);
    }
};
