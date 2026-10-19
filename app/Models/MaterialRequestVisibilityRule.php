<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Softcoded "who sees what" for Material Requests, fully data-driven.
 *
 * A rule is one row per (account, role): an optional status subset plus two
 * switches (own-requests-only, cleared-only). Rules reference a role BY NAME
 * (the same convention as MaterialRequestWorkflowStep::$role_names), so:
 *
 *  - any role that exists in the `roles` table, including roles created
 *    later, can be given a rule from Administration -> Material Request
 *    Workflow without a code change;
 *  - a rule whose role was deleted or renamed is simply never matched (a user
 *    cannot hold a deleted role) and the admin page lists it as a
 *    "(deleted role)" so it can be removed.
 *
 * Evaluation is the UNION of the user's matching rules: a request is visible
 * if ANY rule of ANY role the user holds lets it through. A role that has no
 * rule falls back to the account's editable "default rule" (see
 * defaultRuleFor()), which ships switched OFF, so an unlisted role sees
 * nothing (safe-deny). The only roles named in code are the ones in
 * ALWAYS_VISIBLE_ROLES, the same unconditional Super Admin/Admin override the
 * rest of this module uses.
 */
class MaterialRequestVisibilityRule extends Model
{
    /** Roles that always see every request; a rule for them would be meaningless so the admin page does not offer them. */
    public const ALWAYS_VISIBLE_ROLES = ['Super Admin', 'Admin'];

    /** Settings key (settings.tec_key) holding the per-account fallback rule for roles with no rule of their own. */
    public const DEFAULT_SETTING_KEY = 'material_request_visibility_default';

    protected $fillable = ['account_id', 'role_name', 'statuses', 'own_only', 'assigned_only', 'cleared_only'];

    protected $casts = [
        'statuses' => 'array',
        'own_only' => 'boolean',
        'assigned_only' => 'boolean',
        'cleared_only' => 'boolean',
    ];

    /** Shape of the fallback rule when nothing has been saved: disabled = unlisted roles see nothing. */
    public static function emptyDefaultRule(): array
    {
        return ['enabled' => false, 'statuses' => [], 'own_only' => true, 'cleared_only' => false];
    }

    /** The account's editable fallback rule for roles that have no rule of their own. */
    public static function defaultRuleFor(int $accountId): array
    {
        $raw = Setting::where('tec_key', self::DEFAULT_SETTING_KEY)->where('account_id', $accountId)->value('tec_value');
        $decoded = $raw ? json_decode($raw, true) : null;
        $base = self::emptyDefaultRule();

        if (! is_array($decoded)) {
            return $base;
        }

        return [
            'enabled' => (bool) ($decoded['enabled'] ?? false),
            'statuses' => array_values(array_intersect(MaterialRequest::statuses(), (array) ($decoded['statuses'] ?? []))),
            'own_only' => (bool) ($decoded['own_only'] ?? $base['own_only']),
            'cleared_only' => (bool) ($decoded['cleared_only'] ?? false),
        ];
    }

    public static function saveDefaultRule(int $accountId, array $rule): void
    {
        $clean = [
            'enabled' => (bool) ($rule['enabled'] ?? false),
            'statuses' => array_values(array_intersect(MaterialRequest::statuses(), (array) ($rule['statuses'] ?? []))),
            'own_only' => (bool) ($rule['own_only'] ?? true),
            'cleared_only' => (bool) ($rule['cleared_only'] ?? false),
        ];

        Setting::updateOrCreate(
            ['tec_key' => self::DEFAULT_SETTING_KEY, 'account_id' => $accountId],
            ['tec_value' => json_encode($clean)]
        );
    }

    public static function isAlwaysVisible(User $user): bool
    {
        return $user->hasRole(self::ALWAYS_VISIBLE_ROLES);
    }

    /**
     * Every rule that applies to $user: one per role the user currently holds
     * (a deleted role is not held, so its stale rule can never match), using
     * the role's own rule if it has one, otherwise the account's default rule
     * when enabled. Returns null for Super Admin/Admin (unrestricted); an empty
     * collection means "sees nothing".
     *
     * @return \Illuminate\Support\Collection<int, array{statuses: array, own_only: bool, cleared_only: bool}>|null
     */
    protected static function rulesFor(User $user): ?\Illuminate\Support\Collection
    {
        if (self::isAlwaysVisible($user)) {
            return null;
        }

        $roleNames = $user->roles->pluck('name')->all();
        if (empty($roleNames)) {
            return collect();
        }

        $rows = static::where('account_id', $user->account_id)->whereIn('role_name', $roleNames)->get()->keyBy('role_name');
        $default = null;
        $rules = collect();

        foreach ($roleNames as $roleName) {
            $row = $rows->get($roleName);

            if ($row) {
                $rules->push(['statuses' => array_values($row->statuses ?? []), 'own_only' => (bool) $row->own_only, 'assigned_only' => (bool) $row->assigned_only, 'cleared_only' => (bool) $row->cleared_only]);
                continue;
            }

            $default ??= self::defaultRuleFor((int) $user->account_id);
            if ($default['enabled']) {
                $rules->push(['statuses' => $default['statuses'], 'own_only' => $default['own_only'], 'cleared_only' => $default['cleared_only']]);
            }
        }

        return $rules;
    }

    private static function ruleAllows(array $rule, User $user, MaterialRequest $materialRequest): bool
    {
        if ($rule['own_only'] && (int) $materialRequest->user_id !== (int) $user->id) {
            return false;
        }
        if (! empty($rule['assigned_only']) && ! $user->warehouses()->where('warehouses.id', $materialRequest->warehouse_id)->exists()) {
            return false;
        }
        if ($rule['cleared_only'] && $materialRequest->status !== MaterialRequest::STATUS_CLEARED) {
            return false;
        }
        if (! empty($rule['statuses']) && ! in_array($materialRequest->status, $rule['statuses'], true)) {
            return false;
        }

        return true;
    }

    /** Hard gate for reaching the module at all: Super Admin/Admin always; anyone else needs at least one applicable rule. */
    public static function hasAnyAccess(User $user): bool
    {
        $rules = self::rulesFor($user);

        return $rules === null || $rules->isNotEmpty();
    }

    /** True when every rule that applies to $user is own-requests-only, which drives UI decisions such as hiding the "Requested By"/Warehouse filters. */
    public static function isOwnOnly(User $user): bool
    {
        $rules = self::rulesFor($user);

        return $rules !== null && $rules->isNotEmpty() && $rules->every(fn ($r) => $r['own_only']);
    }

    public static function isVisible(User $user, MaterialRequest $materialRequest): bool
    {
        $rules = self::rulesFor($user);
        if ($rules === null) {
            return true;
        }

        return $rules->contains(fn ($rule) => self::ruleAllows($rule, $user, $materialRequest));
    }

    /** Applies the same union of rules as a query scope, for the index() listing (must stay equivalent to isVisible()). */
    public static function scopeQueryFor(Builder $query, User $user): Builder
    {
        $rules = self::rulesFor($user);
        if ($rules === null) {
            return $query;
        }
        if ($rules->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $any) use ($rules, $user, $table) {
            foreach ($rules as $rule) {
                $any->orWhere(function (Builder $one) use ($rule, $user, $table) {
                    if ($rule['own_only']) {
                        $one->where($table . '.user_id', $user->id);
                    }
                    if (! empty($rule['assigned_only'])) {
                        $one->whereIn($table . '.warehouse_id', $user->warehouses()->pluck('warehouses.id'));
                    }
                    if ($rule['cleared_only']) {
                        $one->where($table . '.status', MaterialRequest::STATUS_CLEARED);
                    }
                    if (! empty($rule['statuses'])) {
                        $one->whereIn($table . '.status', $rule['statuses']);
                    }
                    // A rule with no constraint at all matches everything.
                    if (! $rule['own_only'] && empty($rule['assigned_only']) && ! $rule['cleared_only'] && empty($rule['statuses'])) {
                        $one->whereRaw('1 = 1');
                    }
                });
            }
        });
    }

    /**
     * Every stored rule of the account for the admin page, plus whether its
     * role still exists ($existingRoleNames = the account's current roles).
     * A rule for a role that no longer exists is returned with
     * role_missing = true instead of being hidden, so it can be deleted.
     */
    public static function forAccount(int $accountId, array $existingRoleNames): array
    {
        return static::where('account_id', $accountId)
            ->orderBy('role_name')
            ->get()
            ->map(fn ($row) => [
                'role_name' => $row->role_name,
                'statuses' => array_values($row->statuses ?? []),
                'own_only' => (bool) $row->own_only,
                'assigned_only' => (bool) $row->assigned_only,
                'cleared_only' => (bool) $row->cleared_only,
                'role_missing' => ! in_array($row->role_name, $existingRoleNames, true),
            ])
            ->all();
    }
}
