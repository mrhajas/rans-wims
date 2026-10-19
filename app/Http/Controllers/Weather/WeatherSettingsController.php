<?php

namespace App\Http\Controllers\Weather;

use App\Models\User;
use App\Models\WeatherRiskRule;
use App\Services\Weather\RuleDefaults;
use App\Services\Weather\WeatherAccess;
use App\Services\Weather\WeatherAlerts;
use App\Services\Weather\WeatherManager;
use App\Services\Weather\WeatherRiskService;
use App\Services\Weather\WeatherSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

/**
 * Weather Settings: the provider and its key (manage-weather-settings) and the thresholds of the weather-sensitive activities
 * (manage-weather-rules). The key is encrypted at rest, never sent to the browser, never logged; replacing or removing it asks for the
 * signed-in person's password again.
 */
class WeatherSettingsController extends WeatherBaseController
{
    public function __construct(private WeatherManager $manager, private WeatherRiskService $risk)
    {
        parent::__construct();
    }

    private function open(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && ($user->can('manage-weather-settings') || $user->can('manage-weather-rules')) && WeatherAccess::feature('weather_risk', $user) && WeatherAccess::feature('weather_settings', $user), 403);

        return $user;
    }

    private function wrongPassword(Request $request, User $user)
    {
        if (! Hash::check((string) $request->input('password'), $user->password)) {
            return response()->json(['message' => __('The password you entered is incorrect.'), 'errors' => ['password' => [__('The password you entered is incorrect.')]]], 422);
        }

        return null;
    }

    public function index(Request $request)
    {
        $user = $this->open($request);
        $accountId = (int) $user->account_id;
        $rules = $this->risk->rules($accountId, false);
        $starting = RuleDefaults::all();

        return Inertia::render('Weather/Settings', [
            'prefs' => WeatherSettings::publicView($accountId),
            'defaults' => collect(WeatherSettings::DEFAULTS)->except(['api_key_enc', 'api_key_last4', 'api_key_set_at', 'api_key_set_by', 'last_test'])->all(),
            'providers' => collect(config('weather.providers'))->map(fn ($p, $k) => ['value' => $k, 'label' => $p['label'], 'requires_key' => $p['requires_key'], 'supports_alerts' => $p['supports_alerts'], 'note' => $p['note'], 'default_base' => $p['default_base'], 'commercial_base' => $p['commercial_base']])->values()->prepend(['value' => 'none', 'label' => __('No provider (weather switched off)'), 'requires_key' => false, 'supports_alerts' => false, 'note' => '', 'default_base' => null, 'commercial_base' => null])->all(),
            'configuration' => $this->manager->configuration($accountId),
            'rules' => $rules->map(fn (WeatherRiskRule $r) => $this->ruleRow($r, $starting[$r->activity_key] ?? null))->values(),
            'startingNotice' => RuleDefaults::NOTICE,
            'can' => ['settings' => $user->can('manage-weather-settings'), 'rules' => $user->can('manage-weather-rules'), 'workflow' => $user->hasRole('Super Admin')],
        ]);
    }

    private function ruleRow(WeatherRiskRule $r, ?array $start): array
    {
        $r->loadMissing('reviewer:id,name');
        $changed = false;
        if ($start) {
            $norm = fn ($v) => is_numeric($v) ? round((float) $v, 2) : $v;
            foreach (array_merge(WeatherRiskRule::THRESHOLDS, ['lookahead_hours', 'severity']) as $c) {
                if ($norm($r->{$c}) !== $norm($start[$c])) {
                    $changed = true;
                }
            }
        }

        return $r->only(['id', 'activity_key', 'label', 'keywords', 'lookahead_hours', 'severity', 'is_active', 'is_builtin', 'review_note']) + collect(WeatherRiskRule::THRESHOLDS)->mapWithKeys(fn ($c) => [$c => $r->{$c}])->all() + [
            'reviewed' => $r->isReviewed(), 'reviewed_by' => optional($r->reviewer)->name, 'reviewed_at' => optional($r->reviewed_at)->toDateTimeString(), 'starting' => $start ? collect(array_merge(WeatherRiskRule::THRESHOLDS, ['lookahead_hours', 'severity']))->mapWithKeys(fn ($c) => [$c => $start[$c]])->all() : null,
            'differs_from_starting' => $changed,
        ];
    }

    public function update(Request $request)
    {
        $user = $this->open($request);
        abort_unless($user->can('manage-weather-settings'), 403);
        $data = $request->validate([
            'provider' => ['required', 'in:none,' . implode(',', array_keys(config('weather.providers')))],
            'base_url' => ['nullable', 'string', 'max:200', function ($attr, $v, $fail) {
                if ($v !== null && $v !== '' && ! $this->safeUrl($v)) {
                    $fail(__('Use a full https:// address of the provider (no login in it, no local or private address).'));
                }
            }],
            'ttl_current_min' => ['required', 'integer', 'between:5,1440'], 'ttl_forecast_min' => ['required', 'integer', 'between:15,1440'], 'ttl_alerts_min' => ['required', 'integer', 'between:5,1440'],
            'stale_multiplier' => ['required', 'integer', 'between:2,12'], 'manual_refresh_min' => ['required', 'integer', 'between:1,120'], 'failure_backoff_min' => ['required', 'integer', 'between:1,720'],
            'refresh_on_view' => ['boolean'], 'forecast_hours' => ['required', 'integer', 'between:24,168'], 'timeout_seconds' => ['required', 'integer', 'between:3,30'], 'retries' => ['required', 'integer', 'between:0,3'],
            'warn_without_scheduled_work' => ['boolean'], 'reopen_increase_pct' => ['required', 'integer', 'between:5,300'], 'snapshot_retention_days' => ['required', 'integer', 'between:7,365'],
            'observation_edit_hours' => ['required', 'integer', 'between:1,720'], 'decisions' => ['required', 'array', 'min:1', 'max:10'], 'decisions.*' => ['required', 'string', 'max:120'],
        ]);
        $data['base_url'] = trim((string) ($data['base_url'] ?? ''));
        $accountId = (int) $user->account_id;
        $before = WeatherSettings::get($accountId);
        $after = WeatherSettings::save($data, $accountId);
        $changed = collect(WeatherSettings::EDITABLE)->filter(fn ($k) => ($before[$k] ?? null) !== ($after[$k] ?? null))->values()->all();
        log_activity('Weather settings updated', ['changed' => $changed, 'before' => collect($before)->only($changed)->all(), 'after' => collect($after)->only($changed)->all()], null, 'Weather');
        if ($changed) {
            WeatherAlerts::fire('settings_changed', null, __('Weather settings changed'), __('The Weather & Site Risk settings were changed by :n (:c).', ['n' => $user->name, 'c' => implode(', ', $changed)]), [], route('weather.settings.index'), $user, null, $accountId);
        }

        return back()->with('message', __('Weather settings saved.'));
    }

    /** https only, a real host name, nothing that points at this server or a private network. */
    private function safeUrl(string $v): bool
    {
        $p = parse_url($v);
        if (! $p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query'])) {
            return false;
        }
        $host = strtolower($p['host']);
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.localhost')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return str_contains($host, '.');
    }

    public function replaceKey(Request $request)
    {
        $user = $this->open($request);
        abort_unless($user->can('manage-weather-settings'), 403);
        $data = $request->validate(['password' => ['required', 'string'], 'api_key' => ['required', 'string', 'min:8', 'max:200', 'regex:/^[A-Za-z0-9_\-\.:~]+$/']]);
        if ($bad = $this->wrongPassword($request, $user)) {
            return $bad;
        }
        WeatherSettings::setKey($data['api_key'], $user->id, (int) $user->account_id);
        log_activity('Weather provider key replaced', ['by' => $user->id, 'note' => 'The key itself is never recorded.'], null, 'Weather');
        WeatherAlerts::fire('settings_changed', null, __('Weather settings changed'), __('The weather provider key was replaced by :n.', ['n' => $user->name]), [], route('weather.settings.index'), $user, null, (int) $user->account_id);

        return response()->json(['key' => WeatherSettings::keySummary((int) $user->account_id), 'configuration' => $this->manager->configuration((int) $user->account_id)]);
    }

    public function removeKey(Request $request)
    {
        $user = $this->open($request);
        abort_unless($user->can('manage-weather-settings'), 403);
        $request->validate(['password' => ['required', 'string']]);
        if ($bad = $this->wrongPassword($request, $user)) {
            return $bad;
        }
        WeatherSettings::removeKey((int) $user->account_id);
        log_activity('Weather provider key removed', ['by' => $user->id], null, 'Weather');
        WeatherAlerts::fire('settings_changed', null, __('Weather settings changed'), __('The weather provider key was removed by :n.', ['n' => $user->name]), [], route('weather.settings.index'), $user, null, (int) $user->account_id);

        return response()->json(['key' => WeatherSettings::keySummary((int) $user->account_id), 'configuration' => $this->manager->configuration((int) $user->account_id)]);
    }

    public function test(Request $request)
    {
        $user = $this->open($request);
        abort_unless($user->can('manage-weather-settings'), 403);
        $r = $this->manager->testConnection((int) $user->account_id);
        log_activity('Weather provider connection tested', ['ok' => $r['ok'], 'provider' => $r['provider'], 'message' => $r['message']], null, 'Weather');

        return response()->json($r);
    }

    // ------------------------------------------------------------------------------------------------------------------ thresholds

    private function rule(Request $request, int $id): array
    {
        $user = $this->open($request);
        abort_unless($user->can('manage-weather-rules'), 403);

        return [$user, WeatherRiskRule::query()->where('account_id', $user->account_id)->findOrFail($id)];
    }

    public function updateRule(Request $request, int $rule)
    {
        [$user, $r] = $this->rule($request, $rule);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'], 'keywords' => ['array', 'max:20'], 'keywords.*' => ['string', 'max:40'],
            'max_wind_kmh' => ['nullable', 'numeric', 'between:0,300'], 'max_gust_kmh' => ['nullable', 'numeric', 'between:0,300'], 'max_rain_probability_pct' => ['nullable', 'integer', 'between:0,100'],
            'max_precip_mm_per_hour' => ['nullable', 'numeric', 'between:0,300'], 'max_precip_mm_per_day' => ['nullable', 'numeric', 'between:0,2000'],
            'min_temp_c' => ['nullable', 'numeric', 'between:-30,70'], 'max_temp_c' => ['nullable', 'numeric', 'between:-30,70'],
            'lookahead_hours' => ['required', 'integer', 'between:1,168'], 'severity' => ['required', 'in:caution,warning'], 'is_active' => ['boolean'],
        ]);
        if (isset($data['min_temp_c'], $data['max_temp_c']) && $data['min_temp_c'] >= $data['max_temp_c']) {
            return response()->json(['message' => __('The lowest temperature must be below the highest.'), 'errors' => ['min_temp_c' => [__('The lowest temperature must be below the highest.')]]], 422);
        }
        $data['keywords'] = array_values(array_filter(array_map(fn ($k) => trim((string) $k), $data['keywords'] ?? [])));
        $watch = array_merge(WeatherRiskRule::THRESHOLDS, ['lookahead_hours', 'severity', 'is_active', 'label', 'keywords']);
        $before = $r->only($watch);
        $r->fill($data);
        $thresholdsMoved = collect(array_merge(WeatherRiskRule::THRESHOLDS, ['lookahead_hours', 'severity']))->contains(fn ($c) => $r->isDirty($c));
        if ($thresholdsMoved || $r->isDirty('keywords')) {
            $r->reviewed_by_user_id = null; // a changed threshold must be reviewed again
            $r->reviewed_at = null;
            $r->review_note = null;
        }
        $r->save();
        log_activity('Weather threshold changed', ['rule_id' => $r->id, 'activity' => $r->label, 'before' => $before, 'after' => $r->only($watch), 'review_cleared' => $thresholdsMoved], $r, 'Weather');
        WeatherAlerts::fire('thresholds_changed', null, __('Weather thresholds changed'), __(':n changed the weather thresholds for :a.', ['n' => $user->name, 'a' => $r->label]), [], route('weather.settings.index'), $user, null, (int) $user->account_id);
        $this->reevaluate((int) $user->account_id);

        return back()->with('message', __('Thresholds saved. They need to be reviewed again.'));
    }

    public function reviewRule(Request $request, int $rule)
    {
        [$user, $r] = $this->rule($request, $rule);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $r->update(['reviewed_by_user_id' => $user->id, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null]);
        log_activity('Weather thresholds marked as reviewed', ['rule_id' => $r->id, 'activity' => $r->label, 'reviewed_by' => $user->id, 'note' => $data['note'] ?? null], $r, 'Weather');
        WeatherAlerts::fire('thresholds_changed', null, __('Weather thresholds reviewed'), __(':n marked the thresholds for :a as reviewed.', ['n' => $user->name, 'a' => $r->label]), [], route('weather.settings.index'), $user, null, (int) $user->account_id);

        return back()->with('message', __('Marked as reviewed.'));
    }

    public function restoreRule(Request $request, int $rule)
    {
        [$user, $r] = $this->rule($request, $rule);
        abort_unless(RuleDefaults::restore($r), 422, __('Only the built-in activities have starting values to go back to.'));
        log_activity('Weather thresholds put back to starting values', ['rule_id' => $r->id, 'activity' => $r->label], $r, 'Weather');
        WeatherAlerts::fire('thresholds_changed', null, __('Weather thresholds changed'), __(':n put the thresholds for :a back to the starting values.', ['n' => $user->name, 'a' => $r->label]), [], route('weather.settings.index'), $user, null, (int) $user->account_id);
        $this->reevaluate((int) $user->account_id);

        return back()->with('message', __('Starting values restored.'));
    }

    /** New thresholds apply to the forecast already saved (no provider call). */
    private function reevaluate(int $accountId): void
    {
        try {
            WeatherAccess::scheduledProjects($accountId)->get()->each(fn ($w) => $this->risk->evaluate($w));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
