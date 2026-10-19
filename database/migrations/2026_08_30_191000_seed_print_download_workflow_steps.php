<?php

use App\Models\MaterialRequestWorkflowStep;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = MaterialRequestWorkflowStep::defaults();

        \App\Models\Account::all(['id'])->each(function ($account) use ($defaults) {
            foreach ([MaterialRequestWorkflowStep::STEP_PRINT, MaterialRequestWorkflowStep::STEP_DOWNLOAD] as $stepKey) {
                MaterialRequestWorkflowStep::firstOrCreate(
                    ['account_id' => $account->id, 'step_key' => $stepKey],
                    $defaults[$stepKey]
                );
            }
        });
    }

    public function down(): void
    {
        MaterialRequestWorkflowStep::whereIn('step_key', [
            MaterialRequestWorkflowStep::STEP_PRINT,
            MaterialRequestWorkflowStep::STEP_DOWNLOAD,
        ])->delete();
    }
};
