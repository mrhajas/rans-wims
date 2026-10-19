<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrections the owner asked for after reviewing the online data (matched by the exact old values, so a database where they do not
 * apply is left alone):
 *  - Zaamil Nassor Salim (login nassorasha8@gmail.com) receives her alerts on asha@rans.co.tz, while Director Asha keeps
 *    nassorasha8@gmail.com as her Recipients email;
 *  - Ruqayya Haroub Salim's mobile number had one digit too many (+2556213655039): the last digit is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Director Asha (login asha@rans.co.tz) keeps nassorasha8@gmail.com; Zaamil (login nassorasha8@gmail.com) gets asha@rans.co.tz
        $zaamil = DB::table('users')->whereRaw('LOWER(email) = ?', ['nassorasha8@gmail.com'])->whereRaw('LOWER(name) LIKE ?', ['zaamil%'])->value('id');
        $asha = DB::table('users')->whereRaw('LOWER(email) = ?', ['asha@rans.co.tz'])->value('id');
        if ($zaamil && $asha) {
            DB::table('wims_connect_recipient_channels')->updateOrInsert(
                ['user_id' => $asha, 'channel' => 'email'],
                ['address' => 'nassorasha8@gmail.com', 'is_enabled' => true, 'auto_filled' => false, 'updated_at' => now(), 'created_at' => now()]
            );
            DB::table('wims_connect_recipient_channels')->updateOrInsert(
                ['user_id' => $zaamil, 'channel' => 'email'],
                ['address' => 'asha@rans.co.tz', 'is_enabled' => true, 'auto_filled' => false, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        // Ruqayya's number: drop the extra last digit on her staff record and on the contact details pulled from it
        $old = '+2556213655039';
        $new = '+255621365503';
        $userIds = DB::table('staffs')->where('mobile_no', $old)->whereRaw('LOWER(name) LIKE ?', ['ruqayya%'])->pluck('user_id')->filter()->all();
        DB::table('staffs')->where('mobile_no', $old)->whereRaw('LOWER(name) LIKE ?', ['ruqayya%'])->update(['mobile_no' => $new]);
        if ($userIds) {
            DB::table('wims_connect_recipient_channels')->whereIn('user_id', $userIds)->where('address', $old)->update(['address' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // data corrections: nothing to restore
    }
};
