<?php

use App\Models\LabourWorkflowStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labour_workflow_steps', function (Blueprint $table) {
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

        // Seed every existing account with today's hardcoded defaults, the
        // same way material_request_workflow_steps does — softcoding this
        // must not change who can do what until a Super Admin edits a step.
        // LabourWorkflowStep::defaults() was removed when Labour access moved to permissions (the table is no longer
        // read), so on a fresh install there is nothing to seed.
        if (! method_exists(LabourWorkflowStep::class, 'defaults')) {
            return;
        }

        \App\Models\Account::all(['id'])->each(function ($account) {
            foreach (LabourWorkflowStep::defaults() as $stepKey => $default) {
                LabourWorkflowStep::firstOrCreate(
                    ['account_id' => $account->id, 'step_key' => $stepKey],
                    $default
                );
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labour_workflow_steps');
    }
};
