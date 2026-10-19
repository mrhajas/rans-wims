<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes for the queries that group payment requests by agreement and status (the ledger, the reports, the agreement page) and that look an alert up
 * by its dedupe key and status (every alert raised by the hourly sweep). Safe to run twice: each index is only added when missing.
 */
return new class extends Migration
{
    private const INDEXES = [
        'subcontract_payment_requests' => ['sc_req_agr_status' => ['agreement_id', 'status']],
        'subcontract_alerts' => ['sc_alert_key_status' => ['dedupe_key', 'status']],
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
