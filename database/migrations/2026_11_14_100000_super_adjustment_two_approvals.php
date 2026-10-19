<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Super Adjustment: two Super Admin approvals (the rule is a setting: one or two). Each approval is a row here with its phase, the person, their role,
 * when and from where; a row that no longer stands (the record was edited, rejected or put back to Draft) is voided, never deleted, so the history stays.
 * The status gets a new value "Awaiting Phase 2" (first approval given, stock not changed yet), so the column is made wider.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('super_adjustment_approvals')) {
            Schema::create('super_adjustment_approvals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('super_adjustment_id')->index();
                $t->unsignedTinyInteger('phase');
                $t->unsignedBigInteger('user_id');
                $t->string('role', 60)->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->string('ip_address', 64)->nullable();
                $t->timestamp('voided_at')->nullable();
                $t->string('voided_reason', 190)->nullable();
                $t->timestamps();
                $t->index(['super_adjustment_id', 'voided_at']);
            });
        }
        if (Schema::hasTable('super_adjustments')) {
            DB::statement('ALTER TABLE super_adjustments MODIFY status VARCHAR(40) NOT NULL DEFAULT \'Draft\'');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('super_adjustment_approvals');
    }
};
