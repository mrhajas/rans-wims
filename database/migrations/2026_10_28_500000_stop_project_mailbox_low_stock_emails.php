<?php

use App\Services\LowStock\LowStockConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner does not want each project's own mailbox (for example info@rans.co.tz) to get its nightly low-stock email: the Low Stock Alert
 * page's "also email each project's own address" switch is turned off for every account. It can be switched on again on that page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! class_exists(LowStockConfig::class)) {
            return;
        }
        foreach (DB::table('accounts')->pluck('id') as $accountId) {
            $cfg = LowStockConfig::get((int) $accountId);
            if (! empty($cfg['warehouse_email'])) {
                LowStockConfig::save(['warehouse_email' => false] + $cfg, (int) $accountId);
            }
        }
    }

    public function down(): void
    {
        // a setting: nothing to restore
    }
};
