<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_certificate_contracts', function (Blueprint $table) {
            $table->boolean('vat_exempted')->default(false)->after('vat_rate');
        });

        // New projects default to 18% (the current standard VAT rate) —
        // the 15% default this column originally shipped with is now stale.
        // MySQL requires restating the full column definition to change
        // just its default. Only affects future inserts that bypass the
        // app layer; existing rows and the controller's own PHP default
        // (which every real save already goes through) are untouched.
        DB::statement('ALTER TABLE project_certificate_contracts MODIFY vat_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_certificate_contracts', function (Blueprint $table) {
            $table->dropColumn('vat_exempted');
        });

        DB::statement('ALTER TABLE project_certificate_contracts MODIFY vat_rate DECIMAL(5,2) NOT NULL DEFAULT 15.00');
    }
};
