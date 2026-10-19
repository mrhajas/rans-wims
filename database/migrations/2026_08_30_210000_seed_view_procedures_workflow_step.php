<?php

use App\Models\MaterialRequestWorkflowStep;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $default = MaterialRequestWorkflowStep::defaults()[MaterialRequestWorkflowStep::STEP_VIEW_PROCEDURES];

        \App\Models\Account::all(['id'])->each(function ($account) use ($default) {
            MaterialRequestWorkflowStep::firstOrCreate(
                ['account_id' => $account->id, 'step_key' => MaterialRequestWorkflowStep::STEP_VIEW_PROCEDURES],
                $default
            );
        });
    }

    public function down(): void
    {
        MaterialRequestWorkflowStep::where('step_key', MaterialRequestWorkflowStep::STEP_VIEW_PROCEDURES)->delete();
    }
};
