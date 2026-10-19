<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The SuperAdjustment ByPass (Profile page of Dr Hajas): switched ON for now with Director Asha (username mrshajas) giving phase 2. Only inserts the two settings
 * when they are not there yet, so a later change made on the Profile page is never overwritten, and does nothing where Director Asha is not an active Super Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        $asha = DB::table('users')->where('username', 'mrshajas')->whereNull('deleted_at')->where('is_active', 1)->first();
        if (! $asha || ! DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('roles.name', 'Super Admin')->where('model_has_roles.model_id', $asha->id)->where('model_has_roles.model_type', \App\Models\User::class)->exists()) {
            return;
        }
        $account = (int) ($asha->account_id ?: 1);
        foreach (['super_adjustment_bypass_active' => '1', 'super_adjustment_bypass_approver' => (string) $asha->id] as $k => $v) {
            if (! DB::table('settings')->where('tec_key', $k)->where('account_id', $account)->exists()) {
                DB::table('settings')->insert(['tec_key' => $k, 'tec_value' => $v, 'account_id' => $account]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('tec_key', ['super_adjustment_bypass_active', 'super_adjustment_bypass_approver'])->delete();
    }
};
