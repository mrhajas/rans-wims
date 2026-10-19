<?php

namespace App\Support;

class CommunicationEventRegistry
{
    /**
     * Canonical list of every event WIMS Connect can send external
     * communication for. This is the single source of truth: it drives the
     * Communication Rule Builder, the Recipients preview, and Delivery Logs
     * filters — no code should invent an event key that isn't listed here.
     *
     * `hook` records whether the event already has a real trigger point to
     * attach to in the existing codebase ('existing' — reuses a Laravel
     * event/listener that already fires) or needs new detection logic
     * ('new' — e.g. BOQ threshold-crossing has no existing hook at all,
     * since BOQ compliance is computed live on report view, not on a
     * transaction). This isn't cosmetic: it's what tells the wiring phase
     * whether to attach a listener or build one from scratch. See the WIMS
     * Connect analysis doc, section G, for the full reasoning per event.
     */
    public static function ruleEvents(): array
    {
        return [
            'transfer-dispatched' => [
                'label' => __('Transfer Dispatched'),
                'category' => __('Warehouse'),
                'icon' => 'truck',
                'priority' => 'high',
                'hook' => 'existing',
                'description' => __('A transfer out of a Main Warehouse to a project/site warehouse is finalized.'),
            ],
            'transfer-received' => [
                'label' => __('Transfer Received'),
                'category' => __('Warehouse'),
                'icon' => 'in',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('The destination site confirms receipt of a transfer, via the "Mark as Received" action on the transfer\'s page.'),
            ],
            'checkin-main-warehouse' => [
                'label' => __('Main Warehouse Checkin Completed'),
                'category' => __('Warehouse'),
                'icon' => 'in',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('Stock is checked into a warehouse whose status is Main Warehouse.'),
            ],
            'checkout-main-warehouse-anomaly' => [
                'label' => __('Checkout Recorded at Main Warehouse'),
                'category' => __('Warehouse'),
                'icon' => 'exclamation',
                'priority' => 'high',
                'hook' => 'new',
                'description' => __('A checkout happened directly from a Main Warehouse, normally material should only leave via Transfer. Flagged to admins as a process anomaly, not sent as a routine confirmation.'),
            ],
            'adjustment-finalized' => [
                'label' => __('Adjustment Finalized'),
                'category' => __('Warehouse'),
                'icon' => 'adjustments',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('A Damage, Addition, or Subtraction adjustment is taken out of draft.'),
            ],
            'critical-low-stock' => [
                'label' => __('Critical / Out-of-Stock'),
                'category' => __('Warehouse'),
                'icon' => 'exclamation',
                'priority' => 'critical',
                'hook' => 'existing',
                'description' => __('An item drops to or below its low-stock threshold. Reuses the existing Low Stock Alert resolver.'),
            ],
            'material-request-submitted' => [
                'label' => __('Material Request Submitted'),
                'category' => __('Material Request'),
                'icon' => 'doc-text',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('A new material request is created, pending approval.'),
            ],
            'material-request-approved' => [
                'label' => __('Material Request Approved'),
                'category' => __('Material Request'),
                'icon' => 'tick',
                'priority' => 'high',
                'hook' => 'existing',
                'description' => __('A material request status changes to Approved.'),
            ],
            'material-request-rejected' => [
                'label' => __('Material Request Rejected'),
                'category' => __('Material Request'),
                'icon' => 'trash',
                'priority' => 'high',
                'hook' => 'new',
                'description' => __('A material request is rejected by the approver.'),
            ],
            'material-request-partially-issued' => [
                'label' => __('Material Request Partially Issued'),
                'category' => __('Material Request'),
                'icon' => 'truck',
                'priority' => 'high',
                'hook' => 'new',
                'description' => __('Procurement marks a material request Partially Issued: part of the items are on their way to site.'),
            ],
            'material-request-issued' => [
                'label' => __('Material Request Fully Issued'),
                'category' => __('Material Request'),
                'icon' => 'truck',
                'priority' => 'high',
                'hook' => 'new',
                'description' => __('Procurement marks a material request Fully Issued; it is then ready for a Super Admin to Clear / Complete.'),
            ],
            'material-request-delivery' => [
                'label' => __('Material Request Delivery Confirmed on Site'),
                'category' => __('Material Request'),
                'icon' => 'tick',
                'priority' => 'standard',
                'hook' => 'new',
                'description' => __('A Site Engineer saves delivery ticks (Partially / Fully Delivered) and remarks on a material request.'),
            ],
            'material-request-cleared' => [
                'label' => __('Material Request Cleared'),
                'category' => __('Material Request'),
                'icon' => 'chip',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('A Super Admin clears / completes a Fully Issued material request. It is locked from then on.'),
            ],
            'reallocation-pm-clearance' => [
                'label' => __('Reallocation - PM Clearance Needed'),
                'category' => __('Material Reallocation'),
                'icon' => 'refresh',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('A reallocation is submitted and awaiting Project Manager clearance.'),
            ],
            'reallocation-allocation-needed' => [
                'label' => __('Reallocation - Allocation Needed'),
                'category' => __('Material Reallocation'),
                'icon' => 'refresh',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('A cleared reallocation is awaiting Procurement warehouse allocation.'),
            ],
            'reallocation-dispatch-needed' => [
                'label' => __('Reallocation - Dispatch Needed'),
                'category' => __('Material Reallocation'),
                'icon' => 'truck',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('Stock is allocated; the owning Site Engineer can prepare dispatch.'),
            ],
            'reallocation-final-approval-needed' => [
                'label' => __('Reallocation - Final Approval Needed'),
                'category' => __('Material Reallocation'),
                'icon' => 'clip',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('Warehouse receiving is recorded; awaiting final approval to move stock.'),
            ],
            'reallocation-completed' => [
                'label' => __('Reallocation Completed'),
                'category' => __('Material Reallocation'),
                'icon' => 'tick',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('Final approval is granted and stock has moved.'),
            ],
            'reallocation-changes-requested' => [
                'label' => __('Reallocation - Changes Requested'),
                'category' => __('Material Reallocation'),
                'icon' => 'exclamation',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('The requester needs to revise and resubmit.'),
            ],
            'reallocation-rejected' => [
                'label' => __('Reallocation Rejected'),
                'category' => __('Material Reallocation'),
                'icon' => 'trash',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('The reallocation request was rejected.'),
            ],
            'reallocation-cancelled' => [
                'label' => __('Reallocation Cancelled'),
                'category' => __('Material Reallocation'),
                'icon' => 'trash',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('The requester cancelled their own reallocation request.'),
            ],
            'reallocation-receiving-needed' => [
                'label' => __('Reallocation - Receiving Needed'),
                'category' => __('Material Reallocation'),
                'icon' => 'in',
                'priority' => 'high',
                'hook' => 'existing',
                'description' => __('A shipment is dispatched and awaiting Store Manager receiving.'),
            ],
            'boq-slight-overuse' => [
                'label' => __('BOQ Slight Overuse (>80%)'),
                'category' => __('Project'),
                'icon' => 'chart-bar',
                'priority' => 'standard',
                'hook' => 'new',
                'description' => __('Issued quantity crosses 80% of a BOQ baseline. No existing trigger, compliance is a live report today, not event-driven.'),
            ],
            'boq-overused' => [
                'label' => __('BOQ Overused (>110%)'),
                'category' => __('Project'),
                'icon' => 'exclamation',
                'priority' => 'critical',
                'hook' => 'new',
                'description' => __('Issued quantity exceeds 110% of a BOQ baseline.'),
            ],
            'issue-overdue' => [
                'label' => __('Issue Overdue'),
                'category' => __('Project'),
                'icon' => 'clock',
                'priority' => 'standard',
                'hook' => 'new',
                'description' => __('An issued-material transaction passes its expected return date without being returned.'),
            ],
            'task-manager-assigned' => [
                'label' => __('Task Assigned'),
                'category' => __('Task Manager'),
                'icon' => 'users',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('A task is assigned to a user. "Who" is decided by Task Manager\'s own Notification Settings page, not a WIMS Connect rule, this entry exists for the Delivery Log.'),
            ],
            'task-manager-status-changed' => [
                'label' => __('Task Status Changed'),
                'category' => __('Task Manager'),
                'icon' => 'refresh',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('A task moves to a different status/column. "Who" is decided by Task Manager\'s own Notification Settings page.'),
            ],
            'task-manager-commented' => [
                'label' => __('Task Comment Added'),
                'category' => __('Task Manager'),
                'icon' => 'sms',
                'priority' => 'standard',
                'hook' => 'existing',
                'description' => __('Someone comments on a task. "Who" is decided by Task Manager\'s own Notification Settings page.'),
            ],
        ];
    }

    /**
     * Every event WIMS Connect can send for: the ones whose audience is set by WIMS Connect Rules (ruleEvents) and the ones whose
     * audience is decided by the module's own settings page (managedEvents, each with a `managed_in` label + route). All of them
     * send through the same gateway to each person's number on WIMS Connect > Recipients, and all of them appear in the Delivery Log.
     */
    public static function events(): array
    {
        // an entry that exists in both (a module that used to be rule-driven) is treated as managed by the module
        return array_merge(self::ruleEvents(), self::managedEvents());
    }

    /** Keys whose audience is set by WIMS Connect Rules or a Recipient's own profile (the only ones those two pages can create rules for). */
    public static function ruleEventKeys(): array
    {
        return array_values(array_diff(array_keys(self::ruleEvents()), array_keys(self::managedEvents())));
    }

    public static function managedEvents(): array
    {
        $out = [];
        $add = function (string $key, string $category, string $icon, string $label, string $description, string $where, string $route, string $priority = 'standard') use (&$out) {
            $out[$key] = [
                'label' => $label, 'category' => $category, 'icon' => $icon, 'priority' => $priority, 'hook' => 'existing',
                'description' => $description, 'managed_in' => ['label' => $where, 'route' => $route],
            ];
        };

        // Material Requests: the notification page's SMS & Email tab
        $mrCat = __('Material Request');
        $mrPage = __('Material Request Notification');
        foreach ([
            'created' => ['doc-text', __('Request Created'), __('A material request is submitted.')],
            'updated' => ['edit', __('Request Updated'), __('A material request is edited.')],
            'deleted' => ['trash', __('Request Deleted'), __('A material request is deleted.')],
            'status-changed' => ['refresh', __('Status Changed (Any)'), __('A material request moves to another status.')],
            'approved' => ['tick', __('Request Approved'), __('A material request is approved.')],
            'rejected' => ['trash', __('Request Rejected'), __('A material request is rejected.')],
            'partially-issued' => ['truck', __('Partially Issued'), __('Procurement marks a request Partially Issued.')],
            'issued' => ['truck', __('Fully Issued'), __('Procurement marks a request Fully Issued.')],
            'delivery-updated' => ['tick', __('Delivery Confirmed on Site'), __('A Site Engineer saves delivery ticks and remarks.')],
            'cleared' => ['chip', __('Request Cleared / Completed'), __('A Super Admin clears / completes a request.')],
            'closed' => ['clock', __('Request Closed (legacy)'), __('An older request that was Closed before the Clear / Complete step existed.')],
        ] as $k => [$icon, $label, $desc]) {
            $add('material-request-' . $k, $mrCat, $icon, $mrCat . ' - ' . $label, $desc, $mrPage, 'material-requests.workflow', 'high');
        }
        // older keys from before the workflow page existed; kept so old Delivery Log entries still have a name
        foreach (['material-request-submitted', 'material-request-delivery'] as $legacy) {
            $old = self::ruleEvents()[$legacy] ?? null;
            if ($old) {
                $out[$legacy] = $old + ['managed_in' => ['label' => $mrPage, 'route' => 'material-requests.workflow']];
                $out[$legacy]['label'] .= ' ' . __('(older entry)');
            }
        }

        // Task Manager
        $tmCat = __('Task Manager');
        $tmPage = __('Task Manager Settings');
        $add('task-manager-assigned', $tmCat, 'users', __('Task Assigned'), __('A task is assigned to a user.'), $tmPage, 'task-manager.settings');
        $add('task-manager-status-changed', $tmCat, 'refresh', __('Task Status Changed'), __('A task moves to a different status / column.'), $tmPage, 'task-manager.settings');
        $add('task-manager-commented', $tmCat, 'sms', __('Task Comment Added'), __('Someone comments on a task.'), $tmPage, 'task-manager.settings');
        $add('task-manager-due-reminder', $tmCat, 'clock', __('Task Due Reminder'), __('A task is about to be due, or is overdue (scheduled).'), $tmPage, 'task-manager.settings');
        $add('task-manager-superadmin-alert-assigned', $tmCat, 'exclamation', __('Superadmin Alert: Task Assigned'), __('Super Admins who created or hold the task are told it was assigned.'), $tmPage, 'task-manager.settings');
        $add('task-manager-superadmin-alert-status_changed', $tmCat, 'exclamation', __('Superadmin Alert: Task Status Changed'), __('Super Admins who created or hold the task are told its status changed.'), $tmPage, 'task-manager.settings');

        // Low Stock Alert page
        $add('low-stock-alert', __('Warehouse'), 'exclamation', __('Low Stock Alert (daily digest)'), __('The daily list of items that are out of stock or low, at the time set on the Low Stock Alert page.'), __('Low Stock Alert'), 'low-stock-alert.index', 'critical');

        // Site Meetings
        $smCat = __('Site Meetings');
        foreach (\App\Services\SiteMeetings\SiteMeetingSettings::EVENTS as $k => $def) {
            $add('site_meeting_' . $k, $smCat, 'calendar', __($def['label']), __($def['hint']), __('Site Meetings Settings'), 'site-meetings.settings');
        }

        // Salary
        $salCat = __('Salary');
        $add('salary-paid', $salCat, 'cash', __('Salary Paid'), __("The employee's payment SMS when their salary is paid in full."), __('Salary Settings'), 'salary.settings.index');
        $add('salary-partially-paid', $salCat, 'cash', __('Partial Salary Payment'), __("The employee's payment SMS when a partial payment is recorded."), __('Salary Settings'), 'salary.settings.index');

        // Case Tracking
        $ctCat = __('Case Tracking');
        foreach ([
            'submitted' => __('Case Submitted'), 'in-review' => __('Case In Review'), 'in-progress' => __('Case In Progress'), 'returned' => __('Case Returned for Correction'),
            'resolved' => __('Case Resolved'), 'reopened' => __('Case Reopened'), 'closed' => __('Case Closed'), 'assigned' => __('Case Assigned'), 'commented' => __('New Comment on Case'),
        ] as $k => $label) {
            $add('case-tracking-' . $k, $ctCat, 'clip', $label, __('Sent when the "Also send an SMS" box of this event is ticked in Case Tracking settings.'), __('Case Tracking Settings'), 'case-tracking.settings');
        }

        // Every module's workflow page: the log key of a notice is "<module>-<event>" (underscores as hyphens)
        foreach (\App\Support\Workflow\WorkflowModules::all() as $mKey => $m) {
            foreach ($m['events'] as $eKey => $e) {
                $out[$mKey . '-' . str_replace('_', '-', $eKey)] = [
                    'label' => $e['label'], 'category' => $m['title'], 'icon' => $e['icon'] ?? 'bell', 'priority' => 'standard', 'hook' => 'existing',
                    'description' => $e['description'], 'managed_in' => ['label' => $m['title'], 'route' => $m['page_route']],
                ];
            }
        }

        return $out;
    }

    public static function allEventKeys(): array
    {
        return array_keys(self::events());
    }

    public static function categories(): array
    {
        return collect(self::events())->pluck('category')->unique()->values()->all();
    }
}
