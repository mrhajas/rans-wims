<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Payment Voucher list sorts by voucher_date, filters by type and splits Authorised / Paid on paid_at; the batch list filters by
 * voucher_date and type. None of those had an index, so every page read the whole table. Safe to run twice: each index is only added when missing.
 */
return new class extends Migration
{
    private const INDEXES = [
        'payment_vouchers' => [
            'payment_vouchers_voucher_date_index' => ['voucher_date'],
            'payment_vouchers_type_index' => ['type'],
            'payment_vouchers_paid_at_index' => ['paid_at'],
            'payment_vouchers_status_paid_at_index' => ['status', 'paid_at'],
        ],
        'payment_voucher_batches' => [
            'payment_voucher_batches_voucher_date_index' => ['voucher_date'],
            'payment_voucher_batches_type_index' => ['type'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! Schema::hasTable($table) || ! $this->hasColumns($table, $columns) || $this->hasIndex($table, $name)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasTable($table) && $this->hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }

    private function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function hasIndex(string $table, string $name): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $name)
            ->exists();
    }
};
