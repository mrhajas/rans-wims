<?php

use App\Models\WimsConnectRule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Gives the 23 default role rules seeded by
     * 2026_08_21_020000_seed_wims_connect_default_role_rules a human-readable
     * name, matched on the same (role, event, channel) key that migration
     * used — so a Super Admin sees "Notify Super Admin of new material
     * requests" in the Active Rules table instead of the raw event label.
     */
    public function up(): void
    {
        $rows = [
            // Super Admin
            ['role' => 'Super Admin', 'event' => 'material-request-submitted', 'channel' => 'whatsapp', 'name' => 'Notify Super Admin of new material requests'],
            ['role' => 'Super Admin', 'event' => 'adjustment-finalized', 'channel' => 'whatsapp', 'name' => 'Notify Super Admin when a stock adjustment is finalized'],
            ['role' => 'Super Admin', 'event' => 'checkin-main-warehouse', 'channel' => 'whatsapp', 'name' => 'Notify Super Admin of main warehouse check-ins'],
            ['role' => 'Super Admin', 'event' => 'checkout-main-warehouse-anomaly', 'channel' => 'sms', 'name' => 'Alert Super Admin of a checkout anomaly (SMS)'],
            ['role' => 'Super Admin', 'event' => 'critical-low-stock', 'channel' => 'sms', 'name' => 'Alert Super Admin of critical low stock (SMS)'],
            ['role' => 'Super Admin', 'event' => 'boq-overused', 'channel' => 'sms', 'name' => 'Alert Super Admin of BOQ overuse (SMS)'],
            ['role' => 'Super Admin', 'event' => 'transfer-received', 'channel' => 'whatsapp', 'name' => 'Notify Super Admin when a transfer is received'],
            ['role' => 'Super Admin', 'event' => 'reallocation-final-approval-needed', 'channel' => 'whatsapp', 'name' => 'Notify Super Admin a reallocation needs final approval'],

            // Procurement Manager
            ['role' => 'Procurement Manager', 'event' => 'material-request-approved', 'channel' => 'whatsapp', 'name' => 'Notify Procurement of approved material requests'],
            ['role' => 'Procurement Manager', 'event' => 'checkin-main-warehouse', 'channel' => 'whatsapp', 'name' => 'Notify Procurement of main warehouse check-ins'],
            ['role' => 'Procurement Manager', 'event' => 'critical-low-stock', 'channel' => 'whatsapp', 'name' => 'Alert Procurement of critical low stock'],
            ['role' => 'Procurement Manager', 'event' => 'reallocation-allocation-needed', 'channel' => 'whatsapp', 'name' => 'Notify Procurement a reallocation needs allocation'],

            // Project Manager
            ['role' => 'Project Manager', 'event' => 'reallocation-pm-clearance', 'channel' => 'whatsapp', 'name' => 'Notify Project Manager a reallocation needs clearance'],
            ['role' => 'Project Manager', 'event' => 'boq-slight-overuse', 'channel' => 'whatsapp', 'name' => 'Warn Project Manager of slight BOQ overuse'],
            ['role' => 'Project Manager', 'event' => 'boq-overused', 'channel' => 'whatsapp', 'name' => 'Alert Project Manager of BOQ overuse'],
            ['role' => 'Project Manager', 'event' => 'material-request-rejected', 'channel' => 'whatsapp', 'name' => 'Notify Project Manager of rejected material requests'],
            ['role' => 'Project Manager', 'event' => 'material-request-approved', 'channel' => 'whatsapp', 'name' => 'Notify Project Manager of approved material requests'],

            // Site Engineers
            ['role' => 'Site Engineers', 'event' => 'transfer-dispatched', 'channel' => 'whatsapp', 'name' => 'Notify Site Engineers when a transfer is dispatched'],
            ['role' => 'Site Engineers', 'event' => 'material-request-approved', 'channel' => 'whatsapp', 'name' => 'Notify Site Engineers of approved material requests'],
            ['role' => 'Site Engineers', 'event' => 'material-request-rejected', 'channel' => 'whatsapp', 'name' => 'Notify Site Engineers of rejected material requests'],
            ['role' => 'Site Engineers', 'event' => 'reallocation-dispatch-needed', 'channel' => 'whatsapp', 'name' => 'Notify Site Engineers a reallocation needs dispatch'],
            ['role' => 'Site Engineers', 'event' => 'reallocation-changes-requested', 'channel' => 'whatsapp', 'name' => 'Notify Site Engineers changes were requested on a reallocation'],
            ['role' => 'Site Engineers', 'event' => 'issue-overdue', 'channel' => 'whatsapp', 'name' => 'Alert Site Engineers of an overdue issue'],
        ];

        foreach ($rows as $row) {
            WimsConnectRule::where([
                'scope_type' => 'role',
                'role_name' => $row['role'],
                'event_key' => $row['event'],
                'channel' => $row['channel'],
            ])->update(['name' => $row['name']]);
        }
    }

    public function down(): void
    {
        WimsConnectRule::where('scope_type', 'role')
            ->whereIn('role_name', ['Super Admin', 'Procurement Manager', 'Project Manager', 'Site Engineers'])
            ->update(['name' => null]);
    }
};
