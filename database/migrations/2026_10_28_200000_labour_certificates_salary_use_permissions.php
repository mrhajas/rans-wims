<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

/**
 * Labour, Project Certificates and Salary stop having a second access system (the "who does which step" rows in
 * labour_workflow_steps / project_certificate_workflow_steps / salary_workflow_steps). From now on the Roles screen
 * (permission strings) is the only place where access is assigned.
 *
 * To keep everybody's EFFECTIVE access exactly as it is today, this derives what each role (and each user, for the
 * people named directly in a step or holding a direct permission) could really do under the old rules and rewrites
 * the permission grants to say exactly that:
 *   - a step row (or, when the account has none, the old built-in default) says which roles / users hold the step;
 *     Super Admin and Admin were unconditional for every step;
 *   - where the old policy needed BOTH a permission and the step, only the people who passed both get the permission;
 *     so an old grant that was inert (the role was not in the step) is taken away instead of suddenly working;
 *   - where the old policy needed only the step, whoever held the step now gets the matching permission.
 *
 * Permission strings that also guarded something else with no step (contract / addendum editing and addendum
 * approval) are first copied to two new strings, so those keep working for everyone who had them.
 *
 * The step tables are kept (not dropped). Every change is logged. A second run does nothing (the marker is the
 * manage-contract permission, created inside the same transaction as the grants).
 */
return new class extends Migration
{
    private const GUARD = 'web';
    private const MARKER = 'manage-contract-project-certificates';
    private const NEW_PERMISSIONS = ['manage-contract-project-certificates', 'approve-addendums-project-certificates'];

    /** Permissions this migration decides (everything else is left exactly as found). */
    private const MANAGED = [
        'read-labour', 'create-labour', 'update-labour', 'delete-labour', 'submit-labour', 'clear-labour', 'approve-labour',
        'create-payment-voucher-from-labour', 'print-labour',
        'create-project-certificates', 'edit-project-certificates', 'submit-project-certificates', 'approve-project-certificates',
        'issue-project-certificates', 'record-payment-project-certificates',
        'create-salary-periods', 'submit-salary-periods', 'approve-salary-periods', 'pay-salary-periods',
    ];

    /** The old built-in starting assignments, used when an account has no row for a step. [module => [step => [roles]]] */
    private const DEFAULTS = [
        'labour' => [
            'submit' => ['Site Engineers', 'Site Engineer', 'Engineers', 'Architect', 'Draftsman', 'Technician'],
            'pm_clear' => ['Project Manager', 'Project Managers'],
            'super_admin_approve' => [], 'create_payment_voucher' => [], 'view' => [], 'print' => [],
        ],
        'certificates' => [
            'create' => ['Admin', 'Super Admin'], 'submit' => ['Admin', 'Super Admin'], 'approve' => ['Admin', 'Super Admin'],
            'issue' => ['Admin', 'Super Admin'], 'record_payment' => ['Admin', 'Super Admin'],
        ],
        'salary' => ['prepare' => ['Admin'], 'approve' => ['Admin'], 'pay' => ['Admin']],
    ];

    private const TABLES = [
        'labour' => 'labour_workflow_steps',
        'certificates' => 'project_certificate_workflow_steps',
        'salary' => 'salary_workflow_steps',
    ];

    private array $steps = [];

    public function up(): void
    {
        if (DB::table('permissions')->where('name', self::MARKER)->where('guard_name', self::GUARD)->exists()) {
            $this->note('[access] Labour / Certificates / Salary permissions already applied; nothing to do.');

            return;
        }

        DB::transaction(function () {
            $now = now();
            foreach (self::NEW_PERMISSIONS as $name) {
                DB::table('permissions')->insert(['name' => $name, 'guard_name' => self::GUARD, 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach (array_merge(self::MANAGED, self::NEW_PERMISSIONS) as $name) {
                if (! DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->exists()) {
                    DB::table('permissions')->insert(['name' => $name, 'guard_name' => self::GUARD, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            $permId = DB::table('permissions')->where('guard_name', self::GUARD)->pluck('id', 'name')->all();

            foreach (self::TABLES as $module => $table) {
                foreach (DB::table($table)->get() as $row) {
                    $this->steps[$module][(int) $row->account_id][$row->step_key] = [
                        'roles' => json_decode($row->role_names ?? '[]', true) ?: [],
                        'users' => array_map('intval', json_decode($row->user_ids ?? '[]', true) ?: []),
                    ];
                }
            }

            // ---- every role: what it holds now, and what the same role could do under the old rules
            $roles = DB::table('roles')->whereNull('deleted_at')->orderBy('name')->get();
            $roleHas = [];
            foreach (DB::table('role_has_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->get(['rp.role_id', 'p.name']) as $r) {
                $roleHas[(int) $r->role_id][$r->name] = true;
            }
            $newRoleHas = $roleHas;
            $summary = [];

            foreach ($roles as $role) {
                $rid = (int) $role->id;
                $before = array_keys($roleHas[$rid] ?? []);
                $held = $roleHas[$rid] ?? [];
                $isSuper = $role->name === 'Super Admin';
                $ctx = ['account' => (int) $role->account_id, 'roles' => [$role->name], 'user' => null, 'perms' => $held];

                $target = [];
                foreach (self::MANAGED as $perm) {
                    $target[$perm] = $isSuper ? true : $this->oldEffective($perm, $ctx);
                }
                // the two new strings start as a copy of what the old shared strings allowed (never taken away afterwards)
                $target['manage-contract-project-certificates'] = $isSuper || isset($held['create-project-certificates']) || isset($held['edit-project-certificates']);
                $target['approve-addendums-project-certificates'] = $isSuper || isset($held['approve-project-certificates']);

                foreach ($target as $perm => $want) {
                    $has = isset($held[$perm]);
                    if ($want && ! $has) {
                        DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permId[$perm], 'role_id' => $rid]);
                        $newRoleHas[$rid][$perm] = true;
                    } elseif (! $want && $has && in_array($perm, self::MANAGED, true)) {
                        DB::table('role_has_permissions')->where('permission_id', $permId[$perm])->where('role_id', $rid)->delete();
                        unset($newRoleHas[$rid][$perm]);
                    }
                }

                $managedNames = array_merge(self::MANAGED, self::NEW_PERMISSIONS);
                $b = array_values(array_intersect($managedNames, $before));
                $a = array_values(array_intersect($managedNames, array_keys($newRoleHas[$rid] ?? [])));
                $summary[$role->name] = ['before' => count($b), 'after' => count($a), 'added' => array_values(array_diff($a, $b)), 'removed' => array_values(array_diff($b, $a))];
                $this->note('[access] role ' . $role->name . ': ' . count($b) . ' -> ' . count($a) . ' of the Labour / Certificates / Salary permissions', ['added' => $summary[$role->name]['added'], 'removed' => $summary[$role->name]['removed']]);
            }

            // ---- every user: exact per-person result (multi-role people, people named in a step, direct grants)
            $userRoles = [];
            foreach (DB::table('model_has_roles as mr')->join('roles as r', 'r.id', '=', 'mr.role_id')->whereNull('r.deleted_at')->where('mr.model_type', 'App\\Models\\User')->get(['mr.model_id', 'mr.role_id', 'r.name']) as $r) {
                $userRoles[(int) $r->model_id][(int) $r->role_id] = $r->name;
            }
            $direct = [];
            foreach (DB::table('model_has_permissions as mp')->join('permissions as p', 'p.id', '=', 'mp.permission_id')->where('mp.model_type', 'App\\Models\\User')->get(['mp.model_id', 'p.name']) as $r) {
                $direct[(int) $r->model_id][$r->name] = true;
            }

            $directGranted = 0;
            $directRevoked = 0;
            $leaks = [];
            foreach (DB::table('users')->whereNull('deleted_at')->get(['id', 'username', 'account_id']) as $user) {
                $uid = (int) $user->id;
                $names = array_values($userRoles[$uid] ?? []);
                if (in_array('Super Admin', $names, true)) {
                    continue; // unconditional; the role already holds every permission
                }
                $oldPerms = $direct[$uid] ?? [];
                foreach (array_keys($userRoles[$uid] ?? []) as $rid) {
                    $oldPerms += $roleHas[$rid] ?? [];
                }
                $ctx = ['account' => (int) $user->account_id, 'roles' => $names, 'user' => $uid, 'perms' => $oldPerms];

                foreach (self::MANAGED as $perm) {
                    $wanted = $this->oldEffective($perm, $ctx);
                    $viaRole = false;
                    foreach (array_keys($userRoles[$uid] ?? []) as $rid) {
                        if (isset($newRoleHas[$rid][$perm])) {
                            $viaRole = true;
                            break;
                        }
                    }
                    $hasDirect = isset($direct[$uid][$perm]);
                    if ($wanted && ! $viaRole && ! $hasDirect) {
                        DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $permId[$perm], 'model_type' => 'App\\Models\\User', 'model_id' => $uid]);
                        $directGranted++;
                        $this->note('[access] user ' . $user->username . ' (#' . $uid . ') gets ' . $perm . ' directly (named in a step, or the roles together passed the old test)');
                    } elseif (! $wanted && $hasDirect) {
                        DB::table('model_has_permissions')->where('permission_id', $permId[$perm])->where('model_type', 'App\\Models\\User')->where('model_id', $uid)->delete();
                        $directRevoked++;
                        $this->note('[access] user ' . $user->username . ' (#' . $uid . ') loses the inert direct grant ' . $perm);
                    } elseif (! $wanted && $viaRole) {
                        $leaks[] = $user->username . ':' . $perm;
                    }
                }
                // direct copies of the two new strings for people who held the old shared ones only directly
                foreach (['manage-contract-project-certificates' => ['create-project-certificates', 'edit-project-certificates'], 'approve-addendums-project-certificates' => ['approve-project-certificates']] as $new => $olds) {
                    $had = false;
                    foreach ($olds as $o) {
                        $had = $had || isset($direct[$uid][$o]);
                    }
                    if ($had) {
                        DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $permId[$new], 'model_type' => 'App\\Models\\User', 'model_id' => $uid]);
                        $this->note('[access] user ' . $user->username . ' (#' . $uid . ') gets ' . $new . ' directly (copy of the old direct grant)');
                    }
                }
            }

            $this->note('[access] users: ' . $directGranted . ' direct grants added, ' . $directRevoked . ' inert direct grants removed.');
            if ($leaks) {
                $this->note('[access] these people now hold a permission through a shared role that their combination of roles did not give them before (a role cannot be split per person): ' . implode(', ', $leaks));
            }
            $this->warnCoarse($roles);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Log::info, and also a plain file so the trail survives a production LOG_LEVEL of warning or higher. */
    private function note(string $message, array $context = []): void
    {
        Log::info($message, $context);
        @file_put_contents(storage_path('logs/access_permissions_migration.log'), '[' . now()->toDateTimeString() . '] ' . $message . ($context ? ' ' . json_encode($context) : '') . "\n", FILE_APPEND);
    }

    public function down(): void
    {
        // Grants are left as they are: reverting would only bring back the inert / lost-access state this migration fixed.
    }

    // ------------------------------------------------------------------ the OLD rules

    /** Did $ctx (roles, user id, permissions held) effectively have $perm's capability under the old two-system rules? */
    private function oldEffective(string $perm, array $ctx): bool
    {
        $held = fn (string $p) => isset($ctx['perms'][$p]);
        $admin = (bool) array_intersect($ctx['roles'], ['Super Admin', 'Admin']);
        $s = fn (string $module, string $step) => $this->stepAllows($module, $step, $ctx);

        return match ($perm) {
            // Labour
            'read-labour' => $admin || $held('read-labour') || $s('labour', 'view'),
            'create-labour' => $admin || ($held('create-labour') && $s('labour', 'submit')),
            'update-labour', 'delete-labour', 'submit-labour' => $admin || $s('labour', 'submit'),
            'clear-labour' => $admin || $s('labour', 'pm_clear'),
            'approve-labour' => $admin || $s('labour', 'super_admin_approve'),
            'create-payment-voucher-from-labour' => $admin || ($held('create-payment-voucher-from-labour') && $s('labour', 'create_payment_voucher')),
            'print-labour' => $admin || $held('print-labour') || $s('labour', 'print'),
            // Project Certificates: permission AND step (Update / Revise / Cancel were edit + create-step / approve-step)
            'create-project-certificates' => $held('create-project-certificates') && $s('certificates', 'create'),
            'edit-project-certificates' => $held('edit-project-certificates') && ($s('certificates', 'create') || $s('certificates', 'approve')),
            'submit-project-certificates' => $held('submit-project-certificates') && $s('certificates', 'submit'),
            'approve-project-certificates' => $held('approve-project-certificates') && $s('certificates', 'approve'),
            'issue-project-certificates' => $held('issue-project-certificates') && $s('certificates', 'issue'),
            'record-payment-project-certificates' => $held('record-payment-project-certificates') && $s('certificates', 'record_payment'),
            // Salary: creating a period was the prepare step alone; preparing individual salary was the permission alone
            'create-salary-periods' => $s('salary', 'prepare') || $held('create-salary-periods'),
            'submit-salary-periods' => $s('salary', 'prepare') && ($held('create-salary-periods') || $held('prepare-bulk-salary-periods')),
            'approve-salary-periods' => $s('salary', 'approve'),
            'pay-salary-periods' => $held('pay-salary-periods') && $s('salary', 'pay'),
            default => false,
        };
    }

    /** The old Model::isAllowed(): Super Admin / Admin always; else the account's step row (named user or role), else the built-in default. */
    private function stepAllows(string $module, string $step, array $ctx): bool
    {
        if (array_intersect($ctx['roles'], ['Super Admin', 'Admin'])) {
            return true;
        }
        $row = $this->steps[$module][$ctx['account']][$step] ?? null;
        if ($row === null) {
            $roles = self::DEFAULTS[$module][$step] ?? [];

            return ! empty($roles) && (bool) array_intersect($ctx['roles'], $roles);
        }
        if ($ctx['user'] !== null && in_array($ctx['user'], $row['users'], true)) {
            return true;
        }

        return ! empty($row['roles']) && (bool) array_intersect($ctx['roles'], $row['roles']);
    }

    /** Edit / Revise / Cancel of a certificate were the create and approve steps; say where one role held only one of the two. */
    private function warnCoarse($roles): void
    {
        foreach ($roles as $role) {
            if ($role->name === 'Super Admin') {
                continue;
            }
            $ctx = ['account' => (int) $role->account_id, 'roles' => [$role->name], 'user' => null, 'perms' => []];
            if ($this->stepAllows('certificates', 'create', $ctx) !== $this->stepAllows('certificates', 'approve', $ctx)) {
                $this->note('[access] role ' . $role->name . ' held only one of the certificate Create / Approve steps; editing, revising and cancelling a certificate now follow the Edit and Approve permissions together. Check this role on the Roles screen.');
            }
        }
    }
};
