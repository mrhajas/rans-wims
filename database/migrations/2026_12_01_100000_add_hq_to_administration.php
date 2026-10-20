<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every Administration document belongs to one headquarters (Unguja HQ, Pemba HQ... a soft-coded list in the Administration settings), chosen first and never left
 * blank. A person of the Charity list can belong to one HQ too (blank = both), so a document pulls only the people of its own HQ. Documents made before get the first HQ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_documents') && ! Schema::hasColumn('admin_documents', 'hq')) {
            Schema::table('admin_documents', fn (Blueprint $t) => $t->string('hq', 60)->nullable()->after('reference')->index());
        }
        if (Schema::hasTable('admin_charity_people') && ! Schema::hasColumn('admin_charity_people', 'hq')) {
            Schema::table('admin_charity_people', fn (Blueprint $t) => $t->string('hq', 60)->nullable()->after('name')->index());
        }
        if (Schema::hasColumn('admin_documents', 'hq')) {
            DB::table('admin_documents')->whereNull('hq')->update(['hq' => 'Unguja HQ']);
        }
    }

    public function down(): void
    {
        foreach (['admin_charity_people', 'admin_documents'] as $t) {
            if (Schema::hasColumn($t, 'hq')) {
                Schema::table($t, fn (Blueprint $b) => $b->dropColumn('hq'));
            }
        }
    }
};
