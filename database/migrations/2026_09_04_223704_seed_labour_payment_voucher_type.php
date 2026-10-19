<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payment_voucher_types')->updateOrInsert(
            ['text' => 'Labour Payment'],
            ['use_count' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        DB::table('payment_voucher_types')->where('text', 'Labour Payment')->delete();
    }
};
