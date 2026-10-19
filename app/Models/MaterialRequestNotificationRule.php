<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * "Who gets notified" for the Material Request lifecycle: one row per event, each with a role/user list plus a
 * "notify the requester" toggle (the requester isn't a role, so it can't live in role_names). Super Admin edits each
 * event's audience and messages on the Material Request Notification page.
 *
 * Deliberately NOT the mechanism for "whoever does the next step": that follows the step permissions on the Roles
 * screen and is resolved dynamically via MaterialRequestWorkflowStep::assignedUsers().
 */
class MaterialRequestNotificationRule extends Model
{
    protected $fillable = ['account_id', 'event_key', 'label', 'description', 'role_names', 'user_ids', 'notify_requester', 'notify_site_engineers', 'sms_enabled', 'email_enabled', 'sms_template', 'email_subject', 'email_body', 'email_show_details', 'email_show_items'];

    protected $casts = [
        'role_names' => 'array',
        'user_ids' => 'array',
        'notify_requester' => 'boolean',
        'notify_site_engineers' => 'boolean',
        'sms_enabled' => 'boolean',
        'email_enabled' => 'boolean',
        'email_show_details' => 'boolean',
        'email_show_items' => 'boolean',
    ];

    public const EVENT_CREATED = 'created';
    public const EVENT_UPDATED = 'updated';
    public const EVENT_DELETED = 'deleted';
    public const EVENT_STATUS_CHANGED = 'status_changed';
    public const EVENT_APPROVED = 'approved';
    public const EVENT_REJECTED = 'rejected';
    public const EVENT_PARTIALLY_ISSUED = 'partially_issued';
    public const EVENT_ISSUED = 'issued';
    public const EVENT_DELIVERY = 'delivery_updated';
    public const EVENT_CLEARED = 'cleared';
    public const EVENT_CLOSED = 'closed';

    /**
     * Default SMS / email text per event. Templates use the #tags from MaterialRequestMessageTags and are editable
     * under Material Request Notification -> SMS & Email. SMS text has no link (the gateway rejects them).
     */
    public static function messageDefaults(): array
    {
        $sms = fn (string $title, string $tail = '') => "🔔 WIMS - Material Request {$title}\nReference: #reference\nProject/Warehouse: #project\n" . $tail;

        $row = fn (bool $smsOn, bool $emailOn, string $smsText, string $subject, string $body) => [
            'sms_enabled' => $smsOn,
            'email_enabled' => $emailOn,
            'sms_template' => rtrim($smsText),
            'email_subject' => $subject,
            'email_body' => $body,
            'email_show_details' => true,
            'email_show_items' => true,
        ];

        return [
            self::EVENT_CREATED => $row(false, true, $sms('Submitted', "Requested by: #requestedby\nAwaiting approval."), 'Material Request #reference submitted', "#requestedby submitted material request #reference for #project.\nIt is waiting for approval."),
            self::EVENT_UPDATED => $row(false, true, $sms('Updated', "Updated by: #actor"), 'Material Request #reference updated', "#actor updated material request #reference (#project).\nRemarks: #remarks"),
            self::EVENT_DELETED => $row(false, true, $sms('Deleted', "Deleted by: #actor"), 'Material Request #reference deleted', "#actor deleted material request #reference (#project)."),
            self::EVENT_STATUS_CHANGED => $row(false, false, $sms('Status Changed', "New status: #status\nBy: #actor"), 'Material Request #reference is now #status', "Material request #reference (#project) is now #status.\nChanged by #actor.\nRemarks: #remarks"),
            self::EVENT_APPROVED => $row(false, true, $sms('Approved', "Approved by: #approvedby\nProcurement can now set the procedures.\nRemarks: #remarks"), 'Material Request #reference approved', "Material request #reference for #project was approved by #approvedby on #approvedat.\nRemarks: #remarks"),
            self::EVENT_REJECTED => $row(false, true, $sms('Rejected', "Rejected by: #rejectedby\nRemarks: #remarks"), 'Material Request #reference rejected', "Material request #reference for #project was rejected by #rejectedby on #rejectedat.\nRemarks: #remarks"),
            self::EVENT_PARTIALLY_ISSUED => $row(false, true, $sms('Partially Issued', "Partially issued by: #partiallyissuedby\nItems are on their way. Please confirm what arrives.\nRemarks: #remarks"), 'Material Request #reference partially issued', "Material request #reference for #project was marked Partially Issued by #partiallyissuedby on #partiallyissuedat.\nSite Engineers: please confirm what arrives.\nRemarks: #remarks"),
            self::EVENT_ISSUED => $row(false, true, $sms('Fully Issued', "Fully issued by: #issuedby\nReady to Clear / Complete.\nRemarks: #remarks"), 'Material Request #reference fully issued', "Material request #reference for #project was marked Fully Issued by #issuedby on #issuedat.\nIt is ready to Clear / Complete.\nRemarks: #remarks"),
            self::EVENT_DELIVERY => $row(false, true, $sms('Delivery', "Updated by: #actor\n#deliverysummary\nRemarks: #remarks"), 'Material Request #reference: delivery update', "#actor updated the delivery status of #reference (#project) on site.\nDelivery: #deliverysummary\nDelivered on site by: #deliveredby (#deliveredat)\nRemarks: #remarks"),
            self::EVENT_CLEARED => $row(false, true, $sms('Cleared', "Cleared by: #clearedby\nThe request is now complete and locked."), 'Material Request #reference cleared', "Material request #reference for #project was cleared / completed by #clearedby on #clearedat.\nIt is now locked.\nRemarks: #remarks"),
            self::EVENT_CLOSED => $row(false, true, $sms('Closed'), 'Material Request #reference closed', "Material request #reference for #project has been closed."),
        ];
    }

    /** Starting audiences for each event; every one of them is editable under Material Request Notification. */
    public static function defaults(): array
    {
        $row = fn (string $label, string $description, array $roles = [], bool $requester = false, bool $site = false) => [
            'label' => $label,
            'description' => $description,
            'role_names' => $roles,
            'user_ids' => [],
            'notify_requester' => $requester,
            'notify_site_engineers' => $site,
        ];

        $rows = [
            self::EVENT_CREATED => $row('Request Created', 'Notified when a Project Manager submits a request, so it can be approved or rejected.', ['Super Admin', 'Admin']),
            self::EVENT_UPDATED => $row('Request Updated', 'Notified when an existing request is edited.', ['Super Admin', 'Admin']),
            self::EVENT_DELETED => $row('Request Deleted', 'Notified when a request is deleted.', ['Super Admin', 'Admin']),
            self::EVENT_STATUS_CHANGED => $row('Status Changed (Any)', 'A general notice sent on every status transition, in addition to the specific notices below.', ['Super Admin', 'Admin']),
            self::EVENT_APPROVED => $row('Request Approved', 'The requester (Project Manager) and the Site Engineers assigned to the request\'s project / warehouse are told it is approved. Whoever holds the Procurement step is always notified separately.', [], true, true),
            self::EVENT_REJECTED => $row('Request Rejected', 'The requester (Project Manager) is told their request was rejected.', [], true),
            self::EVENT_PARTIALLY_ISSUED => $row('Partially Issued', 'Told when Procurement marks a request Partially Issued: items are on their way and Site Engineers can start confirming deliveries.', [], true, true),
            self::EVENT_ISSUED => $row('Fully Issued', 'Told when Procurement marks a request Fully Issued. Super Admins are told it is ready to Clear / Complete.', ['Super Admin'], true, true),
            self::EVENT_DELIVERY => $row('Delivery Confirmed on Site', 'Told when a Site Engineer saves delivery ticks (Partially / Fully Delivered) and remarks.', ['Super Admin', 'Procurement Manager', 'Procurement Manger'], true),
            self::EVENT_CLEARED => $row('Request Cleared / Completed', 'Told when a Super Admin clears / completes the request. After this the request is locked for everybody.', [], true, true),
            self::EVENT_CLOSED => $row('Request Closed (legacy)', 'Only used by older requests that were Closed before the Clear / Complete step existed.', [], true),
        ];

        $messages = self::messageDefaults();
        foreach ($rows as $key => $r) {
            $rows[$key] = $r + $messages[$key];
        }

        return $rows;
    }

    /** The channel switches and templates of a stored row, falling back to the defaults for anything left empty. */
    public static function messageFields(?self $row, ?array $default): array
    {
        $default ??= ['sms_enabled' => false, 'email_enabled' => false, 'sms_template' => '', 'email_subject' => '', 'email_body' => '', 'email_show_details' => true, 'email_show_items' => true];

        return [
            'sms_enabled' => $row ? (bool) $row->sms_enabled : (bool) $default['sms_enabled'],
            'email_enabled' => $row ? (bool) $row->email_enabled : (bool) $default['email_enabled'],
            'sms_template' => ($row && filled($row->sms_template)) ? $row->sms_template : $default['sms_template'],
            'email_subject' => ($row && filled($row->email_subject)) ? $row->email_subject : $default['email_subject'],
            'email_body' => ($row && filled($row->email_body)) ? $row->email_body : $default['email_body'],
            'email_show_details' => $row ? (bool) $row->email_show_details : (bool) $default['email_show_details'],
            'email_show_items' => $row ? (bool) $row->email_show_items : (bool) $default['email_show_items'],
        ];
    }

    /** The live rule for one event, auto-healing to defaults() if the row is somehow missing. */
    public static function ruleFor(int $accountId, string $eventKey): array
    {
        $row = static::where('account_id', $accountId)->where('event_key', $eventKey)->first();

        if ($row) {
            return [
                'role_names' => $row->role_names ?? [],
                'user_ids' => $row->user_ids ?? [],
                'notify_requester' => (bool) $row->notify_requester,
                'notify_site_engineers' => (bool) $row->notify_site_engineers,
            ] + self::messageFields($row, self::messageDefaults()[$eventKey] ?? null);
        }

        $default = self::defaults()[$eventKey] ?? null;

        return $default
            ? ['role_names' => $default['role_names'], 'user_ids' => $default['user_ids'], 'notify_requester' => $default['notify_requester'], 'notify_site_engineers' => $default['notify_site_engineers']] + self::messageFields(null, $default)
            : ['role_names' => [], 'user_ids' => [], 'notify_requester' => false, 'notify_site_engineers' => false];
    }

    /**
     * Every recipient for $eventKey on $materialRequest — role_names +
     * user_ids resolved to active User rows (minus $excludeUserId — matches
     * every original notify*() method's own where('id','!=',...) guard, e.g.
     * "don't tell the admin list about their own request if they happen to
     * hold that role"), PLUS the requester if notify_requester is on.
     *
     * $excludeUserId is applied ONLY to the role/user_ids audience, never to
     * the requester entry — the requester's own notice must always fire when
     * notify_requester is on, even in the edge case where they're also the
     * one $excludeUserId refers to (e.g. a Super Admin clearing their own
     * request). Getting this ordering wrong would silently swallow the
     * requester notification whenever $excludeUserId happened to equal the
     * requester's id.
     */
    public static function recipientsFor(int $accountId, string $eventKey, MaterialRequest $materialRequest, ?int $excludeUserId = null): Collection
    {
        $rule = self::ruleFor($accountId, $eventKey);
        $recipients = new Collection();

        if (! empty($rule['role_names'])) {
            $recipients = $recipients->merge(
                User::whereHas('roles', fn ($q) => $q->whereIn('name', $rule['role_names']))
                    ->where('account_id', $accountId)
                    ->where('is_active', true)
                    ->get()
            );
        }

        if (! empty($rule['user_ids'])) {
            $recipients = $recipients->merge(
                User::whereIn('id', $rule['user_ids'])->where('is_active', true)->get()
            );
        }

        $recipients = $recipients
            ->unique('id')
            ->when($excludeUserId, fn ($c) => $c->reject(fn ($u) => (int) $u->id === (int) $excludeUserId));

        if (! empty($rule['notify_site_engineers'])) {
            // Only the Site Engineers assigned to THIS request's project / warehouse / site.
            $recipients = $recipients->merge(
                MaterialRequestWorkflowStep::assignedUsersForWarehouse($accountId, MaterialRequestWorkflowStep::STEP_RECEIVE, $materialRequest->warehouse_id)
                    ->reject(fn ($u) => $excludeUserId && (int) $u->id === (int) $excludeUserId)
            );
        }

        if ($rule['notify_requester'] && $materialRequest->user) {
            $recipients->push($materialRequest->user);
        }

        return $recipients->unique('id')->values();
    }

    /** For the admin UI — $onlyRoleNames restricts role checkboxes to real, currently-existing roles. */
    public static function forAccount(int $accountId, ?array $onlyRoleNames = null): array
    {
        $rows = static::where('account_id', $accountId)->get()->keyBy('event_key');
        $result = [];

        foreach (self::defaults() as $eventKey => $default) {
            $row = $rows->get($eventKey);
            $roleNames = $row ? ($row->role_names ?? []) : $default['role_names'];

            if ($onlyRoleNames !== null) {
                $roleNames = array_values(array_intersect($roleNames, $onlyRoleNames));
            }

            $result[$eventKey] = [
                'event_key' => $eventKey,
                'label' => $row->label ?? $default['label'],
                'description' => $row->description ?? $default['description'],
                'role_names' => $roleNames,
                'user_ids' => $row->user_ids ?? $default['user_ids'],
                'notify_requester' => $row ? (bool) $row->notify_requester : $default['notify_requester'],
                'notify_site_engineers' => $row ? (bool) $row->notify_site_engineers : $default['notify_site_engineers'],
            ] + self::messageFields($row, $default);
        }

        return $result;
    }
}
