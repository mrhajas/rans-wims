<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A cancelled voucher keeps who cancelled it, when and why (until now only the activity log knew). */
    public function up(): void
    {
        if (Schema::hasTable('payment_vouchers') && ! Schema::hasColumn('payment_vouchers', 'cancel_reason')) {
            Schema::table('payment_vouchers', function (Blueprint $t) {
                $t->unsignedBigInteger('cancelled_by_user_id')->nullable()->after('approval_remarks');
                $t->timestamp('cancelled_at')->nullable()->after('cancelled_by_user_id');
                $t->text('cancel_reason')->nullable()->after('cancelled_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_vouchers', 'cancel_reason')) {
            Schema::table('payment_vouchers', fn (Blueprint $t) => $t->dropColumn(['cancelled_by_user_id', 'cancelled_at', 'cancel_reason']));
        }
    }
};
