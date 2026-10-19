<?php

use App\Models\MaterialRequestWorkflowStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_request_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('step_key', 50);
            $table->string('label');
            $table->string('description', 500)->nullable();
            $table->json('role_names')->nullable();
            $table->json('user_ids')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'step_key']);
        });

        // Seed every existing account with today's hardcoded assignments —
        // "softcoding" this must not change a single thing about who can do
        // what until a Super Admin actually edits a step.
        \App\Models\Account::all(['id'])->each(function ($account) {
            foreach (MaterialRequestWorkflowStep::defaults() as $stepKey => $default) {
                MaterialRequestWorkflowStep::firstOrCreate(
                    ['account_id' => $account->id, 'step_key' => $stepKey],
                    $default
                );
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_request_workflow_steps');
    }
};
