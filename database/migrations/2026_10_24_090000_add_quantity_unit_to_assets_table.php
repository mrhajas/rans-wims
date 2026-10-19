<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An asset can now carry a Quantity and a Unit (for example 4 pcs of the same scaffolding set). Additive only: two nullable
 * columns, nothing is changed or removed. Existing assets keep an empty unit and a quantity of 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            if (! Schema::hasColumn('assets', 'quantity')) {
                $table->decimal('quantity', 15, 3)->default(1)->after('serial_number');
            }
            if (! Schema::hasColumn('assets', 'unit_id')) {
                $table->unsignedBigInteger('unit_id')->nullable()->after('quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            foreach (['unit_id', 'quantity'] as $c) {
                if (Schema::hasColumn('assets', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
