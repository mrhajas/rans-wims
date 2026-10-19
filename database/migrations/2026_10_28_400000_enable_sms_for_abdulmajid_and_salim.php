<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Abdulmajid (demajido@rans.co.tz) and Salim Nassor (salimiskiry@rans.co.tz) are Super Admins, so they are already in the audience of every
 * notice addressed to Super Admins (for example "Request Created"). An SMS only goes to a person whose mobile number is switched on in
 * Recipients, so this switches theirs on (the number stays as it is). Matched by login email: a database without them is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('users')->whereIn(DB::raw('LOWER(email)'), ['demajido@rans.co.tz', 'salimiskiry@rans.co.tz'])->whereNull('deleted_at')->pluck('id')->all();
        if ($ids) {
            DB::table('wims_connect_recipient_channels')->whereIn('user_id', $ids)->where('channel', 'sms')->where('address', '!=', '')->update(['is_enabled' => true, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // settings change: nothing to restore
    }
};
