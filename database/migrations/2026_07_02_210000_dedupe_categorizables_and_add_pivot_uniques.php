<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep only one row per category-object relation.
        DB::statement('CREATE TEMPORARY TABLE tmp_categorizables AS SELECT DISTINCT category_id, categorizable_type, categorizable_id FROM categorizables');
        DB::statement('TRUNCATE TABLE categorizables');
        DB::statement('INSERT INTO categorizables (category_id, categorizable_type, categorizable_id) SELECT category_id, categorizable_type, categorizable_id FROM tmp_categorizables');

        $this->addUniqueIfMissing('categorizables', ['category_id', 'categorizable_type', 'categorizable_id'], 'categorizables_unique_relation');
        $this->addUniqueIfMissing('adjustment_item_serial', ['serial_id', 'adjustment_item_id'], 'adjustment_item_serial_unique_pair');
        $this->addUniqueIfMissing('checkin_item_serial', ['serial_id', 'checkin_item_id'], 'checkin_item_serial_unique_pair');
        $this->addUniqueIfMissing('checkout_item_serial', ['serial_id', 'checkout_item_id'], 'checkout_item_serial_unique_pair');
        $this->addUniqueIfMissing('serial_transfer_item', ['serial_id', 'transfer_item_id'], 'serial_transfer_item_unique_pair');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('categorizables', 'categorizables_unique_relation');
        $this->dropIndexIfExists('adjustment_item_serial', 'adjustment_item_serial_unique_pair');
        $this->dropIndexIfExists('checkin_item_serial', 'checkin_item_serial_unique_pair');
        $this->dropIndexIfExists('checkout_item_serial', 'checkout_item_serial_unique_pair');
        $this->dropIndexIfExists('serial_transfer_item', 'serial_transfer_item_unique_pair');
    }

    private function addUniqueIfMissing(string $table, array $columns, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->unique($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropUnique($indexName);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
