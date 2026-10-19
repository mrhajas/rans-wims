<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

/**
 * Material Requests move from their own workflow tables to ordinary PERMISSIONS on the Roles screen.
 *
 * Until now access came from material_request_workflow_steps ("who does what": create, approve, clear, issue,
 * receive, complete, print, download, view_procedures) and material_request_visibility_rules (+ the default rule
 * setting, "who sees what"); the six permission strings on the roles were inert. This migration reads those tables
 * (and the old built-in defaults where a step row is missing) and turns every role's CURRENT effective access into
 * permissions, so nobody gains or loses a thing, except for the documented simplification of "who sees what":
 *
 *   - the per-role status subsets disappear: a role with "View all" sees every status (the old rules of Project
 *     Manager, Procurement Manager and Store Manager hid Cleared requests; they see them now);
 *   - a role that was limited to its assigned projects keeps "View" only, which shows its own requests plus those of
 *     the assigned projects once Approved, Partially Issued, Issued or Cleared;
 *   - a role limited to "own requests only" also gets "View" only (it will additionally see approved requests of
 *     the projects it is assigned to).
 *
 * Mapping (see MaterialRequestWorkflowStep::STEP_PERMISSIONS): create -> create-, approve -> approve-, clear (the
 * Procurement step) -> procure-, issue -> issue-, receive -> receive-, complete -> complete-, print -> print-,
 * download -> download-, view_procedures -> view-procedures-material-requests. Admin was an unconditional override in
 * the old code, so it is simply granted everything here. Super Admin passes every permission through the Gate and
 * is granted them too so the Roles screen shows it correctly. A step's specific users get the permission directly.
 *
 * "update-" and "delete-material-requests" now mean "edit / delete ANY request", which only Admin and Super Admin
 * could do before; "change-status-material-requests" was never enforced and stays unused: all three are revoked
 * from every other role so old stale ticks cannot suddenly start working.
 *
 * Uses the DB facade only (no models), is idempotent (re-running recomputes the same result from the same tables)
 * and drops nothing: the two workflow tables stay in place, unused.
 */
return new class extends Migration
{
    private const STEP_PERMISSIONS = [
        'create' => 'create-material-requests',
        'approve' => 'approve-material-requests',
        'clear' => 'procure-material-requests',
        'issue' => 'issue-material-requests',
        'receive' => 'receive-material-requests',
        'complete' => 'complete-material-requests',
        'print' => 'print-material-requests',
        'download' => 'download-material-requests',
        'view_procedures' => 'view-procedures-material-requests',
    ];

    /** The role lists the old MaterialRequestWorkflowStep::defaults() used whenever a step row was missing. */
    private const SITE_ROLES = ['Site Engineers', 'Site Engineer', 'Engineers', 'Architect', 'Draftsman', 'Technician'];

    private function defaultRoles(): array
    {
        $pm = ['Project Manager', 'Project Managers'];
        $proc = ['Procurement Manager', 'Procurement Manger'];

        return [
            'create' => array_merge($pm, ['Admin']),
            'approve' => ['Super Admin'],
            'clear' => $proc,
            'issue' => $proc,
            'receive' => self::SITE_ROLES,
            'complete' => ['Super Admin'],
            'print' => array_merge($pm, $proc, ['Store Manager']),
            'download' => array_merge($pm, $proc, ['Store Manager']),
            'view_procedures' => $pm,
        ];
    }

    private function allPermissionNames(): array
    {
        return array_merge(
            ['read-material-requests', 'view-all-material-requests', 'update-material-requests', 'delete-material-requests', 'change-status-material-requests'],
            array_values(self::STEP_PERMISSIONS)
        );
    }

    /** 'all' = View all, 'read' = View (own + assigned projects), 'none' = no access. */
    private function visibilityLevel(?object $rule): string
    {
        if (! $rule) {
            return 'none';
        }

        return (! empty($rule->own_only) || ! empty($rule->assigned_only)) ? 'read' : 'all';
    }

    public function up(): void
    {
        $now = now();
        $names = $this->allPermissionNames();

        // 1. The permission rows.
        foreach ($names as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }
        $permissionIds = DB::table('permissions')->whereIn('name', $names)->where('guard_name', 'web')->pluck('id', 'name')->all();
        $idsOnly = array_values($permissionIds);

        $accountIds = DB::table('roles')->whereNotNull('account_id')->distinct()->pluck('account_id')
            ->merge(DB::table('material_request_workflow_steps')->distinct()->pluck('account_id'))
            ->unique()->values();

        foreach ($accountIds as $accountId) {
            // 2. What the old tables said for this account.
            $stepRoles = $this->defaultRoles();
            $stepUsers = array_fill_keys(array_keys(self::STEP_PERMISSIONS), []);
            foreach (DB::table('material_request_workflow_steps')->where('account_id', $accountId)->get() as $row) {
                if (! array_key_exists($row->step_key, self::STEP_PERMISSIONS)) {
                    continue; // the legacy "close" step
                }
                $stepRoles[$row->step_key] = (array) (json_decode($row->role_names ?? '[]', true) ?: []);
                $stepUsers[$row->step_key] = array_map('intval', (array) (json_decode($row->user_ids ?? '[]', true) ?: []));
            }

            $rules = DB::table('material_request_visibility_rules')->where('account_id', $accountId)->get()->keyBy('role_name');
            $rawDefault = DB::table('settings')->where('tec_key', 'material_request_visibility_default')->where('account_id', $accountId)->value('tec_value');
            $decoded = $rawDefault ? json_decode($rawDefault, true) : null;
            $defaultRule = (is_array($decoded) && ! empty($decoded['enabled']))
                ? (object) ['own_only' => (bool) ($decoded['own_only'] ?? true), 'assigned_only' => false, 'statuses' => $decoded['statuses'] ?? [], 'cleared_only' => (bool) ($decoded['cleared_only'] ?? false)]
                : null;

            // 3. Every role of the account (soft-deleted ones too, so a restored role does not revive stale ticks).
            foreach (DB::table('roles')->where('account_id', $accountId)->where('guard_name', 'web')->orderBy('name')->get() as $role) {
                $name = $role->name;
                $before = DB::table('role_has_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                    ->where('rp.role_id', $role->id)->where('p.name', 'like', '%-material-requests')->pluck('p.name')->sort()->values()->all();

                $notes = [];
                if ($name === 'Super Admin') {
                    $wanted = $names;
                } elseif ($name === 'Admin') {
                    // Admin was an unconditional override of every step and saw every request.
                    $wanted = array_values(array_diff($names, ['change-status-material-requests']));
                } else {
                    $wanted = [];
                    foreach (self::STEP_PERMISSIONS as $step => $permission) {
                        if (in_array($name, $stepRoles[$step], true)) {
                            $wanted[] = $permission;
                        }
                    }

                    $rule = $rules->get($name);
                    if ($rule) {
                        $level = $this->visibilityLevel($rule);
                        $statuses = json_decode($rule->statuses ?? '[]', true) ?: [];
                        if ($level === 'all' && ($statuses || $rule->cleared_only)) {
                            $notes[] = 'view-all replaces status subset [' . implode(', ', $statuses) . ']' . ($rule->cleared_only ? ' + cleared only' : '');
                        }
                        if ($level === 'read' && ! empty($rule->own_only)) {
                            $notes[] = 'was own requests only: now also sees approved requests of its assigned projects';
                        }
                    } else {
                        $level = $this->visibilityLevel($defaultRule);
                        if ($level !== 'none') {
                            $notes[] = 'no rule of its own: took the fallback rule';
                        }
                    }
                    if ($level === 'all') {
                        $wanted[] = 'view-all-material-requests';
                        $wanted[] = 'read-material-requests';
                    } elseif ($level === 'read') {
                        $wanted[] = 'read-material-requests';
                    }
                }

                $wanted = array_values(array_unique($wanted));
                DB::table('role_has_permissions')->where('role_id', $role->id)->whereIn('permission_id', $idsOnly)->delete();
                foreach ($wanted as $permission) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionIds[$permission], 'role_id' => $role->id]);
                }

                sort($wanted);
                Log::info('material_requests_use_permissions: role "' . $name . '" (account ' . $accountId . ')', [
                    'before_count' => count($before),
                    'after_count' => count($wanted),
                    'before' => $before,
                    'after' => $wanted,
                    'added' => array_values(array_diff($wanted, $before)),
                    'removed' => array_values(array_diff($before, $wanted)),
                    'notes' => $notes,
                ]);
            }

            // 4. People named directly on a step get that step's permission directly.
            foreach (self::STEP_PERMISSIONS as $step => $permission) {
                foreach (array_unique($stepUsers[$step]) as $userId) {
                    if (! DB::table('users')->where('id', $userId)->exists()) {
                        continue;
                    }
                    DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $permissionIds[$permission], 'model_type' => 'App\\Models\\User', 'model_id' => $userId]);
                    Log::info('material_requests_use_permissions: user ' . $userId . ' was named on the "' . $step . '" step and now holds ' . $permission . ' directly.');
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The old tables are untouched, but the roles' previous inert ticks are not restorable: nothing to undo. */
    public function down(): void
    {
        //
    }
};
