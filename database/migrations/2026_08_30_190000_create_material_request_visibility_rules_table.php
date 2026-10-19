<?php

use App\Models\MaterialRequestVisibilityRule;
use App\Models\MaterialRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_request_visibility_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('role_name', 100);
            $table->json('statuses')->nullable(); // null/empty = every status
            $table->boolean('own_only')->default(false);
            $table->boolean('cleared_only')->default(false);
            $table->timestamps();

            $table->unique(['account_id', 'role_name']);
        });

        // Seeded to reproduce today's exact hardcoded visibility — nothing
        // changes for anyone until a Super Admin edits a rule. Admin/Super
        // Admin intentionally have no row: like every other check in this
        // module, they're an unconditional override, not a configurable rule.
        \App\Models\Account::all(['id'])->each(function ($account) {
            // Frozen snapshot of the original seed (the model no longer carries
            // role-name defaults: rules are now purely data-driven).
            $seed = [
                'Project Manager' => ['statuses' => null, 'own_only' => false, 'cleared_only' => false],
                'Project Managers' => ['statuses' => null, 'own_only' => false, 'cleared_only' => false],
                'Site Engineers' => ['statuses' => null, 'own_only' => true, 'cleared_only' => false],
                'Site Engineer' => ['statuses' => null, 'own_only' => true, 'cleared_only' => false],
                'Engineers' => ['statuses' => null, 'own_only' => true, 'cleared_only' => false],
                'Architect' => ['statuses' => null, 'own_only' => true, 'cleared_only' => false],
                'Draftsman' => ['statuses' => null, 'own_only' => true, 'cleared_only' => false],
                'Technician' => ['statuses' => null, 'own_only' => true, 'cleared_only' => false],
                'Procurement Manager' => ['statuses' => [MaterialRequest::STATUS_APPROVED, MaterialRequest::STATUS_PARTIALLY_ISSUED, MaterialRequest::STATUS_ISSUED, MaterialRequest::STATUS_CLOSED], 'own_only' => false, 'cleared_only' => false],
                'Procurement Manger' => ['statuses' => [MaterialRequest::STATUS_APPROVED, MaterialRequest::STATUS_PARTIALLY_ISSUED, MaterialRequest::STATUS_ISSUED, MaterialRequest::STATUS_CLOSED], 'own_only' => false, 'cleared_only' => false],
                'Store Manager' => ['statuses' => null, 'own_only' => false, 'cleared_only' => true],
            ];
            foreach ($seed as $roleName => $rule) {
                MaterialRequestVisibilityRule::firstOrCreate(
                    ['account_id' => $account->id, 'role_name' => $roleName],
                    $rule
                );
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_request_visibility_rules');
    }
};
