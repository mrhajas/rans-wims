<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Setting;
use App\Rules\LocaleLength;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    public function index()
    {
        return Inertia::render('Setting/Index', ['current' => get_settings(), 'typography' => auth()->user() && auth()->user()->hasRole('Super Admin') ? \App\Support\Typography::forSettings() : null]);
    }

    public function store(Request $request)
    {
        $settings = $request->validate([
            'name'           => 'required',
            'currency_code'  => 'required|size:3',
            'weight_unit'    => 'nullable|string',
            'over_selling'   => 'nullable|boolean',
            'track_weight'   => 'nullable|boolean',
            'sidebar'        => 'required|in:full,mini',
            'language'       => 'required|string',
            'sidebar_style'  => 'required|in:full,dropdown',
            'per_page'       => 'required|in:10,15,25,50,100',
            'default_locale' => ['required', new LocaleLength()],
            'fraction'       => 'required|integer|min:0|in:0,1,2,3,4',
            'reference'      => 'required|string|in:ai,ulid,uuid,uniqid',
            'version_label'  => 'nullable|string|max:50',
            'daily_site_report_auto_archive_days' => 'required|integer|min:1|max:365',
            'app_name'       => 'nullable|string|max:190',
            'footer_credit'  => 'nullable|string|max:190',
        ]);
        collect($settings)->each(function ($value, $key) {
            Setting::updateOrCreate(['tec_key' => $key, 'account_id' => auth()->user()->account_id], ['tec_value' => $value]);
        });
        log_activity(__choice('action_text', ['record' => 'Settings', 'action' => 'saved']), $settings, auth()->user(), 'Setting');

        return back()->with('message', __choice('action_text', ['record' => 'Settings', 'action' => 'saved']));
    }

    /**
     * Writes to public/images/login-logo.png — deliberately NOT the shared
     * public/images/logo.png, which is also the letterhead/watermark on
     * dozens of print templates app-wide (reports, receipts, etc.) plus a
     * few other Vue pages. Scoping this upload to its own file keeps it
     * doing exactly what Settings says it does: only the Login and Forgot
     * Password screens change, nothing else. Login/ForgotPassword.vue fall
     * back to the shared logo.png on their own if this file doesn't exist.
     * Always normalized to PNG via GD so a JPEG upload still lands at the
     * .png path those pages expect.
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg|max:2048',
        ]);

        $file = $request->file('logo');
        $destination = public_path('images/login-logo.png');

        $image = match ($file->getClientOriginalExtension()) {
            'jpg', 'jpeg' => imagecreatefromjpeg($file->getRealPath()),
            default => imagecreatefrompng($file->getRealPath()),
        };

        // Preserve transparency instead of it flattening to black.
        imagesavealpha($image, true);
        imagepng($image, $destination);
        imagedestroy($image);

        log_activity(__('Application logo updated.'), [], auth()->user(), 'Setting');

        return back()->with('message', __('Logo updated.'));
    }

    /**
     * Separate from store() deliberately — the main settings form isn't a
     * natural place for a long document body, and bumping terms_version
     * here (not on every settings save) is what makes every user's stored
     * terms_accepted_version stale again, re-triggering the read-log on
     * their next visit to /terms.
     */
    public function updateTerms(Request $request)
    {
        $this->authorize('update-settings');

        $data = $request->validate([
            'content' => 'required|string|max:50000',
        ]);

        $accountId = auth()->user()->account_id;
        $currentVersion = (int) (get_settings('terms_version') ?: 1);
        $newVersion = $currentVersion + 1;

        Setting::updateOrCreate(['tec_key' => 'terms_content', 'account_id' => $accountId], ['tec_value' => $data['content']]);
        Setting::updateOrCreate(['tec_key' => 'terms_version', 'account_id' => $accountId], ['tec_value' => (string) $newVersion]);

        log_activity(__('Terms & Conditions updated (version :version).', ['version' => $newVersion]), ['version' => $newVersion], auth()->user(), 'Setting');

        return back()->with('message', __('Terms & Conditions saved. Users will be asked to review the updated version.'));
    }

    public function toggleSystemLockdown(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->hasAnyRole(['Super Admin', 'Admin'])) {
            throw ValidationException::withMessages([
                'system_lockdown' => __('You are not allowed to update system lockdown.'),
            ]);
        }

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'password' => 'required|string',
        ]);

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('The provided password is incorrect.'),
            ]);
        }

        Setting::updateOrCreate(
            ['tec_key' => 'system_lockdown', 'account_id' => $user->account_id],
            ['tec_value' => $validated['enabled'] ? '1' : '0']
        );

        log_activity(
            __choice('action_text', ['record' => 'System Lockdown', 'action' => $validated['enabled'] ? 'enabled' : 'disabled']),
            ['system_lockdown' => $validated['enabled']],
            $user,
            'Setting'
        );

        return response()->json([
            'success' => true,
            'enabled' => (bool) $validated['enabled'],
        ]);
    }

    /** The typefaces of the interface: one for the whole application and, optionally, a font / weight / italic for headings, paragraphs, labels, buttons, tabs, tables, tooltips and fields. Super Admin. */
    public function updateTypography(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->hasRole('Super Admin'), 403);
        $roles = array_keys(\App\Support\Typography::roles());
        $data = $request->validate([
            'roles' => 'required|array',
            'roles.*.font' => 'required|string|max:40',
            'roles.*.weight' => 'required|string|in:' . implode(',', array_keys(\App\Support\Typography::WEIGHTS)),
            'roles.*.italic' => 'nullable|boolean',
        ]);
        $fonts = \App\Support\Typography::fonts();
        $errors = [];
        foreach ($data['roles'] as $role => $r) {
            if (! in_array($role, $roles, true)) {
                $errors['roles.' . $role] = __('Unknown part of the interface.');
            } elseif ($r['font'] !== 'inherit' && ! isset($fonts[$r['font']])) {
                $errors['roles.' . $role . '.font'] = __('Choose a font from the list.');
            } elseif ($r['font'] === 'inherit' && $role === 'base') {
                $errors['roles.base.font'] = __('Choose the font of the whole application.');
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $merged = \App\Support\Typography::merge(\App\Support\Typography::defaults(), $data['roles']);
        Setting::updateOrCreate(['tec_key' => \App\Support\Typography::KEY, 'account_id' => $user->account_id], ['tec_value' => json_encode($merged)]);
        log_activity('Typography settings saved', ['base' => $merged['base']['font']] + collect($merged)->except('base')->filter(fn ($r) => $r['font'] !== 'inherit' || $r['weight'] !== 'auto' || $r['italic'])->all(), null, 'Setting');

        return back()->with('message', __('Typography saved. The new fonts are used on every page.'));
    }

    public function resetTypography(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->hasRole('Super Admin'), 403);
        Setting::where('tec_key', \App\Support\Typography::KEY)->where('account_id', $user->account_id)->delete();
        log_activity('Typography settings reset to the defaults', [], null, 'Setting');

        return back()->with('message', __('Typography reset to the starting fonts (Avenir).'));
    }

    /** How many devices one person may be signed in on at once (0 = no limit). Super Admin, with their own password. See App\Services\Security\DeviceLimit. */
    public function updateDeviceLimit(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->hasRole('Super Admin')) {
            throw ValidationException::withMessages(['max_devices' => __('You are not allowed to update this setting.')]);
        }
        $validated = $request->validate(['max_devices' => 'required|integer|min:0|max:'.\App\Services\Security\DeviceLimit::MAX, 'password' => 'required|string']);
        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => __('The provided password is incorrect.')]);
        }
        Setting::updateOrCreate(['tec_key' => \App\Services\Security\DeviceLimit::KEY, 'account_id' => $user->account_id], ['tec_value' => (string) $validated['max_devices']]);
        log_activity(__('Devices per user set to :n.', ['n' => $validated['max_devices'] ?: __('no limit')]), ['max_devices' => (int) $validated['max_devices']], null, 'Setting');

        return response()->json(['success' => true, 'max_devices' => (int) $validated['max_devices']]);
    }

    /** How long a "Login As User" session stays active — see App\Support\Impersonation. */
    public function updateImpersonationExpiry(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->hasRole('Super Admin')) {
            throw ValidationException::withMessages([
                'minutes' => __('You are not allowed to update this setting.'),
            ]);
        }

        $validated = $request->validate([
            'minutes' => 'required|integer|min:1|max:1440',
            'password' => 'required|string',
        ]);

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('The provided password is incorrect.'),
            ]);
        }

        Setting::updateOrCreate(
            ['tec_key' => 'impersonation_expiry_minutes', 'account_id' => $user->account_id],
            ['tec_value' => (string) $validated['minutes']]
        );

        log_activity(
            __('Impersonation session duration set to :minutes minute(s).', ['minutes' => $validated['minutes']]),
            ['minutes' => $validated['minutes']],
            $user,
            'Impersonation'
        );

        return response()->json([
            'success' => true,
            'minutes' => (int) $validated['minutes'],
        ]);
    }
}
