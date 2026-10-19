<?php

namespace App\Services;

use App\Models\SystemFeature;
use App\Support\PermissionRegistry;
use App\Support\SystemFeatureRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Reconciliation for Global Feature Control. WIMS functions are registered in three places that must agree: the code registry
 * (SystemFeatureRegistry), the system_features table that Super Admins toggle, and the navigation (menu.js, which feeds the sidebar and the
 * Launcher). This reads all three and lists what is out of step, so a function can never quietly exist without a switch:
 *
 *  - missing_in_db       registered in code but with no row (cannot be switched; apply() inserts it, enabled)
 *  - orphan_in_db        a row that no longer exists in the code registry (reported, never deleted automatically)
 *  - bad_dependency      depends_on points at a function that does not exist
 *  - duplicate_prefix    one route prefix claimed by two functions
 *  - menu_unknown        a menu entry names a feature that is not registered
 *  - menu_ungated        a top-level menu group with no feature
 *  - routes_ungoverned   named routes behind the feature gate that no function covers (grouped by first name segment)
 *  - missing_permissions abilities in PermissionRegistry that have no permission row yet
 *
 * It only reads, except apply(), which only ever inserts the missing rows.
 */
class FeatureReconciler
{
    /** Route-name segments that are the platform itself (sign-in, profile, health checks, the gate's own landing page) and need no switch. */
    private const CORE_SEGMENTS = [
        'dashboard', 'profile', 'logout', 'login', 'password', 'verification', 'two-factor', 'user-profile-information', 'user-password',
        'current-user', 'sanctum', 'presence', 'offline', 'feature-unavailable', 'alerts', 'activity', 'api', 'ignition', 'livewire', 'face-login',
        'dashboard-cards', 'launcher', 'current', 'hover-preview', 'media', 'quick-menu', 'rauncher', 'scan', 'terms', 'maintenance', 'impersonation', 'stock', 'licence-manager', 'wims-licence', 'search', 'notifications', 'language', 'locale', 'settings', 'system-features', 'password-confirm', 'other-browser-sessions',
    ];

    /** Menu groups that are deliberately always available. */
    private const MENU_EXEMPT = ['dashboard', 'activities', 'settings', 'reports'];

    public function report(): array
    {
        $registry = SystemFeatureRegistry::features();
        $rows = SystemFeature::query()->get(['key', 'depends_on', 'route_prefixes', 'is_enabled'])->keyBy('key');

        $missing = collect(array_keys($registry))->diff($rows->keys())->values()->all();
        $orphans = $rows->keys()->diff(array_keys($registry))->values()->all();

        $badDep = [];
        foreach ($registry as $key => $f) {
            if (! empty($f['depends_on']) && ! isset($registry[$f['depends_on']])) {
                $badDep[] = ['key' => $key, 'depends_on' => $f['depends_on']];
            }
        }

        $claims = [];
        foreach ($registry as $key => $f) {
            foreach ((array) ($f['route_prefixes'] ?? []) as $prefix) {
                $claims[$prefix][] = $key;
            }
        }
        $dupes = collect($claims)->filter(fn ($keys) => count($keys) > 1)->map(fn ($keys, $p) => ['prefix' => $p, 'features' => $keys])->values()->all();

        [$unknown, $ungated] = $this->menuIssues(array_keys($registry));

        $perms = Permission::query()->pluck('name')->all();
        $missingPerms = array_values(array_diff(PermissionRegistry::allPermissionNames(), $perms));

        $out = [
            'registered' => count($registry),
            'in_db' => $rows->count(),
            'missing_in_db' => $missing,
            'orphan_in_db' => $orphans,
            'bad_dependency' => $badDep,
            'duplicate_prefix' => $dupes,
            'menu_unknown' => $unknown,
            'menu_ungated' => $ungated,
            'routes_ungoverned' => $this->ungovernedRoutes(),
            'missing_permissions' => $missingPerms,
        ];
        $out['problems'] = count($missing) + count($orphans) + count($badDep) + count($dupes) + count($unknown) + count($ungated) + count($out['routes_ungoverned']) + count($missingPerms);

        return $out;
    }

    /** Inserts the rows that exist in code but not in the table. Never changes or removes an existing row. Returns the keys added. */
    public function apply(): array
    {
        $registry = SystemFeatureRegistry::features();
        $have = SystemFeature::query()->pluck('key')->all();
        $order = (int) DB::table('system_features')->max('sort_order');
        $added = [];
        foreach ($registry as $key => $f) {
            if (in_array($key, $have, true)) {
                continue;
            }
            DB::table('system_features')->insert([
                'key' => $key, 'name' => $f['name'], 'description' => $f['description'] ?? null, 'module' => $f['module'] ?? null, 'icon' => $f['icon'] ?? null,
                'is_enabled' => 1, 'depends_on' => $f['depends_on'] ?? null, 'route_prefixes' => json_encode($f['route_prefixes'] ?? []),
                'sort_order' => ++$order, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $added[] = $key;
        }
        if ($added) {
            FeatureManager::flushCache();
            log_activity('Feature Control reconciled', ['added' => $added], null, 'System Feature');
        }

        return $added;
    }

    /** @return array{0: array, 1: array} unknown feature names used by the menu, and top-level menu groups without a feature */
    private function menuIssues(array $known): array
    {
        $file = resource_path('js/Core/menu.js');
        if (! is_file($file)) {
            return [[], []];
        }
        $src = file_get_contents($file);
        $unknown = [];
        if (preg_match_all("/^\s+feature:\s*'([^']+)'/m", $src, $m)) {
            foreach (array_unique($m[1]) as $f) {
                if (! in_array($f, $known, true)) {
                    $unknown[] = $f;
                }
            }
        }
        $ungated = [];
        if (preg_match_all("/^  \{\n    name: '([^']+)',\n(.*?)^  \},?\s*$/ms", $src, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as $b) {
                if (in_array($b[1], self::MENU_EXEMPT, true) || preg_match("/^    hidden: true/m", $b[2])) {
                    continue;
                }
                if (! preg_match("/^    feature:\s*'/m", $b[2])) {
                    $ungated[] = $b[1];
                }
            }
        }

        return [array_values($unknown), $ungated];
    }

    private function ungovernedRoutes(): array
    {
        $by = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! in_array('feature.gate', $route->gatherMiddleware(), true)) {
                continue;
            }
            $segment = Str::before($name, '.');
            if (in_array($segment, self::CORE_SEGMENTS, true) || FeatureManager::featureForRoute($name)) {
                continue;
            }
            $by[$segment] = ($by[$segment] ?? 0) + 1;
        }
        ksort($by);

        return collect($by)->map(fn ($n, $s) => ['prefix' => $s, 'routes' => $n])->values()->all();
    }
}
