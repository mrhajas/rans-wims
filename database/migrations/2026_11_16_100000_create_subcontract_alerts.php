<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Subcontract Alerts: one row per unresolved condition (a negative balance, a request waiting too long ...), with who looked at it and how it was dealt with. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subcontract_alerts')) {
            Schema::create('subcontract_alerts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->default(1);
                $t->string('reference', 30)->unique();
                $t->string('dedupe_key', 120)->index();
                $t->string('category', 20)->index();
                $t->string('type', 40);
                $t->string('severity', 10)->default('medium');
                $t->string('title', 220);
                $t->text('description')->nullable();
                $t->unsignedBigInteger('agreement_id')->nullable()->index();
                $t->unsignedBigInteger('contact_id')->nullable();
                $t->string('subject_type', 30)->nullable();
                $t->unsignedBigInteger('subject_id')->nullable();
                $t->decimal('amount', 18, 2)->nullable();
                $t->string('currency', 3)->nullable();
                $t->string('trigger', 20)->default('sweep');          // sweep = raised by the scheduled check and closed by it when the condition goes away; event = raised by something someone did
                $t->string('status', 14)->default('open')->index();    // open | acknowledged | resolved
                $t->unsignedInteger('occurrences')->default(1);
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamp('read_at')->nullable();
                $t->unsignedBigInteger('assigned_to')->nullable();
                $t->unsignedBigInteger('acknowledged_by')->nullable();
                $t->timestamp('acknowledged_at')->nullable();
                $t->unsignedBigInteger('resolved_by')->nullable();
                $t->timestamp('resolved_at')->nullable();
                $t->text('resolution_notes')->nullable();
                $t->timestamp('escalated_at')->nullable();
                $t->timestamps();
            });
        }
        if (Schema::hasTable('permissions')) {
            foreach (['read-alerts-subcontracts', 'resolve-alerts-subcontracts'] as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subcontract_alerts');
    }
};
