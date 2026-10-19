<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * These models declare $tracksDeletes = true (the base Model records who deleted a row in `deleted_by`),
 * but their tables were created without that column, so deleting a forecast line or an issue ended in a
 * "Unknown column 'deleted_by'" server error. Same fix as supplier_ledger_entries.
 */
return new class extends Migration
{
    private array $tables = [
        'work_package_material_forecasts',
        'work_package_labour_forecasts',
        'work_package_equipment_forecasts',
        'work_package_issues',
        'supplier_reconciliations',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'deleted_by')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->unsignedBigInteger('deleted_by')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'deleted_by')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('deleted_by');
                });
            }
        }
    }
};
