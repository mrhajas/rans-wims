<?php

namespace App\Http\Controllers\Weather;

use App\Models\Warehouse;
use App\Services\Weather\WeatherAccess;
use App\Services\Weather\WeatherManager;
use App\Services\Weather\WeatherPresenter;
use App\Services\Weather\WeatherRiskService;
use App\Services\Weather\WeatherSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Weather Overview: one card per project (current conditions, forecast, activity risk, open warnings), refreshing and location. */
class WeatherController extends WeatherBaseController
{
    public function __construct(private WeatherManager $manager, private WeatherPresenter $presenter, private WeatherRiskService $risk)
    {
        parent::__construct();
    }

    public function index(Request $request)
    {
        $user = $this->allow($request, 'read-weather');
        $projects = WeatherAccess::projects($user)->whereNull('warehouses.deleted_at')->orderBy('warehouses.name')->get();
        $conf = $this->manager->configuration((int) $user->account_id);
        $prefs = WeatherSettings::get((int) $user->account_id);

        return Inertia::render('Weather/Index', [
            'cards' => $projects->map(fn (Warehouse $w) => $this->presenter->card($w, $user))->values(),
            'configuration' => $conf,
            'prefs' => ['ttl_current_min' => (int) $prefs['ttl_current_min'], 'ttl_forecast_min' => (int) $prefs['ttl_forecast_min'], 'manual_refresh_min' => (int) $prefs['manual_refresh_min'], 'refresh_on_view' => (bool) $prefs['refresh_on_view']],
            'projectOptions' => $this->projectOptions($user),
            'focus' => ctype_digit((string) $request->query('project', '')) ? (int) $request->query('project') : null,
            'can' => $this->abilities($user),
        ]);
    }

    /** One project's card as JSON. With ?fetch=1 an out-of-date location is refreshed first (at most one provider call, shared by projects at the same place). */
    public function card(Request $request, Warehouse $warehouse)
    {
        $user = $this->allow($request, 'read-weather');
        $w = $this->project($user, $warehouse);
        if ($request->boolean('fetch') && WeatherSettings::get((int) $w->account_id)['refresh_on_view']) {
            $state = $this->manager->ensure($w, 'view', $user);
            if ($state['fetched'] ?? false) {
                $this->risk->evaluate($w, $state);
            }
        }

        return response()->json($this->presenter->card($w, $user));
    }

    public function refresh(Request $request, Warehouse $warehouse)
    {
        $user = $this->allow($request, 'read-weather');
        $w = $this->project($user, $warehouse);
        $wait = $this->manager->cooldownRemaining($w);
        if ($wait > 0) {
            return response()->json(['message' => __('This location was refreshed a moment ago. Try again in :s seconds.', ['s' => $wait]), 'retry_after' => $wait] + ['card' => $this->presenter->card($w, $user)], 429);
        }
        $state = $this->manager->ensure($w, 'manual', $user, true);
        log_activity('Weather refreshed by hand', ['warehouse_id' => $w->id, 'project' => $w->name, 'status' => $state['status'], 'provider' => $state['provider'], 'fetched' => $state['fetched'] ?? false], null, 'Weather');
        $raised = $state['fetched'] ?? false ? $this->risk->evaluate($w, $state) : null;

        return response()->json(['card' => $this->presenter->card($w, $user), 'evaluation' => $raised]);
    }

    /** Suggests coordinates for a project from its address (or a place typed by the person). Saves nothing. */
    public function geocode(Request $request, Warehouse $warehouse, \App\Services\Weather\Geocoder $geocoder)
    {
        $user = $this->allow($request, 'manage-weather-locations', 'weather_locations');
        $w = $this->project($user, $warehouse);
        $typed = trim(strip_tags((string) $request->query('q', '')));
        abort_if(mb_strlen($typed) > 160, 422);
        $result = $typed !== '' ? $geocoder->lookup($typed, '', (int) $w->account_id) : $geocoder->lookup((string) $w->address, (string) $w->name, (int) $w->account_id);
        log_activity('Weather location looked up', ['warehouse_id' => $w->id, 'project' => $w->name, 'searched' => $result['tried'], 'source' => $result['source'], 'found' => count($result['candidates'])], $w, 'Weather');

        return response()->json($result);
    }

    public function location(Request $request, Warehouse $warehouse)
    {
        $user = $this->allow($request, 'manage-weather-locations', 'weather_locations');
        $w = $this->project($user, $warehouse);
        $data = $request->validate([
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ]);
        $lat = isset($data['latitude']) ? round((float) $data['latitude'], 7) : null;
        $lng = isset($data['longitude']) ? round((float) $data['longitude'], 7) : null;
        if ($lat !== null && $lat == 0.0 && $lng == 0.0) {
            return response()->json(['message' => __('Latitude 0 and longitude 0 is a point in the ocean, not a project location.'), 'errors' => ['latitude' => [__('Enter the real coordinates of the site.')]]], 422);
        }
        $before = ['latitude' => $w->latitude, 'longitude' => $w->longitude];
        // the project's own attribute logging is bypassed on purpose: the change is logged below, once, as a Weather entry
        $w->forceFill(['latitude' => $lat, 'longitude' => $lng])->saveQuietly();
        log_activity('Weather location of the project changed', ['warehouse_id' => $w->id, 'project' => $w->name, 'before' => $before, 'after' => ['latitude' => $lat, 'longitude' => $lng]], $w, 'Weather');

        $outside = $lat !== null && ($lat < -12 || $lat > -0.9 || $lng < 29 || $lng > 41.1);

        return response()->json(['card' => $this->presenter->card($w->fresh(), $user), 'warning' => $outside ? __('These coordinates are outside Tanzania. Check them against the site address.') : null]);
    }

    private function abilities($user): array
    {
        return [
            'location' => $user->can('manage-weather-locations') && WeatherAccess::feature('weather_locations', $user),
            'observe' => $user->can('record-weather-observations') && WeatherAccess::feature('weather_observations', $user),
            'warnings' => WeatherAccess::feature('weather_warnings', $user),
            'acknowledge' => $user->can('acknowledge-weather-warnings') && WeatherAccess::feature('weather_warnings', $user),
            'reports' => WeatherAccess::feature('weather_reports', $user),
            'settings' => ($user->can('manage-weather-settings') || $user->can('manage-weather-rules')) && WeatherAccess::feature('weather_settings', $user),
            'workflow' => $user->hasRole('Super Admin'),
        ];
    }
}
