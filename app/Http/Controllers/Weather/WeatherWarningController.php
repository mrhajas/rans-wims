<?php

namespace App\Http\Controllers\Weather;

use App\Models\Warehouse;
use App\Models\WeatherWarning;
use App\Services\Weather\WeatherAccess;
use App\Services\Weather\WeatherAlerts;
use App\Services\Weather\WeatherSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** The list of weather warnings and the record of who acknowledged them. Acknowledging changes nothing else: no work package, no status. */
class WeatherWarningController extends WeatherBaseController
{
    public function index(Request $request)
    {
        $user = $this->allow($request, 'read-weather', 'weather_warnings');
        $projectIds = WeatherAccess::projects($user, false)->pluck('warehouses.id')->all();
        $status = in_array($request->query('status'), ['open', 'acknowledged', 'expired', 'dismissed', 'all'], true) ? $request->query('status') : 'active';
        $filters = [
            'project' => ctype_digit((string) $request->query('project', '')) ? (int) $request->query('project') : null, 'status' => $status,
            'kind' => in_array($request->query('kind'), ['forecast_rule', 'provider_alert'], true) ? $request->query('kind') : null,
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('from', '')) ? $request->query('from') : null,
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('to', '')) ? $request->query('to') : null,
        ];
        $q = WeatherWarning::query()->where('account_id', $user->account_id)->whereIn('warehouse_id', $projectIds ?: [0])
            ->when($filters['project'], fn ($x, $v) => $x->where('warehouse_id', $v))
            ->when($status === 'active', fn ($x) => $x->whereIn('status', ['open', 'acknowledged'])->where(fn ($y) => $y->whereNull('window_end')->orWhere('window_end', '>=', now()->subHours(6))))
            ->when(! in_array($status, ['active', 'all'], true), fn ($x) => $x->where('status', $status))
            ->when($filters['kind'], fn ($x, $v) => $x->where('kind', $v))
            ->when($filters['from'], fn ($x, $v) => $x->where('raised_at', '>=', $v . ' 00:00:00'))
            ->when($filters['to'], fn ($x, $v) => $x->where('raised_at', '<=', $v . ' 23:59:59'))
            ->with(['warehouse:id,name', 'acknowledger:id,name', 'workPackage:id,reference,title'])->orderByRaw("FIELD(status, 'open', 'acknowledged', 'expired', 'dismissed')")->orderByDesc('raised_at');
        $page = $q->paginate(15)->withQueryString();
        $prefs = WeatherSettings::get((int) $user->account_id);

        return Inertia::render('Weather/Warnings', [
            'warnings' => ['data' => collect($page->items())->map(fn (WeatherWarning $x) => $this->row($x))->values(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]],
            'filters' => $filters,
            'projectOptions' => $this->projectOptions($user, false),
            'decisions' => array_values((array) $prefs['decisions']),
            'counts' => WeatherWarning::query()->where('account_id', $user->account_id)->whereIn('warehouse_id', $projectIds ?: [0])->whereIn('status', ['open'])->count(),
            'can' => ['acknowledge' => $user->can('acknowledge-weather-warnings'), 'settings' => $user->can('manage-weather-rules')],
            'focus' => ctype_digit((string) $request->query('warning', '')) ? (int) $request->query('warning') : null,
        ]);
    }

    private function row(WeatherWarning $x): array
    {
        return [
            'id' => $x->id, 'project_id' => $x->warehouse_id, 'project' => optional($x->warehouse)->name, 'kind' => $x->kind, 'severity' => $x->severity, 'status' => $x->status, 'title' => $x->title, 'message' => $x->message,
            'window_start' => optional($x->window_start)->toDateTimeString(), 'window_end' => optional($x->window_end)->toDateTimeString(), 'raised_at' => optional($x->raised_at)->toDateTimeString(),
            'provider' => $x->details['forecast_provider'] ?? $x->source_provider, 'uncertainty' => $x->details['uncertainty'] ?? null, 'notice' => $x->details['notice'] ?? null, 'starting_values' => (bool) ($x->details['starting_values'] ?? false),
            'work_package' => $x->workPackage ? $x->workPackage->reference . ' ' . $x->workPackage->title : ($x->details['work_package']['title'] ?? null),
            'acknowledged_by' => optional($x->acknowledger)->name, 'acknowledged_at' => optional($x->acknowledged_at)->toDateTimeString(), 'acknowledge_note' => $x->acknowledge_note, 'acknowledge_decision' => $x->acknowledge_decision,
            'reopened_because' => $x->details['reopened_because'] ?? null, 'expired_reason' => $x->details['expired_reason'] ?? null,
        ];
    }

    public function acknowledge(Request $request, int $warning)
    {
        $user = $this->allow($request, 'acknowledge-weather-warnings', 'weather_warnings');
        $x = WeatherWarning::query()->where('account_id', $user->account_id)->findOrFail($warning);
        $project = $this->project($user, $x->warehouse_id);
        $data = $request->validate([
            'outcome' => ['required', 'in:acknowledged,dismissed'],
            'decision' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if (($data['outcome'] === 'dismissed' || ! in_array($data['decision'], (array) WeatherSettings::get((int) $user->account_id)['decisions'], true)) && mb_strlen(trim((string) ($data['note'] ?? ''))) < 3) {
            return response()->json(['message' => __('Please write a short note: it is required when a warning is marked not applicable or when the decision is not one of the listed ones.'), 'errors' => ['note' => [__('A short note is required here.')]]], 422);
        }
        if ($x->status !== WeatherWarning::OPEN) {
            return response()->json(['message' => __('This warning is no longer open (:s).', ['s' => $x->status]), 'errors' => ['status' => [__('Only an open warning can be acknowledged.')]]], 422);
        }
        $x->update([
            'status' => $data['outcome'] === 'dismissed' ? WeatherWarning::DISMISSED : WeatherWarning::ACKNOWLEDGED, 'acknowledged_by' => $user->id, 'acknowledged_at' => now(), 'acknowledge_note' => $data['note'] ?? null,
            'acknowledge_decision' => $data['decision'], 'acknowledged_peak' => $x->peak_value, 'details' => array_merge((array) $x->details, ['message_at_acknowledgement' => $x->message]),
        ]);
        log_activity($data['outcome'] === 'dismissed' ? 'Weather warning marked not applicable' : 'Weather warning acknowledged', [
            'warehouse_id' => $x->warehouse_id, 'project' => $project->name, 'warning_id' => $x->id, 'title' => $x->title, 'message' => $x->message, 'decision' => $data['decision'], 'note' => $data['note'] ?? null,
            'acknowledged_by' => $user->id, 'work_package_id' => $x->work_package_id, 'note_work' => 'Recording this changes no work package and no status.',
        ], $x, 'Weather');
        WeatherAlerts::fire('warning_acknowledged', $project, $x->title, $user->name . ' acknowledged this warning for ' . $project->name . '. Decision: ' . $data['decision'] . '.', ['decision' => $data['decision'], 'activity' => $x->details['activity'] ?? ''],
            route('weather.warnings.index', ['project' => $project->id, 'status' => 'all']), $user, 'weather_risk|ack|' . $x->id, (int) $project->account_id);

        return $request->wantsJson() && ! $request->header('X-Inertia') ? response()->json(['ok' => true]) : back()->with('message', __('Recorded. Nothing else was changed.'));
    }
}
