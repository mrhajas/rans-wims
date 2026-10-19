<?php

namespace App\Http\Controllers\SiteDiary;

use App\Models\Role;
use App\Models\SiteDiaryMedia;
use App\Models\SiteDiaryNotificationRule as Rule;
use App\Models\User;
use App\Services\SiteDiary\GoogleDriveClient;
use App\Services\SiteDiary\SiteDiaryLogger;
use App\Services\SiteDiary\SiteDiaryNotifier;
use App\Services\SiteDiary\SiteDiarySettings;
use App\Services\SiteDiary\SiteDiaryStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule as VRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Site Diary settings. Super-user only (manage-site-diary-settings); every change needs the user's password and is logged. */
class SiteDiarySettingsController extends SiteDiaryBaseController
{
    private function guard(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->can('manage-site-diary-settings'), 403);

        return $user;
    }

    private function wrongPassword(Request $request, User $user)
    {
        if (! Hash::check((string) $request->input('password'), $user->password)) {
            return response()->json(['errors' => ['password' => [__('The password you entered is incorrect.')]]], 422);
        }

        return null;
    }

    public function index(Request $request, GoogleDriveClient $drive)
    {
        $user = $this->guard($request);
        $s = $this->settings($user);

        return Inertia::render('SiteDiary/Settings', [
            // NOT named "settings": that key already carries WIMS's global settings (sidebar style, base URL …) on every page
            'sd_settings' => $s->all(),
            'server_max_mb' => SiteDiarySettings::serverMaxMb(),
            'redirect_uri' => route('site-diary.settings.google.callback'),
            'supported' => ['photo' => SiteDiarySettings::SUPPORTED_PHOTO_TYPES, 'video' => SiteDiarySettings::SUPPORTED_VIDEO_TYPES],
            'feature' => ['photos' => \App\Services\FeatureManager::isEnabled('site_diary_photos'), 'videos' => \App\Services\FeatureManager::isEnabled('site_diary_videos')],
            'drive' => $s->credentialSummary() + ['ready' => $drive->ready(), 'root_set' => (string) $s->get('drive_root_folder_id') !== ''],
            'queue' => [
                'waiting' => SiteDiaryMedia::whereIn('upload_status', ['pending', 'uploading'])->count(),
                'failed' => SiteDiaryMedia::where('upload_status', 'failed')->count(),
                'local' => SiteDiaryMedia::where('storage_provider', 'local')->where('upload_status', 'uploaded')->count(),
            ],
            'schedule' => ['send_time' => Rule::ruleFor($user->account_id, Rule::MISSING_DIARY)['send_time'] ?: '17:30'],
            'canOpenWorkflow' => $user->hasRole('Super Admin'),
            'projects' => $this->projects($user, false)->map(fn ($p) => $p + [
                'require_daily' => optional(DB::table('site_diary_project_rules')->where('warehouse_id', $p['value'])->first())->require_daily,
            ])->values(),
        ]);
    }

    /** Accepts a Drive folder ID or a full folder link and returns just the ID. */
    private function folderId(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v !== '' && preg_match('#/folders/([A-Za-z0-9_\-]{10,128})#', $v, $m)) {
            return $m[1];
        }
        if ($v !== '' && preg_match('#[?&]id=([A-Za-z0-9_\-]{10,128})#', $v, $m)) {
            return $m[1];
        }

        return $v === '' ? null : $v;
    }

    /**
     * One save for the whole page: limits, storage location, the Google credential fields (if typed), the per-project
     * daily-diary rules and the notification rules. While "WIMS Cloud" is the storage location nothing about Google
     * Drive is checked, so the rest of the page can always be saved; choosing "Google Drive" requires a working setup.
     */
    public function update(Request $request)
    {
        $user = $this->guard($request);
        $s = $this->settings($user);
        if ($request->has('settings.drive_root_folder_id')) {
            $request->merge(['settings' => array_merge((array) $request->input('settings'), ['drive_root_folder_id' => $this->folderId($request->input('settings.drive_root_folder_id'))])]);
        }
        $data = $request->validate([
            'password' => 'required|string',
            'settings' => 'required|array',
            'settings.photos_enabled' => 'boolean',
            'settings.max_photos' => 'integer|min:1|max:500',
            'settings.videos_enabled' => 'boolean',
            'settings.max_videos' => 'integer|min:1|max:100',
            'settings.max_photo_mb' => 'integer|min:1|max:500',
            'settings.max_video_mb' => 'integer|min:1|max:5000',
            'settings.photo_types' => 'array|min:1',
            'settings.photo_types.*' => ['string', VRule::in(SiteDiarySettings::SUPPORTED_PHOTO_TYPES)],
            'settings.video_types' => 'array|min:1',
            'settings.video_types.*' => ['string', VRule::in(SiteDiarySettings::SUPPORTED_VIDEO_TYPES)],
            'settings.drive_enabled' => 'boolean',
            'settings.drive_root_folder_id' => ['nullable', 'string', 'max:300'],
            'settings.thumbnails' => 'boolean',
            'settings.downloads_allowed' => 'boolean',
            'settings.password_on_delete' => 'boolean',
            'settings.remarks_max' => 'integer|min:100|max:20000',
            'settings.remarks_required' => 'boolean',
            'settings.require_media' => 'boolean',
            'settings.delete_files_on_remove' => 'boolean',
            'settings.drive_retention_days' => ['integer', VRule::in(SiteDiarySettings::RETENTION_CHOICES)],
            'settings.require_daily' => 'boolean',
            'settings.capture_max_px' => 'integer|min:640|max:6000',
            'project_rules' => 'nullable|array',
            'project_rules.*.warehouse_id' => 'required|integer',
            'project_rules.*.require_daily' => 'nullable|boolean',
            'missing_time' => 'nullable|date_format:H:i',
            'google' => 'nullable|array',
            'google.type' => 'nullable|in:service_account,oauth,oauth_client',
            'google.service_account_json' => 'nullable|string|max:20000',
            'google.client_id' => 'nullable|string|max:300',
            'google.client_secret' => 'nullable|string|max:300',
            'google.refresh_token' => 'nullable|string|max:1000',
        ]);
        if ($bad = $this->wrongPassword($request, $user)) {
            return $bad;
        }

        $useDrive = (bool) ($data['settings']['drive_enabled'] ?? false);
        $warnings = [];
        $before = $s->all();

        DB::transaction(function () use ($data, $user, $s, $useDrive, &$warnings) {
            // 1 · the plain settings
            foreach ($data['settings'] as $key => $value) {
                if (! array_key_exists($key, SiteDiarySettings::DEFAULTS)) {
                    continue;
                }
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
                if (is_bool($value)) {
                    $value = $value ? 1 : 0;
                }
                $s->set($key, $value ?? '');
            }

            // 2 · a Google credential typed on this page (only the fields that were filled in)
            $g = array_filter((array) ($data['google'] ?? []), fn ($v) => $v !== null && $v !== '');
            if (! empty($g['type'])) {
                $cred = null;
                $problem = null;
                if ($g['type'] === 'service_account') {
                    $json = json_decode($g['service_account_json'] ?? '', true);
                    if (! is_array($json) || empty($json['client_email']) || empty($json['private_key']) || ! @openssl_pkey_get_private($json['private_key'])) {
                        $problem = __('The service-account key file is not valid.');
                    } else {
                        $cred = ['type' => 'service_account', 'client_email' => $json['client_email'], 'private_key' => $json['private_key']];
                    }
                } elseif (! empty($g['client_id']) && ! empty($g['client_secret'])) {
                    $old = $s->credentials();
                    $keepToken = ($old['type'] ?? '') === 'oauth' && ($old['client_id'] ?? '') === $g['client_id'] && ! empty($old['refresh_token']);
                    $cred = $g['type'] === 'oauth' && ! empty($g['refresh_token'])
                        ? ['type' => 'oauth', 'client_id' => $g['client_id'], 'client_secret' => $g['client_secret'], 'refresh_token' => $g['refresh_token']]
                        : ($keepToken
                            ? ['type' => 'oauth', 'client_id' => $g['client_id'], 'client_secret' => $g['client_secret'], 'refresh_token' => $old['refresh_token'], 'identity' => $old['identity'] ?? null]
                            : ['type' => 'oauth_client', 'client_id' => $g['client_id'], 'client_secret' => $g['client_secret']]);
                } else {
                    $problem = __('Enter both the client ID and the client secret.');
                }
                if ($cred) {
                    $s->storeCredentials($cred);
                    $s->set('drive_auth_type', $cred['type'] === 'service_account' ? 'service_account' : 'oauth');
                } elseif ($problem) {
                    if ($useDrive) {
                        throw ValidationException::withMessages(['google' => $problem]);
                    }
                    $warnings[] = $problem.' '.__('(Not saved, it is not needed while WIMS Cloud is the storage location.)');
                }
            }

            // 3 · Google Drive only becomes the storage location when it is really set up. Otherwise everything else on the
            //     page is still saved, storage simply stays on WIMS Cloud, and the person is told why.
            if ($useDrive) {
                $c = $s->credentials();
                $usable = $c && (($c['type'] ?? '') === 'service_account' || (($c['type'] ?? '') === 'oauth' && ! empty($c['refresh_token'])));
                if (! $usable) {
                    $s->set('drive_enabled', 0);
                    $warnings[] = __('Everything was saved, but Google Drive is not connected yet, so storage stays on WIMS Cloud. Press “Connect Google account”, then choose Google Drive again.');
                } elseif (! preg_match('/^[A-Za-z0-9_\-]{20,128}$/', (string) $s->get('drive_root_folder_id'))) {
                    $s->set('drive_enabled', 0);
                    $warnings[] = __('Everything was saved, but the Google Drive storage folder is not set up, so storage stays on WIMS Cloud. Press “Connect Google account” again to create it.');
                }
            }

            // 4 · per-project "daily diary" rules
            $allowed = $this->projects($user, false)->pluck('value')->map(fn ($v) => (int) $v)->all();
            foreach ($data['project_rules'] ?? [] as $rule) {
                if (! in_array((int) $rule['warehouse_id'], $allowed, true)) {
                    continue;
                }
                if ($rule['require_daily'] === null) {
                    DB::table('site_diary_project_rules')->where('warehouse_id', $rule['warehouse_id'])->delete();
                } else {
                    DB::table('site_diary_project_rules')->updateOrInsert(['warehouse_id' => $rule['warehouse_id']], ['require_daily' => (bool) $rule['require_daily'] ? 1 : 0, 'updated_at' => now(), 'created_at' => now()]);
                }
            }

            // 5 · the time of the daily "diary missing" message (who gets it is set on the Site Diary workflow page)
            if (! empty($data['missing_time'])) {
                $this->saveMissingTime($data['missing_time'], $user);
            }
        });

        $after = $s->forAccount($user->account_id)->all();
        $diff = [];
        foreach ($after as $k => $v) {
            if ((string) $v !== (string) ($before[$k] ?? '')) {
                $diff[$k] = ['from' => $before[$k] ?? null, 'to' => $v];
            }
        }
        SiteDiaryLogger::log($user, null, 'SETTINGS_CHANGE', null, ['changes' => $diff + (! empty($data['google']['type']) ? ['google_credential' => 'updated'] : []) + (! empty($data['missing_time']) ? ['missing_diary_time' => $data['missing_time']] : [])]);
        $names = array_keys($diff + (! empty($data['google']['type']) ? ['google_credential' => 1] : []) + (! empty($data['missing_time']) ? ['missing_diary_time' => 1] : []));
        if ($names) {
            SiteDiaryNotifier::event(Rule::SETTINGS_CHANGED, $user, null, ['changes' => str_replace('_', ' ', implode(', ', $names))]);
        }

        return response()->json(['success' => true, 'settings' => $after, 'warnings' => $warnings]);
    }

    private function saveMissingTime(string $time, User $user): void
    {
        $rule = Rule::ruleFor($user->account_id, Rule::MISSING_DIARY);
        Rule::updateOrCreate(['account_id' => $user->account_id, 'event_key' => Rule::MISSING_DIARY], [
            'send_time' => $time, 'enabled' => $rule['enabled'], 'notify_bell' => $rule['notify_bell'], 'send_email' => $rule['send_email'], 'exclude_actor' => $rule['exclude_actor'],
            'role_names' => $rule['role_names'], 'user_ids' => $rule['user_ids'],
        ]);
    }

    /** Saves (or replaces) the Google credential. It is encrypted and never shown again. */
    public function credentials(Request $request)
    {
        $user = $this->guard($request);
        $data = $request->validate([
            'password' => 'required|string',
            'type' => 'required|in:service_account,oauth,oauth_client',
            'service_account_json' => 'required_if:type,service_account|nullable|string|max:20000',
            'client_id' => 'required_if:type,oauth,oauth_client|nullable|string|max:300',
            'client_secret' => 'required_if:type,oauth,oauth_client|nullable|string|max:300',
            'refresh_token' => 'required_if:type,oauth|nullable|string|max:1000',
        ]);
        if ($bad = $this->wrongPassword($request, $user)) {
            return $bad;
        }

        if ($data['type'] === 'service_account') {
            $json = json_decode($data['service_account_json'], true);
            if (! is_array($json) || empty($json['client_email']) || empty($json['private_key']) || ! @openssl_pkey_get_private($json['private_key'])) {
                return response()->json(['errors' => ['service_account_json' => [__('This is not a valid Google service-account key file.')]]], 422);
            }
            $cred = ['type' => 'service_account', 'client_email' => $json['client_email'], 'private_key' => $json['private_key']];
        } elseif ($data['type'] === 'oauth_client') {
            // Step 1 of "Connect Google account": remember the client; the refresh token arrives after the consent screen.
            $cred = ['type' => 'oauth_client', 'client_id' => $data['client_id'], 'client_secret' => $data['client_secret']];
        } else {
            $cred = ['type' => 'oauth', 'client_id' => $data['client_id'], 'client_secret' => $data['client_secret'], 'refresh_token' => $data['refresh_token']];
        }
        $this->settings($user)->storeCredentials($cred);
        $this->settings($user)->set('drive_auth_type', $data['type']);
        SiteDiaryLogger::log($user, null, 'SETTINGS_CHANGE', null, ['changes' => ['google_drive_credentials' => 'replaced']]);
        SiteDiaryNotifier::event(Rule::SETTINGS_CHANGED, $user, null, ['changes' => 'Google Drive credentials replaced']);

        return response()->json(['success' => true, 'drive' => $this->settings($user)->credentialSummary()]);
    }

    /** Step 2: returns the Google consent-screen address; the browser then goes there. */
    public function connect(Request $request)
    {
        $user = $this->guard($request);
        $request->validate(['password' => 'required|string']);
        if ($bad = $this->wrongPassword($request, $user)) {
            return $bad;
        }
        $c = $this->settings($user)->credentials();
        if (! $c || empty($c['client_id']) || ! in_array($c['type'] ?? '', ['oauth_client', 'oauth'], true)) {
            return response()->json(['errors' => ['client_id' => [__('Save the Google client ID and secret first.')]]], 422);
        }
        $state = \Illuminate\Support\Str::random(40);
        $request->session()->put('site_diary_google_state', ['state' => $state, 'user' => $user->id, 'at' => now()->timestamp]);

        return response()->json(['url' => GoogleDriveClient::authUrl($c['client_id'], route('site-diary.settings.google.callback'), $state)]);
    }

    /** Google sends the person back here with a one-time code. */
    public function callback(Request $request, GoogleDriveClient $drive)
    {
        $user = $this->guard($request);
        $saved = (array) $request->session()->pull('site_diary_google_state', []);
        $back = redirect()->route('site-diary.settings');
        if (! $request->filled('state') || empty($saved['state']) || ! hash_equals($saved['state'], (string) $request->query('state')) || (int) ($saved['user'] ?? 0) !== $user->id || now()->timestamp - (int) ($saved['at'] ?? 0) > 900) {
            return $back->with('error', __('The Google sign-in could not be verified. Please start again.'));
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return $back->with('error', __('Google sign-in was cancelled or refused (:e).', ['e' => $request->query('error', 'no code')]));
        }

        $s = $this->settings($user);
        $c = $s->credentials();
        if (! $c || empty($c['client_id'])) {
            return $back->with('error', __('Save the Google client ID and secret first.'));
        }
        try {
            $tokens = $drive->exchangeCode($c['client_id'], $c['client_secret'], (string) $request->query('code'), route('site-diary.settings.google.callback'));
            $s->storeCredentials(['type' => 'oauth', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'refresh_token' => $tokens['refresh_token'], 'identity' => $drive->accountEmail($tokens['access_token'])]);
            $s->set('drive_auth_type', 'oauth');
            // What is in the folder box is a folder NAME to create (or a real folder id from an earlier connect).
            $typed = trim((string) $s->get('drive_root_folder_id'));
            if (! preg_match('/^[A-Za-z0-9_\-]{20,128}$/', $typed)) {
                \Illuminate\Support\Facades\Cache::forget('site_diary_drive_token_'.md5(json_encode(['oauth', $c['client_id']])));
                $s->set('drive_root_folder_id', app(GoogleDriveClient::class)->createRootFolder($typed !== '' ? mb_substr($typed, 0, 80) : 'WIMS Site Diaries'));
            }
        } catch (\Throwable $e) {
            report($e);

            return $back->with('error', __('Google Drive Connection Problem').': '.$e->getMessage());
        }
        SiteDiaryLogger::log($user, null, 'SETTINGS_CHANGE', null, ['changes' => ['google_drive' => 'connected via Google sign-in']]);
        SiteDiaryNotifier::event(Rule::SETTINGS_CHANGED, $user, null, ['changes' => 'Google Drive connected through Google sign-in']);

        return $back->with('message', __('Google Drive is connected. Choose “Google Drive” as the storage location and press Save Settings to start using it.'));
    }

    public function disconnect(Request $request)
    {
        $user = $this->guard($request);
        $request->validate(['password' => 'required|string']);
        if ($bad = $this->wrongPassword($request, $user)) {
            return $bad;
        }
        $this->settings($user)->storeCredentials(null);
        $this->settings($user)->set('drive_enabled', 0);
        SiteDiaryLogger::log($user, null, 'SETTINGS_CHANGE', null, ['changes' => ['google_drive_credentials' => 'removed']]);
        SiteDiaryNotifier::event(Rule::SETTINGS_CHANGED, $user, null, ['changes' => 'Google Drive disconnected']);

        return response()->json(['success' => true]);
    }

    public function test(Request $request, GoogleDriveClient $drive)
    {
        $this->guard($request);
        if (! $drive->configured()) {
            return response()->json(['ok' => false, 'message' => __('Save the Google credential and the Drive folder ID first.')], 422);
        }
        try {
            $r = $drive->test();

            return response()->json(['ok' => true, 'message' => __('Connected. Files will be stored under the folder “:name”.', ['name' => $r['name']])]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => __('Google Drive Connection Problem').': '.$e->getMessage()], 422);
        }
    }

    /** Pushes a small batch of waiting / failed files to Drive right now (the scheduled command does the same every few minutes). */
    public function sync(Request $request, SiteDiaryStorage $storage, GoogleDriveClient $drive)
    {
        $user = $this->guard($request);
        if (! $drive->ready()) {
            return response()->json(['ok' => false, 'message' => __('Google Drive is not switched on and connected.')], 422);
        }
        $done = 0;
        $failed = 0;
        SiteDiaryMedia::where(fn ($q) => $q->whereIn('upload_status', ['pending', 'failed'])->orWhere(fn ($q) => $q->where('storage_provider', 'local')->where('upload_status', 'uploaded')))
            ->orderBy('id')->limit(10)->get()->each(function ($m) use ($storage, &$done, &$failed, $user) {
                $storage->push($m) ? $done++ : $failed++;
                $m->refresh();
                SiteDiaryLogger::log($user, $m->diary, $m->upload_status === 'failed' ? 'DRIVE_UPLOAD_FAILED' : 'DRIVE_UPLOAD', $m);
            });

        if ($failed > 0) {
            SiteDiaryNotifier::event(Rule::DRIVE_UPLOAD_FAILED, $user, null, ['filename' => $failed . ' file(s)', 'changes' => 'the manual sync could not send them', 'account_id' => $user->account_id]);
        }

        return response()->json(['ok' => true, 'done' => $done, 'failed' => $failed]);
    }

    /** Space used / left in the connected Google account (loaded after the page opens so it never slows the page). */
    public function usage(Request $request, GoogleDriveClient $drive)
    {
        $user = $this->guard($request);
        if (! $drive->configured()) {
            return response()->json(['ok' => false, 'message' => __('Google Drive is not connected.')], 422);
        }
        try {
            $q = $drive->quota();
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => __('Google Drive Connection Problem').': '.$e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $q + [
            'wims_bytes' => (int) SiteDiaryMedia::where('storage_provider', 'google_drive')->whereNotNull('drive_file_id')->whereNull('expired_at')->sum('file_size'),
            'wims_files' => SiteDiaryMedia::where('storage_provider', 'google_drive')->whereNotNull('drive_file_id')->whereNull('expired_at')->count(),
        ]);
    }

    /** Erases every Site Diary file inside the storage folder (the folder stays). Needs the password and is logged. */
    public function clearDrive(Request $request, GoogleDriveClient $drive)
    {
        $user = $this->guard($request);
        $request->validate(['password' => 'required|string']);
        if ($bad = $this->wrongPassword($request, $user)) {
            return $bad;
        }
        if (! $drive->configured()) {
            return response()->json(['ok' => false, 'message' => __('Google Drive is not connected.')], 422);
        }
        try {
            $r = $drive->clearRoot();
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => __('Google Drive Connection Problem').': '.$e->getMessage()], 422);
        }
        // every record that pointed at an erased file now says so, instead of failing when somebody opens it
        $marked = SiteDiaryMedia::withTrashed()->whereNotNull('drive_file_id')->whereNull('expired_at')
            ->update(['drive_file_id' => null, 'drive_folder_id' => null, 'expired_at' => now()]);
        SiteDiaryLogger::log($user, null, 'DRIVE_CLEARED', null, ['files' => $r['files'], 'folders' => $r['folders'], 'skipped' => $r['skipped'], 'records_marked' => $marked]);
        SiteDiaryNotifier::event(Rule::SETTINGS_CHANGED, $user, null, ['changes' => 'Google Drive storage cleared (' . $r['files'] . ' files erased)']);

        return response()->json(['ok' => true] + $r + ['records' => $marked]);
    }
}
