<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The steps of the Material Request workflow and the PERMISSION that gates each one.
 *
 * Access is assigned in ONE place only: Roles -> (role) -> Material Requests. This class used to read a "who does
 * what" table (material_request_workflow_steps); that table is no longer consulted (the migration
 * 2026_10_28_100000_material_requests_use_permissions copied its content into role permissions and the table is
 * kept only as history). isAllowed() is now simply "does the user hold the step's permission", and Super Admin
 * passes through the Gate::before in AuthServiceProvider like everywhere else in the app.
 *
 * The workflow's SHAPE (the steps below and the fixed statuses in MaterialRequest::statuses()) stays fixed.
 */
class MaterialRequestWorkflowStep extends Model
{
    protected $fillable = ['account_id', 'step_key', 'label', 'description', 'role_names', 'user_ids'];

    protected $casts = [
        'role_names' => 'array',
        'user_ids' => 'array',
    ];

    public const STEP_CREATE = 'create';
    public const STEP_APPROVE = 'approve';
    public const STEP_CLEAR = 'clear';
    /** Settings key holding how strictly Clear / Complete checks that every item has been delivered: allow | warn | block. */
    public const CLEAR_CHECK_SETTING = 'material_request_clear_delivery_check';
    public const CLEAR_CHECK_MODES = ['allow', 'warn', 'block'];

    public const STEP_CLOSE = 'close'; // legacy (Closed status); no longer part of the active flow
    public const STEP_ISSUE = 'issue';
    public const STEP_RECEIVE = 'receive';
    public const STEP_COMPLETE = 'complete';
    public const STEP_PRINT = 'print';
    public const STEP_DOWNLOAD = 'download';
    public const STEP_VIEW_PROCEDURES = 'view_procedures';

    /**
     * HISTORICAL: the role assignments the old "who does what" table was seeded with. Nothing reads this at runtime any
     * more (access is by permission); it stays only because the older seeding migrations call it on a fresh install.
     */
    public static function defaults(): array
    {
        return [
            self::STEP_CREATE => [
                'label' => 'Create Request',
                'description' => 'Who can raise a new material request (for now: Project Managers).',
                'role_names' => ['Project Manager', 'Project Managers', 'Admin'],
                'user_ids' => [],
            ],
            self::STEP_APPROVE => [
                'label' => 'Approve / Reject',
                'description' => 'Who can approve or reject a request that is Pending Approval (the Approve / Reject buttons at the top of the request).',
                'role_names' => ['Super Admin'],
                'user_ids' => [],
            ],
            self::STEP_CLEAR => [
                'label' => 'Procurement (set procedures)',
                'description' => 'Who sets the procurement procedure of each item (supplier, transfer from site, other) once a request is approved.',
                'role_names' => ['Procurement Manager', 'Procurement Manger'],
                'user_ids' => [],
            ],
            self::STEP_ISSUE => [
                'label' => 'Mark Partially / Fully Issued',
                'description' => 'Who can change an approved request to Partially Issued or Fully Issued (buttons at the top of the request).',
                'role_names' => ['Procurement Manager', 'Procurement Manger'],
                'user_ids' => [],
            ],
            self::STEP_RECEIVE => [
                'label' => 'Confirm Delivery (Site)',
                'description' => 'Who can tick items as Partially / Fully Delivered and add remarks once a request is Partially or Fully Issued. They must also be assigned to the request\'s Project / Warehouse / Site (Administrators are exempt from that).',
                'role_names' => User::SITE_ENGINEER_ROLES,
                'user_ids' => [],
            ],
            self::STEP_COMPLETE => [
                'label' => 'Clear / Complete',
                'description' => 'Who can clear / complete a Fully Issued request. This locks it: nobody can change it afterwards.',
                'role_names' => ['Super Admin'],
                'user_ids' => [],
            ],
            self::STEP_PRINT => [
                'label' => 'Print',
                'description' => 'Who can print a request (in addition to whoever can view it).',
                'role_names' => ['Project Manager', 'Project Managers', 'Procurement Manager', 'Procurement Manger', 'Store Manager'],
                'user_ids' => [],
            ],
            self::STEP_DOWNLOAD => [
                'label' => 'Download',
                'description' => 'Who can download a request as PDF (in addition to whoever can view it).',
                'role_names' => ['Project Manager', 'Project Managers', 'Procurement Manager', 'Procurement Manger', 'Store Manager'],
                'user_ids' => [],
            ],
            self::STEP_VIEW_PROCEDURES => [
                'label' => 'View Procedures (Read-Only)',
                'description' => "Who can see each item's procurement procedure once the request has been cleared, without being able to edit it. Whoever holds the Clear step above can already view AND edit. This is for everyone else who just needs visibility.",
                'role_names' => ['Project Manager', 'Project Managers'],
                'user_ids' => [],
            ],
        ];
    }

    /** Step key -> the permission that lets a user perform it. The ONE place this mapping lives. */
    public const STEP_PERMISSIONS = [
        self::STEP_CREATE => 'create-material-requests',
        self::STEP_APPROVE => 'approve-material-requests',
        self::STEP_CLEAR => 'procure-material-requests',
        self::STEP_ISSUE => 'issue-material-requests',
        self::STEP_RECEIVE => 'receive-material-requests',
        self::STEP_COMPLETE => 'complete-material-requests',
        self::STEP_PRINT => 'print-material-requests',
        self::STEP_DOWNLOAD => 'download-material-requests',
        self::STEP_VIEW_PROCEDURES => 'view-procedures-material-requests',
    ];

    /** Permissions of the Quick path (not workflow steps of the normal flow, so they are not in STEP_PERMISSIONS). */
    public const PERMISSION_QUICK_CREATE = 'quick-material-requests';
    public const PERMISSION_QUICK_APPROVE = 'quick-approve-material-requests';

    /** The permission for a step, or null for a step that no longer exists in the active flow (the legacy "close"). */
    public static function permissionFor(string $stepKey): ?string
    {
        return self::STEP_PERMISSIONS[$stepKey] ?? null;
    }

    /** True when the user holds the permission of $stepKey (Super Admin always does, through the global Gate::before). */
    public static function isAllowed(User $user, string $stepKey): bool
    {
        $permission = self::permissionFor($stepKey);

        return $permission !== null && $user->can($permission);
    }

    /**
     * Every active user who holds the permission of $stepKey, directly or through a role. Super Admin is included
     * (it holds every permission). Used to tell the next owner "it is your turn", not to decide who may act.
     * $includeSuperAdmin = false limits it to users who were actually given the permission.
     */
    public static function assignedUsers(int $accountId, string $stepKey, ?bool $includeSuperAdmin = null): \Illuminate\Support\Collection
    {
        // Super Admin was only ever told about the two steps it was assigned (approve, clear / complete); for the other steps it is told
        // only when it has been given the permission explicitly, so the "it is your turn" notices stay as they were
        $includeSuperAdmin ??= in_array($stepKey, [self::STEP_APPROVE, self::STEP_COMPLETE], true);

        return self::usersWithPermission($accountId, self::permissionFor($stepKey), $includeSuperAdmin);
    }

    /** Every active user holding a permission, directly or through a role (Super Admin too when $includeSuperAdmin). */
    public static function usersWithPermission(int $accountId, ?string $permissionName, bool $includeSuperAdmin): \Illuminate\Support\Collection
    {
        $permissionId = $permissionName ? Permission::where('name', $permissionName)->where('guard_name', 'web')->value('id') : null;

        if (! $permissionId && ! $includeSuperAdmin) {
            return new \Illuminate\Support\Collection();
        }

        return User::where('account_id', $accountId)
            ->where('is_active', true)
            ->where(function ($query) use ($permissionId, $includeSuperAdmin) {
                if ($permissionId) {
                    $query->orWhereHas('permissions', fn ($p) => $p->where('permissions.id', $permissionId))
                        ->orWhereHas('roles', fn ($r) => $r
                            ->when(! $includeSuperAdmin, fn ($q) => $q->where('name', '!=', 'Super Admin'))
                            ->whereHas('permissions', fn ($p) => $p->where('permissions.id', $permissionId)));
                }
                if ($includeSuperAdmin) {
                    $query->orWhereHas('roles', fn ($r) => $r->where('name', 'Super Admin'));
                }
            })
            ->get();
    }

    /** allow = clear whenever; warn (default) = confirm first when items are not fully delivered; block = refuse until they are. */
    public static function clearDeliveryMode(int $accountId): string
    {
        $value = \App\Models\Setting::where('tec_key', self::CLEAR_CHECK_SETTING)->where('account_id', $accountId)->value('tec_value');

        return in_array($value, self::CLEAR_CHECK_MODES, true) ? $value : 'warn';
    }

    public static function saveClearDeliveryMode(int $accountId, string $mode): void
    {
        if (! in_array($mode, self::CLEAR_CHECK_MODES, true)) {
            return;
        }

        \App\Models\Setting::updateOrCreate(['tec_key' => self::CLEAR_CHECK_SETTING, 'account_id' => $accountId], ['tec_value' => $mode]);
    }

    /**
     * Users holding $stepKey who are also assigned to $warehouseId (user_warehouse), e.g. the Site Engineers of one
     * project, who must be told about / confirm deliveries for that project only. Super Admin is left out here on
     * purpose: holding every permission does not make a Super Admin a site engineer of every project.
     */
    public static function assignedUsersForWarehouse(int $accountId, string $stepKey, ?int $warehouseId): \Illuminate\Support\Collection
    {
        if (! $warehouseId) {
            return new \Illuminate\Support\Collection();
        }

        return self::assignedUsers($accountId, $stepKey, false)
            ->filter(fn (User $u) => $u->warehouses()->where('warehouses.id', $warehouseId)->exists())
            ->values();
    }
}
