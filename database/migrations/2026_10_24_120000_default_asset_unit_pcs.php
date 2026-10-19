<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Assets that have no unit yet get "Pcs" (the usual unit for an asset); anything that already has a unit is left alone. */
return new class extends Migration
{
    public function up(): void
    {
        $pcs = DB::table('units')->where('code', 'pcs')->whereNull('base_unit_id')->whereNull('deleted_at')->orderBy('id')->value('id');
        if ($pcs) {
            DB::table('assets')->whereNull('unit_id')->update(['unit_id' => $pcs]);
        }
    }

    public function down(): void
    {
        // nothing to undo: which assets had no unit before is not recorded
    }
};
