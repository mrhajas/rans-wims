<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserAuditEvent;
use Illuminate\Support\Facades\Schema;

/**
 * The user audit history: the major things that happen to a user account and who did them. Called from the User model (what changed on the
 * account itself) and from the few places that change roles, warehouses, the staff link, the face or "login as". It never stores a password and
 * it never lets a failure here break the action that is being recorded.
 */
class UserAudit
{
    /** kind => [label, icon (name in Shared/Icons.vue), tone (css colour), group shown in the filter] */
    public const KINDS = [
        'user_created' => ['User created', 'plus', '#059669', 'Account'],
        'user_deleted' => ['User deleted', 'trash', '#dc2626', 'Account'],
        'user_restored' => ['User restored', 'refresh', '#0891b2', 'Account'],
        'user_deleted_permanently' => ['User deleted for good', 'trash', '#991b1b', 'Account'],
        'account_disabled' => ['Account disabled', 'lock-closed', '#dc2626', 'Account'],
        'account_enabled' => ['Account enabled', 'lock-open', '#059669', 'Account'],
        'password_changed' => ['Password changed', 'key', '#d97706', 'Security'],
        'two_factor_changed' => ['Two-step sign-in changed', 'otp', '#7c3aed', 'Security'],
        'face_reset' => ['Face login reset', 'camera', '#7c3aed', 'Security'],
        'impersonated' => ['Signed in as this user', 'impersonate', '#be123c', 'Security'],
        'info_updated' => ['Information updated', 'edit', '#2563eb', 'Information'],
        'roles_updated' => ['Roles updated', 'users', '#4f46e5', 'Access'],
        'warehouses_updated' => ['Projects / warehouses updated', 'building', '#0d9488', 'Access'],
        'staff_link' => ['Staff profile link changed', 'link', '#0369a1', 'Access'],
        'role_permissions' => ['Role permissions changed', 'key', '#4f46e5', 'Access'],
    ];

    /** Columns of the users table that are only housekeeping and are never worth a line. */
    private const NOISE = ['updated_at', 'created_at', 'last_seen_at', 'remember_token', 'current_team_id', 'terms_accepted_version', 'terms_accepted_at', 'two_factor_recovery_codes', 'deleted_at', 'deleted_by', 'email_verified_at'];

    /** Information fields shown by name: column => label. */
    private const INFO = ['name' => 'Name', 'email' => 'Email', 'username' => 'Username', 'warehouse_id' => 'Main warehouse', 'idle_timeout_minutes' => 'Auto logout (minutes)', 'view_all' => 'View all records', 'edit_all' => 'Edit all records', 'profile_photo_path' => 'Profile photo'];

    public static function record(?User $subject, string $kind, array $details = [], ?int $actorId = null, ?string $subjectName = null): void
    {
        try {
            if (! Schema::hasTable('user_audit_events')) {
                return;
            }
            $actor = $actorId ? User::withoutGlobalScopes()->find($actorId) : (auth()->check() ? auth()->user() : null);
            $ctx = app()->runningInConsole() && ! app()->runningUnitTests() ? [] : self::context();
            UserAuditEvent::create([
                'account_id' => $subject?->account_id ?? $actor?->account_id ?? 1,
                'user_id' => $subject?->id,
                'actor_id' => $actor?->id,
                'kind' => $kind,
                'user_name' => $subject?->name ?? $subjectName,
                'actor_name' => $actor?->name,
                'details' => $details ?: null,
                'ip' => $ctx['ip'] ?? null,
                'device' => $ctx['device'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function context(): array
    {
        try {
            return request_context();
        } catch (\Throwable) {
            return ['ip' => request()->ip(), 'device' => null];
        }
    }

    /** Called from User::updated(): turns what changed on the account into lines of the history. */
    public static function fromChanges(User $user): void
    {
        $changes = array_diff_key($user->getChanges(), array_flip(self::NOISE));
        if (! $changes) {
            return;
        }
        $actor = auth()->id();

        if (array_key_exists('password', $changes)) {
            self::record($user, 'password_changed', ['source' => ! $actor ? 'reset' : ((int) $actor === (int) $user->id ? 'self' : 'admin')]);
        }
        if (array_key_exists('is_active', $changes) || array_key_exists('disabled_at', $changes)) {
            if (array_key_exists('is_active', $changes)) {
                self::record($user, $user->is_active ? 'account_enabled' : 'account_disabled');
            }
        }
        $tf = [];
        foreach (['two_factor_secret' => 'Authenticator app', 'two_factor_email_enabled_at' => 'Email code', 'two_factor_sms_enabled_at' => 'SMS code'] as $col => $label) {
            if (array_key_exists($col, $changes)) {
                $tf[] = ['label' => $label, 'to' => $user->{$col} ? 'On' : 'Off'];
            }
        }
        if ($tf) {
            self::record($user, 'two_factor_changed', ['methods' => $tf]);
        }

        $info = [];
        foreach (self::INFO as $col => $label) {
            if (! array_key_exists($col, $changes)) {
                continue;
            }
            if ($col === 'profile_photo_path') {
                $info[] = ['label' => $label, 'from' => null, 'to' => $user->profile_photo_path ? 'Changed' : 'Removed'];
                continue;
            }
            $info[] = ['label' => $label, 'from' => self::show($user->getOriginal($col)), 'to' => self::show($user->{$col})];
        }
        if (array_key_exists('extra_attributes', $changes)) {
            $old = (array) ($user->getOriginal('extra_attributes') ?: []);
            $new = (array) ($user->extra_attributes ?: []);
            if (($old['phone'] ?? null) !== ($new['phone'] ?? null)) {
                $info[] = ['label' => 'Phone', 'from' => self::show($old['phone'] ?? null), 'to' => self::show($new['phone'] ?? null)];
            }
        }
        if ($info) {
            self::record($user, 'info_updated', ['changes' => $info]);
        }
    }

    private static function show($v): string
    {
        if ($v === null || $v === '') {
            return '-';
        }

        return is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v;
    }

    /** Roles before and after a change: records what was added and what was taken away. */
    public static function roles(User $user, array $before, array $after): void
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        if ($added || $removed) {
            self::record($user, 'roles_updated', ['added' => $added, 'removed' => $removed, 'now' => array_values($after)]);
        }
    }

    public static function warehouses(User $user, array $before, array $after): void
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        if ($added || $removed) {
            // a role with "All warehouses" can add dozens at once: the first few are named, the rest are counted
            self::record($user, 'warehouses_updated', ['added' => array_slice($added, 0, 12), 'removed' => array_slice($removed, 0, 12), 'added_count' => count($added), 'removed_count' => count($removed)]);
        }
    }
}
