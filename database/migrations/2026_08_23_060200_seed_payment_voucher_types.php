<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the default Voucher Type bank in a fixed, deliberate order —
     * "Administration" first so it wins the tie while every row still has
     * use_count=1. The list otherwise grows purely from what users type on
     * the form (see PaymentVoucherController::rememberBank()).
     */
    private const TYPES = [
        'Administration', 'Staff Salary', 'Contractor Payment', 'Subcontractor Payment', 'Staff Loan',
        'Staff Reimbursement', 'Site Expenses', 'Petty Cash', 'Transport / Fuel', 'Rent / Lease', 'Utilities',
        'Professional Fees', 'Government / Statutory Payment', 'Retention Release', 'Purchase Payment',
        'Deposit / Advance', 'Refund', 'Other Payment',
    ];

    public function up(): void
    {
        foreach (self::TYPES as $text) {
            DB::table('payment_voucher_types')->updateOrInsert(
                ['text' => $text],
                ['use_count' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('payment_voucher_types')->whereIn('text', self::TYPES)->delete();
    }
};
