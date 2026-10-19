<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adjustments get a real Pending / Approved / Rejected life: who rejected it, when and why, plus the approver's role
 * (e.g. "Super Admin") frozen at the moment of approval so the record keeps saying who signed it off even if roles
 * change later. Super Adjustments get the same approver-role snapshot. Additive only; existing rows keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adjustments', function (Blueprint $table) {
            if (! Schema::hasColumn('adjustments', 'rejected_by')) {
                $table->unsignedBigInteger('rejected_by')->nullable()->after('approved_at');
                $table->timestamp('rejected_at')->nullable()->after('rejected_by');
                $table->text('rejection_reason')->nullable()->after('rejected_at');
                $table->string('approved_by_role', 80)->nullable()->after('approved_by');
                $table->index('rejected_at');
            }
        });

        Schema::table('super_adjustments', function (Blueprint $table) {
            if (! Schema::hasColumn('super_adjustments', 'approved_by_role')) {
                $table->string('approved_by_role', 80)->nullable()->after('approved_by_user_id');
            }
        });

        // Backfill the approver role for records approved before this change (Super Admin wins when someone has several).
        $this->backfill('adjustments', 'approved_by');
        $this->backfill('super_adjustments', 'approved_by_user_id');
    }

    private function backfill(string $table, string $userColumn): void
    {
        $rows = DB::table($table)->whereNotNull($userColumn)->whereNull('approved_by_role')->select($userColumn . ' as uid')->distinct()->get();
        foreach ($rows as $row) {
            $names = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $row->uid)
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->pluck('roles.name');
            $role = $names->contains('Super Admin') ? 'Super Admin' : $names->first();
            if ($role) {
                DB::table($table)->where($userColumn, $row->uid)->whereNull('approved_by_role')->update(['approved_by_role' => $role]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('adjustments', function (Blueprint $table) {
            $table->dropIndex(['rejected_at']);
            $table->dropColumn(['rejected_by', 'rejected_at', 'rejection_reason', 'approved_by_role']);
        });
        Schema::table('super_adjustments', function (Blueprint $table) {
            $table->dropColumn('approved_by_role');
        });
    }
};
