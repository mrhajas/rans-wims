<?php

use App\Services\Signatories\MappingSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Puts the starting signing setup of every supported document in place (Payment Voucher, Salary Voucher, Labour List, Fund Allocation,
 * Project Certificate, Contract Addendum, Material Request, Material Reallocation, Transfer, Adjustment, Super Adjustment, Site Meeting
 * Minutes, Daily Site Report). Nothing is required and nothing blocks. Until somebody has an ACTIVE signature the reports print exactly
 * as before (blank lines). An administrator can change every line on the Document Signing Setup page; this never overwrites a document
 * that already has a setup. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('signing_mappings') && Schema::hasTable('signing_roles')) {
            app(MappingSeeder::class)->seedAll();
        }
    }

    public function down(): void
    {
    }
};
