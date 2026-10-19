<?php

namespace App\Services\Simulation;

use App\Support\PasswordPolicy;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The people who may sign in to Simulation WIMS ("Category Authority" accounts). They live ONLY in the simulation database: nothing is copied from the
 * production users, no production password works there, and no simulation password works in production. Production Super Admins manage them from the
 * production side through the explicit simulation connection; the module code of the simulation never needs this class.
 */
class SimulationAccounts
{
    private function db(): Connection
    {
        return DB::connection('mysql_simulation');
    }

    public function all(): array
    {
        $roleId = $this->db()->table('roles')->where('name', config('simulation.role'))->value('id');
        $perm = $this->db()->table('model_has_permissions')->where('model_type', \App\Models\User::class)->selectRaw('model_id, COUNT(*) n')->groupBy('model_id')->pluck('n', 'model_id');
        $roles = $this->db()->table('model_has_roles')->where('model_type', \App\Models\User::class)->where('role_id', $roleId)->pluck('model_id')->flip();

        $names = $this->db()->table('model_has_permissions as mp')->join('permissions as p', 'p.id', '=', 'mp.permission_id')->where('mp.model_type', \App\Models\User::class)->get(['mp.model_id', 'p.name'])->groupBy('model_id')->map(fn ($g) => $g->pluck('name')->values()->all());

        return $this->db()->table('users')->whereNull('deleted_at')->orderBy('name')->get()->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'email' => $u->email, 'active' => (bool) $u->is_active, 'last_seen' => $u->last_seen_at, 'created_at' => $u->created_at,
            'access' => isset($roles[$u->id]) ? 'full' : 'custom', 'direct_permissions' => (int) ($perm[$u->id] ?? 0), 'permissions' => $names[$u->id] ?? [],
        ])->all();
    }

    /** The mark that makes an account a Category Authority account: the only kind that can sign in to Simulation WIMS, and a kind that can never sign in to production. */
    public const MARK = 'category_authority';

    public static function isCategoryAuthority($user): bool
    {
        $x = $user->extra_attributes ?? null;
        $x = is_string($x) ? json_decode($x, true) : (is_object($x) && method_exists($x, 'toArray') ? $x->toArray() : (array) $x);

        return ! empty($x[self::MARK]);
    }

    /**
     * @param array{username:string,name:string,email?:string,password:string,permissions?:array} $d
     * @param bool $enforcePolicy false only for a password the owner chose on purpose to change later (the command line asks for it explicitly)
     */
    public function create(array $d, ?int $by, bool $enforcePolicy = true): int
    {
        $v = Validator::make($d, [
            'username' => ['required', 'regex:/^[A-Za-z0-9._-]{3,40}$/'], 'name' => 'required|string|max:120', 'email' => 'nullable|string|max:190', 'password' => $enforcePolicy ? ['required', PasswordPolicy::rule()] : ['required', 'string', 'min:8', 'max:190'],
        ]);
        $v->after(function ($v) use ($d) {
            if ($this->db()->table('users')->where('username', $d['username'] ?? '')->exists()) {
                $v->errors()->add('username', __('That username is taken.'));
            }
        });
        $v->validate();
        $terms = $this->db()->table('settings')->where('tec_key', 'terms_version')->value('tec_value');
        $id = $this->db()->table('users')->insertGetId([
            'name' => $d['name'], 'username' => $d['username'], 'email' => ($d['email'] ?? null) ?: ($d['username'] . '@simulation.invalid'), 'password' => Hash::make($d['password']), 'is_active' => 1, 'account_id' => 1,
            'view_all' => 1, 'edit_all' => 1, 'extra_attributes' => json_encode([self::MARK => true]), 'terms_accepted_version' => $terms ? (int) $terms : null, 'terms_accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->setAccess($id, $d['permissions'] ?? null);
        $this->audit('Simulation account created', ['username' => $d['username'], 'access' => ! empty($d['permissions']) ? 'custom' : 'full'], $by);

        return $id;
    }

    /**
     * Edits an account: name and username, optionally a new password, and the access. A changed username or password, or a changed access, ends the person's
     * open sessions so the change takes effect at once. Only Category Authority accounts of the simulation can be edited here.
     *
     * @param array{username:string,name:string,password?:?string,permissions?:?array} $d
     */
    public function update(int $id, array $d, ?int $by): void
    {
        $row = $this->db()->table('users')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        $v = Validator::make($d, ['username' => ['required', 'regex:/^[A-Za-z0-9._-]{3,40}$/'], 'name' => 'required|string|max:120', 'password' => ['nullable', PasswordPolicy::rule()]]);
        $v->after(function ($v) use ($d, $id) {
            if ($this->db()->table('users')->where('username', $d['username'] ?? '')->where('id', '<>', $id)->exists()) {
                $v->errors()->add('username', __('That username is taken.'));
            }
        });
        $v->validate();
        $set = ['name' => $d['name'], 'username' => $d['username'], 'updated_at' => now()];
        $changed = ['name' => $row->name !== $d['name'], 'username' => $row->username !== $d['username']];
        if (! empty($d['password'])) {
            $set['password'] = Hash::make($d['password']);
            $set['remember_token'] = null;
            $changed['password'] = true;
        }
        if ($row->username !== $d['username'] && str_ends_with((string) $row->email, '@simulation.invalid')) {
            $set['email'] = $d['username'] . '@simulation.invalid';
        }
        $this->db()->table('users')->where('id', $id)->update($set);
        $this->setAccess($id, $d['permissions'] ?? null);
        $changed['access'] = true;
        if (! empty($changed['username']) || ! empty($changed['password'])) {
            $this->endSessions($id);
        }
        $this->audit('Simulation account edited', ['user' => $id, 'username' => $d['username'], 'password_changed' => ! empty($d['password']), 'access' => ! empty($d['permissions']) ? 'custom' : 'full'], $by);
    }

    /** full access = the Category Authority Simulation role; custom = only the listed abilities (never one of the production-only abilities) */
    public function setAccess(int $userId, ?array $permissions): void
    {
        $roleId = $this->db()->table('roles')->where('name', config('simulation.role'))->value('id');
        $this->db()->table('model_has_roles')->where('model_id', $userId)->where('model_type', \App\Models\User::class)->delete();
        $this->db()->table('model_has_permissions')->where('model_id', $userId)->where('model_type', \App\Models\User::class)->delete();
        if ($permissions === null || $permissions === []) {
            if ($roleId) {
                $this->db()->table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => \App\Models\User::class, 'model_id' => $userId]);
            }
            // pages and policies that ask "is this person an Admin / Project Manager ..." by NAME are answered too; these roles carry no abilities of their own and Super Admin is never among them
            foreach ($this->workingRoleIds() as $rid) {
                $this->db()->table('model_has_roles')->insert(['role_id' => $rid, 'model_type' => \App\Models\User::class, 'model_id' => $userId]);
            }
        } else {
            $ids = $this->db()->table('permissions')->where('guard_name', 'web')->whereIn('name', $permissions)->whereNotIn('name', (array) config('simulation.never_permissions'))->pluck('id');
            foreach ($ids as $pid) {
                $this->db()->table('model_has_permissions')->insert(['permission_id' => $pid, 'model_type' => \App\Models\User::class, 'model_id' => $userId]);
            }
        }
    }

    /** every role of the simulation except Super Admin and the Category Authority role itself */
    public function workingRoleIds(): array
    {
        return $this->db()->table('roles')->where('guard_name', 'web')->whereNotIn('name', ['Super Admin', (string) config('simulation.role')])->pluck('id')->all();
    }

    /** bring the roles of the full-access accounts up to date (after new roles appear) */
    public function syncRoles(): int
    {
        $roleId = $this->db()->table('roles')->where('name', config('simulation.role'))->value('id');
        $n = 0;
        foreach ($this->db()->table('model_has_roles')->where('role_id', $roleId)->where('model_type', \App\Models\User::class)->pluck('model_id') as $uid) {
            $have = $this->db()->table('model_has_roles')->where('model_id', $uid)->where('model_type', \App\Models\User::class)->pluck('role_id')->all();
            foreach (array_diff($this->workingRoleIds(), $have) as $rid) {
                $this->db()->table('model_has_roles')->insert(['role_id' => $rid, 'model_type' => \App\Models\User::class, 'model_id' => $uid]);
                $n++;
            }
        }

        return $n;
    }

    public function setActive(int $id, bool $active, ?int $by): void
    {
        $this->db()->table('users')->where('id', $id)->update(['is_active' => $active ? 1 : 0, 'disabled_at' => $active ? null : now(), 'updated_at' => now()]);
        if (! $active) {
            $this->endSessions($id);        // an existing session stops working at once (also refused by EnsureEnvironmentIntegrity)
        }
        $this->audit($active ? 'Simulation account activated' : 'Simulation account deactivated', ['user' => $id], $by);
    }

    public function resetPassword(int $id, string $password, ?int $by): void
    {
        Validator::make(['password' => $password], ['password' => ['required', PasswordPolicy::rule()]])->validate();
        $this->db()->table('users')->where('id', $id)->update(['password' => Hash::make($password), 'remember_token' => null, 'updated_at' => now()]);
        $this->endSessions($id);
        $this->audit('Simulation account password reset', ['user' => $id], $by);
    }

    /** Take every way in away: switched off, sessions gone, tokens gone. */
    public function revoke(int $id, ?int $by): void
    {
        $this->db()->table('users')->where('id', $id)->update(['is_active' => 0, 'disabled_at' => now(), 'remember_token' => null, 'updated_at' => now()]);
        $this->endSessions($id);
        if ($this->db()->getSchemaBuilder()->hasTable('personal_access_tokens')) {
            $this->db()->table('personal_access_tokens')->where('tokenable_id', $id)->delete();
        }
        $this->audit('Simulation account revoked', ['user' => $id], $by);
    }

    private function endSessions(int $id): void
    {
        $this->db()->table('sessions')->where('user_id', $id)->delete();
    }

    /** the simulation's own activity: what the simulation users did (read from the simulation database) */
    public function activity(int $limit = 100, ?int $userId = null): array
    {
        $q = $this->db()->table('activity_log')->orderByDesc('id')->limit($limit);
        if ($userId) {
            $q->where('causer_id', $userId);
        }
        $names = $this->db()->table('users')->pluck('name', 'id');

        return $q->get(['id', 'description', 'causer_id', 'created_at'])->map(fn ($a) => ['id' => $a->id, 'what' => $a->description, 'who' => $names[$a->causer_id] ?? '-', 'when' => $a->created_at])->all();
    }

    public function logins(int $limit = 100): array
    {
        return $this->db()->table('login_logs')->orderByDesc('id')->limit($limit)->get(['id', 'username', 'status', 'ip_address', 'attempted_at', 'error_message'])->map(fn ($l) => (array) $l)->all();
    }

    private function audit(string $what, array $props, ?int $by): void
    {
        // recorded in the PRODUCTION activity log (who on the production side did it) and in the simulation's own log
        try {
            if (function_exists('log_activity') && ! \App\Support\Simulation\Environment::isSimulation()) {
                log_activity($what, $props, null, 'Simulation');
            }
            $this->db()->table('activity_log')->insert(['account_id' => 1, 'log_name' => 'default', 'description' => $what, 'properties' => json_encode($props + ['by_production_user' => $by]), 'causer_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            \Log::warning('Simulation account audit failed', ['error' => $e->getMessage()]);
        }
    }
}
