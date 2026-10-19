<?php

use App\Support\Workflow\LegacyAlertMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/** Copies the Notification Center, Communication Rules, Email Alerts matrix and Salary notification rules into the module workflow pages. */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $r = LegacyAlertMigrator::run();
            Log::info('Legacy alerts copied into the workflow pages', $r);
        } catch (\Throwable $e) {
            Log::error('Legacy alerts could not be copied into the workflow pages', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // the workflow settings are kept; nothing to undo
    }
};
