<?php

use App\Services\Signatories\MappingSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Subcontractor Management, External Stock and Accounts Tracking join the Signatories module: the starting document setup (Prepared / Reviewed /
 * Approved By lines, never required) for their approval based records and generated reports. Never overwrites a setup an administrator already made.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('signing_mappings') || ! Schema::hasTable('signing_roles')) {
            return;
        }
        $seeder = app(MappingSeeder::class);
        foreach (['subcontract_agreement', 'subcontract_variation', 'subcontract_request', 'subcontract_report', 'external_stock', 'external_stock_report', 'acct_transaction', 'acct_transfer', 'acct_report'] as $type) {
            $seeder->seedAll($type);
        }
    }

    public function down(): void
    {
    }
};
