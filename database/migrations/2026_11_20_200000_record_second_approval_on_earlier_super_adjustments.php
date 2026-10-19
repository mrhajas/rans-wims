<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Super Adjustments approved before the two-approval rule have no phase records. At the owner's instruction every one of them is shown as approved in
 * phase 1 by the person who approved it and in phase 2 by Eng Ali (username engalmiskry); SADJ-0001, whose phase 1 was Eng Ali's own, gets Director Asha (mrshajas) as phase 2. The rows are marked as recorded afterwards, and the history keeps it.
 * Nothing about the records themselves, their stock or their status changes. Safe to run again (a record that already has phase rows is left alone).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('super_adjustment_approvals') || ! Schema::hasTable('super_adjustments')) {
            return;
        }
        if (! Schema::hasColumn('super_adjustment_approvals', 'note')) {
            Schema::table('super_adjustment_approvals', function (Blueprint $t) {
                $t->string('note', 190)->nullable()->after('role');
            });
        }
        $ali = DB::table('users')->where('username', 'engalmiskry')->whereNull('deleted_at')->first();
        if (! $ali) {
            return;                 // another database (a demo, the simulation): nothing to record
        }
        // SADJ-0001 was approved by Eng Ali himself (phase 1), so its phase 2 is recorded under Director Asha (username mrshajas) instead
        $asha = DB::table('users')->where('username', 'mrshajas')->whereNull('deleted_at')->first();
        $note = 'Recorded afterwards at the owner\'s instruction (approved before the two-approval rule).';
        foreach (DB::table('super_adjustments')->where('status', 'Approved')->whereNull('deleted_at')->orderBy('id')->get() as $sa) {
            if (DB::table('super_adjustment_approvals')->where('super_adjustment_id', $sa->id)->exists()) {
                continue;
            }
            $second = ($sa->reference === 'SADJ-0001' && $asha) ? $asha : $ali;
            $at = $sa->approved_at ?: $sa->updated_at;
            DB::table('super_adjustment_approvals')->insert([
                ['super_adjustment_id' => $sa->id, 'phase' => 1, 'user_id' => $sa->approved_by_user_id ?: $ali->id, 'role' => $sa->approved_by_role ?: 'Super Admin', 'note' => $note, 'approved_at' => $at, 'created_at' => now(), 'updated_at' => now()],
                ['super_adjustment_id' => $sa->id, 'phase' => 2, 'user_id' => $second->id, 'role' => 'Super Admin', 'note' => $note, 'approved_at' => $at, 'created_at' => now(), 'updated_at' => now()],
            ]);
            DB::table('activity_log')->insert(['account_id' => $sa->account_id ?: 1, 'log_name' => 'User', 'description' => 'Super Adjustment ' . $sa->reference . ': phase 1 and phase 2 approvals recorded afterwards (phase 2: ' . $second->name . ')', 'subject_type' => \App\Models\SuperAdjustment::class, 'subject_id' => $sa->id, 'properties' => json_encode(['note' => $note]), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('super_adjustment_approvals', 'note')) {
            DB::table('super_adjustment_approvals')->whereNotNull('note')->where('note', 'like', 'Recorded afterwards%')->delete();
        }
    }
};
