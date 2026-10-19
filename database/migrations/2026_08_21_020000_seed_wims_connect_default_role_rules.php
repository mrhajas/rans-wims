<?php

use App\Models\WimsConnectRule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Sensible defaults for the four roles most involved in WIMS Connect
     * events: Super Admin, Procurement Manager, Project Manager, Site
     * Engineers. Every (role, event) pair below was checked against the
     * actual candidate-resolution logic in the listener/controller that
     * fires that event — a role only gets a default rule for an event
     * where that role can genuinely appear in the candidate set, so
     * nothing here is a dead rule waiting on data that never arrives.
     *
     * All channel="whatsapp" except the two flagged critical/anomaly
     * events, which default to SMS (see the analysis doc's gateway
     * assignment reasoning, section I) — harmless to seed now even before
     * the SMS gateway has real credentials, since CommunicationService
     * checks the gateway's own feature flag before ever sending.
     *
     * These are global-default role rules (scope_type='role', no
     * project/user narrowing) — a Super Admin can still add a per-project
     * or per-user override later; per the documented precedence model
     * (global < role < project < user), those will simply take
     * precedence over these defaults, not conflict with them.
     */
    public function up(): void
    {
        $rows = [
            // Super Admin
            ['role' => 'Super Admin', 'event' => 'material-request-submitted', 'channel' => 'whatsapp'],
            ['role' => 'Super Admin', 'event' => 'adjustment-finalized', 'channel' => 'whatsapp'],
            ['role' => 'Super Admin', 'event' => 'checkin-main-warehouse', 'channel' => 'whatsapp'],
            ['role' => 'Super Admin', 'event' => 'checkout-main-warehouse-anomaly', 'channel' => 'sms'],
            ['role' => 'Super Admin', 'event' => 'critical-low-stock', 'channel' => 'sms'],
            ['role' => 'Super Admin', 'event' => 'boq-overused', 'channel' => 'sms'],
            ['role' => 'Super Admin', 'event' => 'transfer-received', 'channel' => 'whatsapp'],
            ['role' => 'Super Admin', 'event' => 'reallocation-final-approval-needed', 'channel' => 'whatsapp'],

            // Procurement Manager
            ['role' => 'Procurement Manager', 'event' => 'material-request-approved', 'channel' => 'whatsapp'],
            ['role' => 'Procurement Manager', 'event' => 'checkin-main-warehouse', 'channel' => 'whatsapp'],
            ['role' => 'Procurement Manager', 'event' => 'critical-low-stock', 'channel' => 'whatsapp'],
            ['role' => 'Procurement Manager', 'event' => 'reallocation-allocation-needed', 'channel' => 'whatsapp'],

            // Project Manager
            ['role' => 'Project Manager', 'event' => 'reallocation-pm-clearance', 'channel' => 'whatsapp'],
            ['role' => 'Project Manager', 'event' => 'boq-slight-overuse', 'channel' => 'whatsapp'],
            ['role' => 'Project Manager', 'event' => 'boq-overused', 'channel' => 'whatsapp'],
            ['role' => 'Project Manager', 'event' => 'material-request-rejected', 'channel' => 'whatsapp'],
            ['role' => 'Project Manager', 'event' => 'material-request-approved', 'channel' => 'whatsapp'],

            // Site Engineers
            ['role' => 'Site Engineers', 'event' => 'transfer-dispatched', 'channel' => 'whatsapp'],
            ['role' => 'Site Engineers', 'event' => 'material-request-approved', 'channel' => 'whatsapp'],
            ['role' => 'Site Engineers', 'event' => 'material-request-rejected', 'channel' => 'whatsapp'],
            ['role' => 'Site Engineers', 'event' => 'reallocation-dispatch-needed', 'channel' => 'whatsapp'],
            ['role' => 'Site Engineers', 'event' => 'reallocation-changes-requested', 'channel' => 'whatsapp'],
            ['role' => 'Site Engineers', 'event' => 'issue-overdue', 'channel' => 'whatsapp'],
        ];

        foreach ($rows as $row) {
            WimsConnectRule::firstOrCreate(
                ['scope_type' => 'role', 'role_name' => $row['role'], 'event_key' => $row['event'], 'channel' => $row['channel']],
                ['is_enabled' => true, 'priority' => WimsConnectRule::computePriority(null, $row['role'], null)]
            );
        }
    }

    public function down(): void
    {
        WimsConnectRule::where('scope_type', 'role')
            ->whereIn('role_name', ['Super Admin', 'Procurement Manager', 'Project Manager', 'Site Engineers'])
            ->delete();
    }
};
