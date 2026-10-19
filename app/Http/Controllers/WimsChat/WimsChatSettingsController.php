<?php

namespace App\Http\Controllers\WimsChat;

use App\Models\Role;
use App\Services\WimsChat\WimsChatLogger;
use App\Services\WimsChat\WimsChatSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** WIMSChat administration: file limits, daily role quotas, file types, reactions, statuses, windows. (Who is told about chat activity is on the WIMSChat workflow page.) One save, password-confirmed, logged. */
class WimsChatSettingsController extends WimsChatBaseController
{
    private function guard()
    {
        $u = auth()->user();
        abort_unless($u && $u->can('manage-wimschat-settings'), 403);

        return $u;
    }

    public function index()
    {
        $me = $this->guard();
        $cfg = $this->cfg($me);

        return Inertia::render('WimsChat/Settings', [
            'wc_settings' => $cfg->all(),
            'server_max_mb' => WimsChatSettings::serverMaxMb(),
            'roles' => Role::ofAccount()->orderBy('name')->pluck('name'),
            'user_defaults' => WimsChatSettings::USER_DEFAULTS,
            'canOpenWorkflow' => $me->hasRole('Super Admin'),
        ]);
    }

    public function update(Request $request)
    {
        $me = $this->guard();
        $d = $request->validate([
            'password' => 'required|string',
            'settings' => 'required|array',
            'settings.max_file_mb' => 'integer|min:1|max:'.WimsChatSettings::serverMaxMb(),
            'settings.max_files_per_message' => 'integer|min:1|max:20',
            'settings.daily_quota_default_mb' => 'integer|min:0|max:100000',
            'settings.daily_quota_roles' => 'array',
            'settings.daily_quota_roles.*' => 'nullable|integer|min:0|max:100000',
            'settings.image_compress' => 'boolean',
            'settings.image_max_px' => 'integer|min:640|max:6000',
            'settings.edit_window_minutes' => 'integer|min:0|max:100000',
            'settings.delete_window_minutes' => 'integer|min:0|max:100000',
            'settings.max_message_chars' => 'integer|min:50|max:20000',
            'settings.group_max_members' => 'integer|min:2|max:2000',
            'settings.nudge_cooldown_seconds' => 'integer|min:0|max:86400',
            'settings.typing_ttl_seconds' => 'integer|min:3|max:60',
            'settings.active_within_seconds' => 'integer|min:60|max:3600',
            'settings.away_timeout_minutes' => 'integer|min:5|max:1440',
            'settings.show_last_seen' => 'boolean',
            'settings.allow_manual_status' => 'boolean',
            'settings.read_receipts' => 'boolean',
            'settings.poll_open_ms' => 'integer|min:1500|max:30000',
            'settings.poll_idle_ms' => 'integer|min:10000|max:300000',
            'settings.page_size' => 'integer|min:10|max:100',
            'settings.attachment_retention_days' => ['integer', Rule::in([0, 30, 60, 90, 180, 365])],
            'settings.message_retention_days' => ['integer', Rule::in([0, 30, 60, 90, 180, 365])],
            'settings.group_invite_required' => 'boolean',
            'settings.voice_enabled' => 'boolean',
            'settings.voice_max_seconds' => 'integer|min:5|max:300',
            'settings.voice_max_kbps' => 'integer|min:16|max:512',
            'settings.themes' => 'array|min:1',
            'settings.themes.*' => [Rule::in(['ocean', 'teal', 'violet', 'rose', 'sunset', 'forest'])],
            'settings.search_min_chars' => 'integer|min:1|max:10',
            'settings.message_preview_chars' => 'integer|min:20|max:300',
            'settings.blocked_extensions' => 'array',
            'settings.blocked_extensions.*' => ['string', 'max:12', 'regex:/^[a-z0-9]+$/i'],
            'settings.reactions' => 'array|max:24',
            'settings.reactions.*' => 'string|max:16',
            'settings.statuses' => 'array|min:2|max:12',
            'settings.statuses.*.key' => ['required', 'string', 'max:20', 'regex:/^[a-z0-9_]+$/'],
            'settings.statuses.*.label' => 'required|string|max:40',
            'settings.statuses.*.color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.statuses.*.selectable' => 'boolean',
            'settings.statuses.*.quiet' => 'boolean',
            'settings.statuses.*.appears_as' => 'required|string|max:20',
            'settings.file_policies' => 'array',
            'settings.file_policies.*.label' => 'required|string|max:40',
            'settings.file_policies.*.enabled' => 'boolean',
            'settings.file_policies.*.extensions' => 'array',
            'settings.file_policies.*.extensions.*' => ['string', 'max:12', 'regex:/^[a-z0-9]+$/i'],
            'settings.file_policies.*.mimes' => 'array',
            'settings.file_policies.*.mimes.*' => ['string', 'max:120', 'regex:~^[a-z0-9.+\-]+/[a-z0-9.+\-]+$~i'],
            'settings.file_policies.*.max_mb' => 'nullable|integer|min:1|max:'.WimsChatSettings::serverMaxMb(),
            'settings.file_policies.*.preview' => 'boolean',
            'settings.file_policies.*.download' => 'boolean',
        ]);
        if (! Hash::check((string) $d['password'], $me->password)) {
            return response()->json(['errors' => ['password' => [__('The password you entered is incorrect.')]]], 422);
        }

        $cfg = $this->cfg($me);
        $before = $cfg->all();
        $changed = [];
        $quotaKeys = ['max_file_mb', 'daily_quota_default_mb', 'daily_quota_roles', 'file_policies', 'blocked_extensions', 'max_files_per_message'];
        $quotaChanged = false;
        foreach ($d['settings'] as $key => $value) {
            if (! array_key_exists($key, WimsChatSettings::DEFAULTS) && ! array_key_exists($key, WimsChatSettings::JSON_DEFAULTS)) {
                continue;
            }
            if ($key === 'daily_quota_roles') {
                $value = array_filter((array) $value, fn ($v) => $v !== null && $v !== '');
            }
            if ($key === 'blocked_extensions') {
                $value = array_values(array_unique(array_map('strtolower', (array) $value)));
            }
            if ($key === 'file_policies') {
                $value = collect((array) $value)->map(fn ($p) => [
                    'label' => $p['label'], 'enabled' => (bool) ($p['enabled'] ?? false),
                    'extensions' => array_values(array_unique(array_map('strtolower', (array) ($p['extensions'] ?? [])))),
                    'mimes' => array_values(array_unique(array_map('strtolower', (array) ($p['mimes'] ?? [])))),
                    'max_mb' => $p['max_mb'] ?? null, 'preview' => (bool) ($p['preview'] ?? false), 'download' => (bool) ($p['download'] ?? false),
                ])->all();
            }
            if ($key === 'reactions') {
                $value = array_values(array_unique(array_filter((array) $value)));
            }
            if ($key === 'statuses') {
                $value = collect((array) $value)->map(fn ($s) => [
                    'key' => $s['key'], 'label' => $s['label'], 'color' => $s['color'], 'selectable' => (bool) ($s['selectable'] ?? false),
                    'quiet' => (bool) ($s['quiet'] ?? false), 'appears_as' => $s['appears_as'],
                ])->unique('key')->values()->all();
            }
            if (json_encode($before[$key] ?? null) !== json_encode($value)) {
                $changed[] = $key;
                if (in_array($key, $quotaKeys, true)) {
                    $quotaChanged = true;
                }
            }
            $cfg->set($key, $value);
        }

        if ($changed) {
            WimsChatLogger::log($me, 'SETTINGS_CHANGE', null, ['changed' => $changed]);
            \App\Services\WimsChat\WimsChatNotifier::settingsChanged($me, count($changed));
        }
        if ($quotaChanged) {
            WimsChatLogger::log($me, 'QUOTA_CHANGE', null, ['changed' => array_values(array_intersect($changed, $quotaKeys))]);
        }

        return response()->json(['ok' => true, 'settings' => $this->cfg($me)->all()]);
    }
}
