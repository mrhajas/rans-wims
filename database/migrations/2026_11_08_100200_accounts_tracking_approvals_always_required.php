<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Accounts Tracking: no approval rule may ask for zero approvals any more (the service also never accepts fewer than one). */
return new class extends Migration
{
    protected $connection = 'accounts';

    public function up(): void
    {
        if (Schema::connection('accounts')->hasTable('acct_approval_rules')) {
            DB::connection('accounts')->table('acct_approval_rules')->where('approvals_required', '<', 1)->update(['approvals_required' => 1]);
        }
    }

    public function down(): void
    {
    }
};
