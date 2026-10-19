<?php

use App\Support\TzPhone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every Tanzanian mobile number is kept as +255XXXXXXXXX: 0674020223, 255674020223, +255 674 020 223 and +2550674020223 all become
 * +255674020223. Covers the user's phone, the SMS-code number, staff mobile numbers and the SMS / WhatsApp details in Recipients.
 * A number that cannot be read as a Tanzanian mobile number is left exactly as it is (nothing is deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            DB::table('users')->whereNotNull('two_factor_phone')->where('two_factor_phone', '!=', '')->orderBy('id')->each(function ($u) {
                $n = TzPhone::normalize($u->two_factor_phone);
                if ($n && $n !== $u->two_factor_phone) {
                    DB::table('users')->where('id', $u->id)->update(['two_factor_phone' => $n]);
                }
            });
            if (Schema::hasColumn('users', 'extra_attributes')) {
                DB::table('users')->whereNotNull('extra_attributes')->orderBy('id')->each(function ($u) {
                    $extra = json_decode((string) $u->extra_attributes, true);
                    $phone = is_array($extra) ? ($extra['phone'] ?? null) : null;
                    $n = filled($phone) ? TzPhone::normalize((string) $phone) : null;
                    if ($n && $n !== $phone) {
                        $extra['phone'] = $n;
                        DB::table('users')->where('id', $u->id)->update(['extra_attributes' => json_encode($extra)]);
                    }
                });
            }
        }

        if (Schema::hasTable('staffs')) {
            DB::table('staffs')->whereNotNull('mobile_no')->where('mobile_no', '!=', '')->orderBy('id')->each(function ($s) {
                $n = TzPhone::normalize($s->mobile_no);
                if ($n && $n !== $s->mobile_no) {
                    DB::table('staffs')->where('id', $s->id)->update(['mobile_no' => $n]);
                }
            });
        }

        if (Schema::hasTable('wims_connect_recipient_channels')) {
            DB::table('wims_connect_recipient_channels')->whereIn('channel', ['sms', 'whatsapp'])->where('address', '!=', '')->orderBy('id')->each(function ($r) {
                $n = TzPhone::normalize($r->address);
                if ($n && $n !== $r->address) {
                    DB::table('wims_connect_recipient_channels')->where('id', $r->id)->update(['address' => $n]);
                }
            });
        }
    }

    public function down(): void
    {
        // the original spelling of each number is not kept
    }
};
