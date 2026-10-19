<?php

use App\Models\ExecutiveReportSubscriber;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Seeds email_function_subscriptions so the new matrix starts out matching
     * exactly who receives each email today — no real recipient gains or loses
     * an email the moment this ships. Also grants the new
     * manage-email-notifications permission to Super Admin/Admin, matching the
     * PermissionRegistry convention established by the earlier permission-matrix work.
     */
    public function up(): void
    {
        $guard = 'web';

        Permission::query()->firstOrCreate(['name' => 'manage-email-notifications', 'guard_name' => $guard]);

        foreach (['Super Admin', 'Admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role && ! $role->hasPermissionTo('manage-email-notifications')) {
                $role->givePermissionTo('manage-email-notifications');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Plain table query: the WarehouseEmailSubscriber model was removed with the Email Alerts matrix, but this
        // migration must still run on a fresh install.
        $warehouseSubscriberUserIds = DB::table('warehouse_email_subscribers')
            ->where('active', true)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique();

        $executiveSubscriberUserIds = ExecutiveReportSubscriber::active()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique();

        $superAdminUserIds = User::role('Super Admin')->pluck('id')->unique();

        $subscriberFunctionKeys = [
            'checkin-completed',
            'checkout-completed',
            'transfer-completed',
            'adjustment-activity',
            'material-reallocation-stage-change',
            'material-request-lifecycle',
            'site-report-submitted',
        ];

        $now = now();
        $rows = [];

        foreach ($subscriberFunctionKeys as $key) {
            foreach ($warehouseSubscriberUserIds as $userId) {
                $rows[] = ['user_id' => $userId, 'function_key' => $key];
            }
        }

        foreach (['site-report-submitted-admin-copy', 'low-stock-alert-admin-digest'] as $key) {
            foreach ($superAdminUserIds as $userId) {
                $rows[] = ['user_id' => $userId, 'function_key' => $key];
            }
        }

        foreach ($executiveSubscriberUserIds as $userId) {
            $rows[] = ['user_id' => $userId, 'function_key' => 'executive-intelligence-report-weekly'];
        }

        $accountId = User::whereIn('id', array_column($rows, 'user_id'))->value('account_id');

        $seen = [];
        foreach ($rows as $row) {
            $dedupeKey = $row['user_id'] . '|' . $row['function_key'];
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            DB::table('email_function_subscriptions')->insertOrIgnore([
                'user_id' => $row['user_id'],
                'function_key' => $row['function_key'],
                'account_id' => $accountId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // The EmailFunctionRegistry class was removed with the Email Alerts matrix; this table only ever held its keys.
        DB::table('email_function_subscriptions')->delete();

        $permission = Permission::query()->where('name', 'manage-email-notifications')->first();
        $permission?->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
