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

        return Inertia::render('Settings/FeatureControl', [
            'features' => $features,
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
     * Landing page a blocked non-Super-Admin is redirected to by the
     * FeatureGate middleware (see app/Http/Middleware/FeatureGate.php) —
     * Inertia doesn't swap pages for non-2xx XHR responses, so the
     * middleware redirects here instead of rendering in place.
     */
    public function unavailable(Request $request)
    {
        $feature = SystemFeature::where('key', $request->get('feature'))->first();

        return Inertia::render('Errors/FeatureUnavailable', [
            'featureName' => $feature->name ?? null,
            'message' => __('This WIMS function is currently disabled by the system administrator.'),
        ]);
    }
}
