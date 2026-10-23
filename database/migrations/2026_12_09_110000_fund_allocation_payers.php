<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A funds allocation sheet can have more than one payer. fund_allocation_payers holds all of them; fund_allocations.assigned_to_user_id stays as the first
 * (main) payer so the reports, the signatures and old links keep working. The payer each sheet already has is copied in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fund_allocation_payers')) {
            Schema::create('fund_allocation_payers', function (Blueprint $t) {
                $t->id();
                $t->foreignId('fund_allocation_id')->constrained('fund_allocations')->cascadeOnDelete();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->timestamps();
                $t->unique(['fund_allocation_id', 'user_id']);
            });
        }
        $rows = DB::table('fund_allocations')->whereNotNull('assigned_to_user_id')->get(['id', 'assigned_to_user_id']);
        foreach ($rows as $r) {
            DB::table('fund_allocation_payers')->updateOrInsert(['fund_allocation_id' => $r->id, 'user_id' => $r->assigned_to_user_id], ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_allocation_payers');
    }
};
