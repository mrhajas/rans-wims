<?php

namespace App\Http\Controllers\Weather;

use App\Models\SiteDiary;
use App\Models\User;
use App\Models\WeatherObservation;
use App\Models\WorkPackage;
use App\Services\Weather\WeatherAccess;
use App\Services\Weather\WeatherPresenter;
use App\Services\Weather\WeatherSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Weather seen on site, recorded by site staff. Kept apart from provider data and always labelled "Observed on site". */
class WeatherObservationController extends WeatherBaseController
{
    public function __construct(private WeatherPresenter $presenter)
    {
        parent::__construct();
    }

    public function index(Request $request)
    {
        $user = $this->allow($request, 'read-weather', 'weather_observations');
        $projectIds = WeatherAccess::projects($user, false)->pluck('warehouses.id')->all();
        $filters = [
            'project' => ctype_digit((string) $request->query('project', '')) ? (int) $request->query('project') : null,
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('from', '')) ? $request->query('from') : null,
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('to', '')) ? $request->query('to') : null,
        ];
        $q = WeatherObservation::query()->where('account_id', $user->account_id)->whereIn('warehouse_id', $projectIds ?: [0])
            ->when($filters['project'], fn ($x, $v) => $x->where('warehouse_id', $v))
            ->when($filters['from'], fn ($x, $v) => $x->where('observed_at', '>=', $v . ' 00:00:00'))
            ->when($filters['to'], fn ($x, $v) => $x->where('observed_at', '<=', $v . ' 23:59:59'))
            ->with(['warehouse:id,name', 'recorder:id,name', 'workPackage:id,title'])->orderByDesc('observed_at')->orderByDesc('id');
        $page = $q->paginate(20)->withQueryString();
        $prefs = WeatherSettings::get((int) $user->account_id);

        $recordable = $this->projectOptions($user);
        $ids = array_column($recordable, 'value');

        return Inertia::render('Weather/Observations', [
            'observations' => ['data' => collect($page->items())->map(fn ($o) => $this->presenter->observation($o) + ['can_edit' => $this->canChange($user, $o, $prefs)])->values(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]],
            'filters' => $filters,
            'projectOptions' => $this->projectOptions($user, false),
            'recordableProjects' => $recordable,
            'workPackages' => WorkPackage::query()->withoutGlobalScope('account')->whereIn('warehouse_id', $ids ?: [0])->where('status', '!=', WorkPackage::STATUS_COMPLETED)
                ->orderBy('title')->limit(500)->get(['id', 'warehouse_id', 'reference', 'title'])->map(fn ($w) => ['value' => (string) $w->id, 'label' => $w->reference . ' ' . $w->title, 'project' => (string) $w->warehouse_id])->values(),
            'options' => collect(WeatherSettings::options())->map(fn ($list) => collect($list)->map(fn ($label, $key) => ['value' => $key, 'label' => __($label)])->values())->all(),
            'can' => ['record' => $user->can('record-weather-observations'), 'overview' => true],
            'editHours' => (int) $prefs['observation_edit_hours'],
            'now' => now()->format('Y-m-d H:i'),
        ]);
    }

    private function canChange(User $user, WeatherObservation $o, array $prefs): bool
    {
        if ($user->can('manage-weather-settings')) {
            return true; // someone who runs the module may correct any record
        }

        return $user->can('record-weather-observations') && (int) $o->recorded_by === (int) $user->id && $o->created_at && $o->created_at->gte(now()->subHours((int) $prefs['observation_edit_hours']));
    }

    private function rules(): array
    {
        $opt = WeatherSettings::options();

        return [
            'warehouse_id' => ['required', 'integer'],
            'observed_at' => ['required', 'date_format:Y-m-d H:i'],
            'conditions' => ['required', Rule::in(array_keys($opt['conditions']))],
            'rainfall_level' => ['nullable', Rule::in(array_keys($opt['rainfall']))],
            'rainfall_mm' => ['nullable', 'numeric', 'between:0,500'],
            'temperature_c' => ['nullable', 'numeric', 'between:-20,60'],
            'wind_level' => ['nullable', Rule::in(array_keys($opt['wind']))],
            'wind_kmh' => ['nullable', 'numeric', 'between:0,250'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'work_package_id' => ['nullable', 'integer'],
        ];
    }

    private function clean(Request $request, User $user, ?int $warehouseId = null): array
    {
        $data = $request->validate($this->rules());
        $project = $this->project($user, $warehouseId ?? (int) $data['warehouse_id']);
        abort_unless(WeatherAccess::projects($user)->where('warehouses.id', $project->id)->exists(), 422, __('Weather can only be recorded for an active project.'));
        $at = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['observed_at'], config('weather.timezone'));
        if ($at->gt(now()->addMinutes(5))) {
            abort(response()->json(['message' => __('The time of the observation cannot be in the future.'), 'errors' => ['observed_at' => [__('The time of the observation cannot be in the future.')]]], 422));
        }
        if ($at->lt(now()->subDays(31))) {
            abort(response()->json(['message' => __('Observations older than 31 days cannot be recorded.'), 'errors' => ['observed_at' => [__('Observations older than 31 days cannot be recorded.')]]], 422));
        }
        if (! empty($data['work_package_id']) && ! WorkPackage::query()->withoutGlobalScope('account')->where('id', $data['work_package_id'])->where('warehouse_id', $project->id)->exists()) {
            abort(response()->json(['message' => __('That work package does not belong to this project.'), 'errors' => ['work_package_id' => [__('That work package does not belong to this project.')]]], 422));
        }
        $diary = SiteDiary::withoutGlobalScope('account')->where('warehouse_id', $project->id)->whereDate('diary_date', $at->toDateString())->where('status', '!=', 'Draft')->orderByDesc('id')->value('id');

        return [
            'warehouse_id' => $project->id, 'observed_at' => $at->format('Y-m-d H:i:s'), 'conditions' => $data['conditions'], 'rainfall_level' => $data['rainfall_level'] ?? null, 'rainfall_mm' => $data['rainfall_mm'] ?? null,
            'temperature_c' => $data['temperature_c'] ?? null, 'wind_level' => $data['wind_level'] ?? null, 'wind_kmh' => $data['wind_kmh'] ?? null, 'notes' => $data['notes'] ?? null,
            'work_package_id' => $data['work_package_id'] ?? null, 'diary_date' => $at->toDateString(), 'site_diary_id' => $diary,
        ];
    }

    public function store(Request $request)
    {
        $user = $this->allow($request, 'record-weather-observations', 'weather_observations');
        $row = WeatherObservation::query()->create($this->clean($request, $user) + ['account_id' => $user->account_id, 'recorded_by' => $user->id]);
        log_activity('Weather observation recorded', ['warehouse_id' => $row->warehouse_id, 'observation_id' => $row->id, 'observed_at' => $row->observed_at->toDateTimeString(), 'conditions' => $row->conditions, 'rainfall_level' => $row->rainfall_level, 'rainfall_mm' => $row->rainfall_mm,
            'temperature_c' => $row->temperature_c, 'wind_level' => $row->wind_level, 'wind_kmh' => $row->wind_kmh, 'site_diary_id' => $row->site_diary_id], $row, 'Weather');

        return $request->wantsJson() && ! $request->header('X-Inertia') ? response()->json(['id' => $row->id], 201) : back()->with('message', __('Weather observation recorded.'));
    }

    public function update(Request $request, int $observation)
    {
        $user = $this->allow($request, 'record-weather-observations', 'weather_observations');
        $o = WeatherObservation::query()->where('account_id', $user->account_id)->findOrFail($observation);
        $this->project($user, $o->warehouse_id);
        abort_unless($this->canChange($user, $o, WeatherSettings::get((int) $user->account_id)), 403);
        $before = $o->only(['observed_at', 'conditions', 'rainfall_level', 'rainfall_mm', 'temperature_c', 'wind_level', 'wind_kmh', 'notes', 'work_package_id']);
        $o->update($this->clean($request, $user, $o->warehouse_id));
        log_activity('Weather observation corrected', ['warehouse_id' => $o->warehouse_id, 'observation_id' => $o->id, 'before' => $before, 'after' => $o->only(array_keys($before))], $o, 'Weather');

        return $request->wantsJson() && ! $request->header('X-Inertia') ? response()->json(['ok' => true]) : back()->with('message', __('Weather observation updated.'));
    }

    public function destroy(Request $request, int $observation)
    {
        $user = $this->allow($request, 'record-weather-observations', 'weather_observations');
        $o = WeatherObservation::query()->where('account_id', $user->account_id)->findOrFail($observation);
        $this->project($user, $o->warehouse_id);
        abort_unless($this->canChange($user, $o, WeatherSettings::get((int) $user->account_id)), 403);
        $o->forceFill(['deleted_by' => $user->id])->save();
        $o->delete();
        log_activity('Weather observation removed', ['warehouse_id' => $o->warehouse_id, 'observation_id' => $o->id, 'observed_at' => $o->observed_at->toDateTimeString(), 'conditions' => $o->conditions], $o, 'Weather');

        return back()->with('message', __('Weather observation removed.'));
    }
}
