<?php

namespace App\Http\Controllers;

use App\Models\SystemFeature;
use App\Services\FeatureManager;
use App\Services\FeatureReconciler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SystemFeatureController extends Controller
{
    /**
     * Every action here already hard-gates on hasRole('Super Admin') below,
     * so the auto-derived read-/update-system-features ability (never
     * registered) never actually blocks anyone beyond what these explicit
     * checks already block; disabling it removes the dead, misleading
     * auto-check rather than fixing a real gap.
     */
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $features = SystemFeature::orderBy('module')->orderBy('sort_order')->get([
            'id', 'key', 'name', 'description', 'module', 'icon', 'is_enabled', 'depends_on',
        ]);

        $effective = FeatureManager::enabledMap();
        $features->each(fn ($f) => $f->setAttribute('effective', (bool) ($effective[$f->key] ?? true)));

        return Inertia::render('Settings/FeatureControl', [
            'features' => $features,
            'focus' => (string) $request->query('focus', ''),
            'stats' => [
                'total' => $features->count(),
                'active' => $features->where('is_enabled', true)->count(),
                'disabled' => $features->where('is_enabled', false)->count(),
            ],
        ]);
    }

    public function update(Request $request, SystemFeature $systemFeature)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'password' => 'nullable|string',
        ]);

        if (! $data['enabled']) {
            if (! Hash::check((string) ($data['password'] ?? ''), $request->user()->password)) {
                throw ValidationException::withMessages([
                    'password' => __('The provided password is incorrect.'),
                ]);
            }
        }

        $previous = $systemFeature->is_enabled;
        $systemFeature->update(['is_enabled' => $data['enabled']]);

        FeatureManager::flushCache();

        log_activity(
            $data['enabled'] ? 'Feature Enabled' : 'Feature Disabled',
            [
                'feature' => $systemFeature->name,
                'key' => $systemFeature->key,
                'previous' => $previous ? 'ACTIVE' : 'DISABLED',
                'new' => $data['enabled'] ? 'ACTIVE' : 'DISABLED',
            ],
            $systemFeature,
            'System Feature'
        );

        return back()->with('message', $data['enabled']
            ? __(':name enabled.', ['name' => $systemFeature->name])
            : __(':name disabled.', ['name' => $systemFeature->name]));
    }

    /**
     * Main Switch: turns a function and every sub-function under it on or off in one go (the individual switches still work one by one).
     * Switching off needs the Super Admin's password, exactly like a single switch.
     */
    public function family(Request $request, SystemFeature $systemFeature)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'password' => 'nullable|string',
        ]);

        if (! $data['enabled'] && ! Hash::check((string) ($data['password'] ?? ''), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('The provided password is incorrect.')]);
        }

        $keys = array_merge([$systemFeature->key], FeatureManager::descendantsOf($systemFeature->key));
        $changed = SystemFeature::query()->whereIn('key', $keys)->where('is_enabled', ! $data['enabled'])->pluck('name', 'key')->all();
        SystemFeature::query()->whereIn('key', $keys)->update(['is_enabled' => $data['enabled']]);

        FeatureManager::flushCache();

        log_activity(
            $data['enabled'] ? 'Feature Group Enabled' : 'Feature Group Disabled',
            [
                'feature' => $systemFeature->name,
                'key' => $systemFeature->key,
                'functions' => count($keys),
                'changed' => array_values($changed),
                'new' => $data['enabled'] ? 'ACTIVE' : 'DISABLED',
            ],
            $systemFeature,
            'System Feature'
        );

        return back()->with('message', $data['enabled']
            ? __(':name and its :n sub-functions enabled.', ['name' => $systemFeature->name, 'n' => count($keys) - 1])
            : __(':name and its :n sub-functions disabled.', ['name' => $systemFeature->name, 'n' => count($keys) - 1]));
    }

    /** What is out of step between the code registry, Feature Control, the menu / Launcher and the permission registry. Read only. */
    public function reconcile(Request $request, FeatureReconciler $reconciler)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        return response()->json($reconciler->report());
    }

    /** Adds the registered functions that have no Feature Control row yet (switched on). Changes nothing else. */
    public function reconcileApply(Request $request, FeatureReconciler $reconciler)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);
        $added = $reconciler->apply();

        return response()->json(['added' => $added] + $reconciler->report());
    }

    /**
     * Landing page a blocked person is redirected to by the FeatureGate middleware (see app/Http/Middleware/FeatureGate.php) -
     * Inertia doesn't swap pages for non-2xx XHR responses, so the middleware redirects here instead of rendering in place.
     * A Super Admin gets the "module deactivated" notice with a way to switch it back on; everyone else the plain message.
     */
    public function unavailable(Request $request)
    {
        $feature = SystemFeature::where('key', $request->get('feature'))->first();
        $isSuper = $request->user() && $request->user()->hasRole('Super Admin');

        $props = [
            'featureName' => $feature->name ?? null,
            'message' => __('This WIMS function is currently disabled by the system administrator.'),
            'isSuper' => (bool) $isSuper,
        ];

        if ($isSuper && $feature) {
            $blocker = FeatureManager::blockingAncestor($feature->key);
            $from = (string) $request->query('from', '');
            $props += [
                'module' => $feature->module,
                'featureKey' => $feature->key,
                'featureId' => $feature->id,
                'ownSwitchOff' => ! $feature->is_enabled,
                // set when a function above this one is what holds it off (this one's own switch may be on)
                'heldBy' => $blocker && $blocker->key !== $feature->key ? ['key' => $blocker->key, 'name' => $blocker->name, 'id' => $blocker->id] : null,
                'subCount' => count(FeatureManager::descendantsOf($feature->key)),
                // only a same-site path may be used as the way back
                'from' => preg_match('#^/(?!/)#', $from) ? $from : null,
            ];
        }

        return Inertia::render('Errors/FeatureUnavailable', $props);
    }
}
