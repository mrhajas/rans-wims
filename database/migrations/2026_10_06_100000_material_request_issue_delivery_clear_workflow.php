<?php

use App\Models\Account;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestNotificationRule;
use App\Models\MaterialRequestVisibilityRule;
use App\Models\MaterialRequestWorkflowStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Material Request workflow v2: Project Manager raises -> Super Admin approves/rejects -> Procurement sets
 * procedures + marks Partially/Fully Issued -> assigned Site Engineers confirm delivery per item -> Super Admin
 * Clears/Completes (locks the request). Adds the columns that flow needs and re-seeds the softcoded assignments
 * (who does what / sees what / is told what), all of which stay editable under Administration -> Material Request
 * Workflow. Additive only: no rows are deleted and no request data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_request_items', function (Blueprint $table) {
            if (! Schema::hasColumn('material_request_items', 'procedure_contact_id')) {
                $table->foreignId('procedure_contact_id')->nullable()->after('procedure')->constrained('contacts')->nullOnDelete();
            }
            if (! Schema::hasColumn('material_request_items', 'procedure_warehouse_id')) {
                $table->foreignId('procedure_warehouse_id')->nullable()->after('procedure_contact_id')->constrained('warehouses')->nullOnDelete();
            }
            if (! Schema::hasColumn('material_request_items', 'procedure_note')) {
                $table->string('procedure_note', 255)->nullable()->after('procedure_warehouse_id');
            }
            if (! Schema::hasColumn('material_request_items', 'delivery_status')) {
                $table->string('delivery_status', 24)->nullable()->after('procedure_note');
            }
            if (! Schema::hasColumn('material_request_items', 'delivered_by_user_id')) {
                $table->foreignId('delivered_by_user_id')->nullable()->after('delivery_status')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('material_request_items', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('delivered_by_user_id');
            }
        });

        Schema::table('material_request_notification_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('material_request_notification_rules', 'notify_site_engineers')) {
                $table->boolean('notify_site_engineers')->default(false)->after('notify_requester');
            }
        });

        Schema::table('material_request_visibility_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('material_request_visibility_rules', 'assigned_only')) {
                $table->boolean('assigned_only')->default(false)->after('own_only');
            }
        });

        $siteEngineerRoles = ['Site Engineers', 'Site Engineer', 'Engineers', 'Architect', 'Draftsman', 'Technician'];
        $visibleToSite = [
            MaterialRequest::STATUS_APPROVED,
            MaterialRequest::STATUS_PARTIALLY_ISSUED,
            MaterialRequest::STATUS_ISSUED,
            MaterialRequest::STATUS_CLEARED,
        ];

        Account::all(['id'])->each(function ($account) use ($siteEngineerRoles, $visibleToSite) {
            $defaults = MaterialRequestWorkflowStep::defaults();

            // New steps (never overwrites an existing assignment).
            foreach (['issue', 'receive', 'complete'] as $key) {
                MaterialRequestWorkflowStep::firstOrCreate(['account_id' => $account->id, 'step_key' => $key], $defaults[$key]);
            }
            // Re-seeded for the new flow: only Project Managers raise requests for now, and approval is a Super Admin job.
            foreach (['create', 'approve', 'clear'] as $key) {
                MaterialRequestWorkflowStep::updateOrCreate(['account_id' => $account->id, 'step_key' => $key], $defaults[$key]);
            }

            // Notification audiences for the new events + the reworked ones.
            // Only the audience columns exist at this point; the SMS / email columns arrive in a later migration.
            $rules = collect(MaterialRequestNotificationRule::defaults())->map(
                fn ($r) => collect($r)->only(['label', 'description', 'role_names', 'user_ids', 'notify_requester', 'notify_site_engineers'])->all()
            )->all();
            foreach (['partially_issued', 'issued', 'delivery_updated'] as $key) {
                MaterialRequestNotificationRule::firstOrCreate(['account_id' => $account->id, 'event_key' => $key], $rules[$key]);
            }
            foreach (['created', 'approved', 'rejected', 'cleared'] as $key) {
                MaterialRequestNotificationRule::updateOrCreate(['account_id' => $account->id, 'event_key' => $key], $rules[$key]);
            }

            // Site engineers only see requests of the projects/warehouses they are assigned to, from approval onwards.
            foreach ($siteEngineerRoles as $role) {
                MaterialRequestVisibilityRule::updateOrCreate(
                    ['account_id' => $account->id, 'role_name' => $role],
                    ['statuses' => $visibleToSite, 'own_only' => false, 'assigned_only' => true, 'cleared_only' => false]
                );
            }
            // "Cleared only" used to mean "procurement has set procedures"; it now means "completed", which would hide
            // a Project Manager's own pending requests from them.
            MaterialRequestVisibilityRule::where('account_id', $account->id)->whereIn('role_name', ['Project Manager', 'Project Managers'])->update(['cleared_only' => false]);
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: the added columns and seeded rows are harmless to leave in place.
    }
};
