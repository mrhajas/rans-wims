<?php

namespace App\Services\Weather;

use App\Models\Warehouse;
use App\Models\WeatherRiskRule;
use App\Models\WeatherWarning;
use App\Models\WorkPackage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns a fresh forecast into advisory warnings for the weather-sensitive activities, and keeps them tidy:
 *  - one warning per project + activity + scheduled work + day (the fingerprint), so a refresh never raises a duplicate;
 *  - an open warning follows the latest forecast (window, figures), disappears (expired) when the forecast no longer goes past the threshold;
 *  - an acknowledged warning is never reopened by a small change, only when the forecast is clearly worse than when it was acknowledged;
 *  - provider alerts (where the provider supplies them) become warnings of their own kind.
 * Nothing here touches a work package: it never starts, stops, approves or forbids any work.
 */
class WeatherRiskService
{
    public function __construct(private WeatherManager $manager)
    {
    }

    /** @var array<string,Collection> the rules read once per service object (a page with 36 project cards asked for them 36 times) */
    private array $ruleMemo = [];

    /** @var array<int,Collection>|null scheduled work per project, loaded for all the cards of a page at once */
    private ?array $workMemo = null;

    public function rules(int $accountId, bool $activeOnly = true): Collection
    {
        RuleDefaults::ensure($accountId);

        return $this->ruleMemo[$accountId . ($activeOnly ? 'a' : 'x')] ??= WeatherRiskRule::query()->where('account_id', $accountId)->when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('sort_order')->orderBy('id')->get();
    }

    /** One query for the candidate work of every project on the page (instead of one per card). */
    public function warmWork(array $warehouseIds, int $accountId): void
    {
        $now = CarbonImmutable::now(config('weather.timezone'));
        $to = $now->addHours((int) ($this->rules($accountId)->max('lookahead_hours') ?: 24));
        $this->workMemo = array_fill_keys($warehouseIds, collect());
        foreach (WorkPackage::query()->withoutGlobalScope('account')->whereIn('warehouse_id', $warehouseIds)->where('status', '!=', WorkPackage::STATUS_COMPLETED)
            ->where(function ($q) use ($now, $to) {
                $q->where(fn ($x) => $x->whereDate('planned_start', '<=', $to->toDateString())->whereDate('planned_finish', '>=', $now->toDateString()))
                    ->orWhere(fn ($x) => $x->where('status', WorkPackage::STATUS_IN_PROGRESS)->whereDate('planned_start', '<=', $to->toDateString()));
            })->orderBy('planned_start')->get(['id', 'warehouse_id', 'reference', 'title', 'category', 'planned_start', 'planned_finish', 'status'])->groupBy('warehouse_id') as $wid => $rows) {
            $this->workMemo[(int) $wid] = $rows->values();
        }
    }

    private function usable(array $state): bool
    {
        return ! empty($state['forecast']['hourly']) && ! $state['stale'] && in_array($state['status'], ['ok', 'failed'], true);
    }

    /** Work not finished that could fall in the window: planned dates overlap it, or already started and still not completed. */
    private function candidateWork(Warehouse $w, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if ($this->workMemo !== null && isset($this->workMemo[$w->id])) {
            return $this->workMemo[$w->id]; // loaded for the whole page by warmWork()
        }

        return WorkPackage::query()->withoutGlobalScope('account')->where('warehouse_id', $w->id)->where('status', '!=', WorkPackage::STATUS_COMPLETED)
            ->where(function ($q) use ($from, $to) {
                $q->where(fn ($x) => $x->whereDate('planned_start', '<=', $to->toDateString())->whereDate('planned_finish', '>=', $from->toDateString()))
                    ->orWhere(fn ($x) => $x->where('status', WorkPackage::STATUS_IN_PROGRESS)->whereDate('planned_start', '<=', $to->toDateString()));
            })->orderBy('planned_start')->get(['id', 'reference', 'title', 'category', 'planned_start', 'planned_finish', 'status']);
    }

    private function workRow(WorkPackage $wp): array
    {
        return ['id' => $wp->id, 'reference' => $wp->reference, 'title' => $wp->title, 'category' => $wp->category, 'status' => $wp->status,
            'planned_start' => $wp->planned_start?->toDateString(), 'planned_finish' => $wp->planned_finish?->toDateString()];
    }

    /** Per activity: how the forecast compares with its thresholds, and which scheduled work it could touch. Read-only (cards, Site Diary). */
    public function assessments(Warehouse $w, array $state): array
    {
        if (! $this->usable($state)) {
            return [];
        }
        $now = CarbonImmutable::now(config('weather.timezone'));
        $rules = $this->rules((int) $w->account_id);
        $work = $this->candidateWork($w, $now, $now->addHours((int) ($rules->max('lookahead_hours') ?: 24)));
        $out = [];
        foreach ($rules as $rule) {
            $a = RiskEvaluator::assess($rule, $state['forecast']['hourly'], $now);
            $a['sentence'] = $a['level'] === 'clear' ? null : RiskEvaluator::sentence($a, $now);
            $a['starting_values'] = ! $rule->isReviewed();
            $from = $a['window_start'] ? CarbonImmutable::parse($a['window_start']) : $now;
            $to = $a['window_end'] ? CarbonImmutable::parse($a['window_end']) : $now->addHours($a['lookahead_hours']);
            $a['scheduled'] = $work->filter(fn ($wp) => RiskEvaluator::matches($rule, $wp->title, $wp->category)
                && $wp->planned_start->toDateString() <= $to->toDateString() && ($wp->planned_finish->toDateString() >= $from->toDateString() || $wp->status === WorkPackage::STATUS_IN_PROGRESS))
                ->map(fn ($wp) => $this->workRow($wp))->values()->all();
            $out[] = $a;
        }

        return $out;
    }

    /**
     * Raise / refresh / expire the warnings of one project from its current state. Safe to run as often as you like.
     * @return array{skipped:?string, raised:int, updated:int, reopened:int, expired:int, alerts:int}
     */
    public function evaluate(Warehouse $w, ?array $state = null, bool $notify = true): array
    {
        $result = ['skipped' => null, 'raised' => 0, 'updated' => 0, 'reopened' => 0, 'expired' => 0, 'alerts' => 0];
        $state ??= $this->manager->state($w);
        if (! $this->usable($state)) {
            $result['skipped'] = $state['status'];

            return $result;
        }
        $accountId = (int) $w->account_id;
        $prefs = WeatherSettings::get($accountId);
        $now = CarbonImmutable::now(config('weather.timezone'));
        $seen = [];

        foreach ($this->assessments($w, $state) as $a) {
            if ($a['level'] === 'clear') {
                continue;
            }
            $rule = WeatherRiskRule::query()->find($a['rule_id']);
            $targets = $a['scheduled'] ?: ($prefs['warn_without_scheduled_work'] ? [null] : []);
            foreach ($targets as $wp) {
                $fp = sha1('rule|' . $w->id . '|' . $a['rule_id'] . '|' . ($wp['id'] ?? 0) . '|' . substr((string) $a['window_start'], 0, 10));
                $seen[] = $fp;
                $this->upsert($w, $state, $a, $rule, $wp, $fp, $prefs, $notify, $result);
            }
        }

        // open rule warnings that the forecast no longer supports, or whose window has passed
        WeatherWarning::query()->where('account_id', $accountId)->where('warehouse_id', $w->id)->where('kind', 'forecast_rule')->where('status', WeatherWarning::OPEN)
            ->where(fn ($q) => $q->whereNotIn('fingerprint', $seen ?: ['-'])->orWhere('window_end', '<', $now->toDateTimeString()))
            ->get()->each(function (WeatherWarning $x) use (&$result) {
                $x->update(['status' => WeatherWarning::EXPIRED, 'expired_at' => now(), 'details' => array_merge((array) $x->details, ['expired_reason' => 'The latest forecast no longer goes past the threshold, or the window has passed.'])]);
                log_activity('Weather warning expired', ['warehouse_id' => $x->warehouse_id, 'title' => $x->title, 'fingerprint' => $x->fingerprint], $x, 'Weather');
                $result['expired']++;
            });

        $this->alertWarnings($w, $state, $prefs, $notify, $result);

        return $result;
    }

    private function upsert(Warehouse $w, array $state, array $a, ?WeatherRiskRule $rule, ?array $wp, string $fp, array $prefs, bool $notify, array &$result): void
    {
        $now = CarbonImmutable::now(config('weather.timezone'));
        $sentence = $a['sentence'];
        $message = $sentence . ($wp ? ' Scheduled work that may be affected: ' . $wp['reference'] . ' ' . $wp['title'] . ' (planned ' . CarbonImmutable::parse($wp['planned_start'])->format('j M') . ' to ' . CarbonImmutable::parse($wp['planned_finish'])->format('j M') . ').' : ' No scheduled work was matched; this is a general note for the activity.');
        $details = [
            'activity' => $a['label'], 'activity_key' => $a['activity_key'], 'breaches' => $a['breaches'], 'lead_hours' => $a['lead_hours'], 'work_package' => $wp,
            'thresholds' => $rule ? collect(WeatherRiskRule::THRESHOLDS)->mapWithKeys(fn ($c) => [$c => $rule->{$c}])->all() + ['lookahead_hours' => $rule->lookahead_hours] : null,
            'starting_values' => $a['starting_values'], 'notice' => $a['starting_values'] ? RuleDefaults::NOTICE : null,
            'uncertainty' => $this->uncertaintyText($state, $a), 'forecast_provider' => $state['provider_label'], 'forecast_fetched_at' => $state['fetched_at'],
        ];
        $data = [
            'rule_id' => $a['rule_id'], 'work_package_id' => $wp['id'] ?? null, 'severity' => $a['severity'], 'title' => 'Weather may affect: ' . $a['label'], 'message' => $message, 'details' => $details,
            'window_start' => $a['window_start'], 'window_end' => $a['window_end'], 'source_provider' => $state['provider'], 'snapshot_id' => $state['snapshot_ids']['forecast'] ?? null, 'peak_value' => $a['score'],
        ];

        $existing = WeatherWarning::query()->where('account_id', $w->account_id)->where('fingerprint', $fp)->first();
        if (! $existing) {
            $row = WeatherWarning::query()->create($data + ['account_id' => $w->account_id, 'warehouse_id' => $w->id, 'kind' => 'forecast_rule', 'fingerprint' => $fp, 'status' => WeatherWarning::OPEN, 'raised_at' => now()]);
            log_activity('Weather warning raised', $this->logProps($row, $w), $row, 'Weather');
            $result['raised']++;
            $notify && $this->tell($row, $w, 'warning_raised');

            return;
        }
        $result['updated']++;
        if ($existing->status === WeatherWarning::EXPIRED) {
            $existing->update($data + ['status' => WeatherWarning::OPEN, 'expired_at' => null, 'raised_at' => now()]);
            log_activity('Weather warning raised again', $this->logProps($existing, $w), $existing, 'Weather');
            $notify && $this->tell($existing, $w, 'warning_raised');
            $result['reopened']++;

            return;
        }
        if ($existing->status === WeatherWarning::ACKNOWLEDGED) {
            $worse = (float) $a['score'] > (float) ($existing->acknowledged_peak ?? 0) + (float) $prefs['reopen_increase_pct'];
            if ($worse) {
                $existing->update(array_replace($data, ['status' => WeatherWarning::OPEN, 'raised_at' => now(), 'details' => $details + ['reopened_because' => 'The forecast is now clearly worse than when the warning was acknowledged.', 'previous_acknowledgement' => ['by' => $existing->acknowledged_by, 'at' => optional($existing->acknowledged_at)->toDateTimeString(), 'note' => $existing->acknowledge_note, 'decision' => $existing->acknowledge_decision]]]));
                log_activity('Weather warning re-opened (forecast worse)', $this->logProps($existing, $w), $existing, 'Weather');
                $notify && $this->tell($existing, $w, 'warning_raised');
                $result['reopened']++;
            } else {
                $existing->update(['details' => array_merge((array) $existing->details, ['latest' => $details])]); // the acknowledged text stays as it was
            }

            return;
        }
        if ($existing->status === WeatherWarning::DISMISSED) {
            $existing->update(['details' => array_merge((array) $existing->details, ['latest' => $details])]);

            return;
        }
        $existing->update($data); // open: follow the latest forecast quietly
    }

    private function alertWarnings(Warehouse $w, array $state, array $prefs, bool $notify, array &$result): void
    {
        if (! $state['supports_alerts'] || $state['alerts'] === null) {
            return;
        }
        $now = CarbonImmutable::now(config('weather.timezone'));
        $seen = [];
        foreach ($state['alerts'] as $al) {
            if (! empty($al['end']) && CarbonImmutable::parse($al['end'])->lt($now)) {
                continue;
            }
            $fp = sha1('alert|' . $w->id . '|' . $state['provider'] . '|' . ($al['id'] ?? $al['event']));
            $seen[] = $fp;
            $desc = mb_substr(trim((string) ($al['description'] ?? '')), 0, 400);
            if ($desc !== '' && ! preg_match('/[.!?]$/u', $desc)) {
                $desc .= '.';
            }
            $message = trim(($al['sender'] ? $al['sender'] . ' has issued' : 'A weather alert was issued') . ': ' . $al['event'] . ($al['start'] ? ' (from ' . RiskEvaluator::when($al['start'], $now) . ($al['end'] ? ' to ' . RiskEvaluator::when($al['end'], $now) : '') . ')' : '') . '. ' . ($desc ? $desc . ' ' : '')
                . 'This is a provider alert for the area, not a measurement on site. Review before proceeding.');
            $data = [
                'severity' => 'warning', 'title' => 'Weather alert: ' . $al['event'], 'message' => $message,
                'details' => ['alert' => $al, 'forecast_provider' => $state['provider_label'], 'uncertainty' => 'Alerts come from the national service named above; WIMS does not rate their certainty.'],
                'window_start' => $al['start'], 'window_end' => $al['end'], 'source_provider' => $state['provider'], 'snapshot_id' => $state['snapshot_ids']['alerts'] ?? null,
            ];
            $existing = WeatherWarning::query()->where('account_id', $w->account_id)->where('fingerprint', $fp)->first();
            if (! $existing) {
                $row = WeatherWarning::query()->create($data + ['account_id' => $w->account_id, 'warehouse_id' => $w->id, 'kind' => 'provider_alert', 'fingerprint' => $fp, 'status' => WeatherWarning::OPEN, 'raised_at' => now()]);
                log_activity('Weather alert from the provider recorded', $this->logProps($row, $w), $row, 'Weather');
                $result['alerts']++;
                $notify && $this->tell($row, $w, 'severe_alert');
            } elseif (in_array($existing->status, [WeatherWarning::OPEN, WeatherWarning::EXPIRED], true)) {
                $reopen = $existing->status === WeatherWarning::EXPIRED;
                $existing->update($data + ($reopen ? ['status' => WeatherWarning::OPEN, 'expired_at' => null, 'raised_at' => now()] : []));
                $reopen && $notify && $this->tell($existing, $w, 'severe_alert');
            }
        }
        WeatherWarning::query()->where('account_id', $w->account_id)->where('warehouse_id', $w->id)->where('kind', 'provider_alert')->where('status', WeatherWarning::OPEN)
            ->whereNotIn('fingerprint', $seen ?: ['-'])->get()->each(function (WeatherWarning $x) use (&$result) {
                $x->update(['status' => WeatherWarning::EXPIRED, 'expired_at' => now()]);
                log_activity('Weather alert no longer issued by the provider', ['warehouse_id' => $x->warehouse_id, 'title' => $x->title], $x, 'Weather');
                $result['expired']++;
            });
    }

    private function uncertaintyText(array $state, array $a): string
    {
        $parts = [];
        $prob = collect($a['breaches'])->firstWhere('metric', 'rain_probability');
        $parts[] = $state['uncertainty'] ?: 'This provider gave no statement about forecast uncertainty.';
        if ($prob) {
            $parts[] = 'The provider rain probability reaches ' . (int) $prob['value'] . '% in the window.';
        }
        if (($a['lead_hours'] ?? 0) > 48) {
            $parts[] = 'The window starts more than 48 hours ahead, so it is less certain.';
        }

        return implode(' ', $parts);
    }

    private function logProps(WeatherWarning $x, Warehouse $w): array
    {
        return ['warehouse_id' => $w->id, 'project' => $w->name, 'warning_id' => $x->id, 'kind' => $x->kind, 'severity' => $x->severity, 'title' => $x->title, 'message' => $x->message,
            'window_start' => optional($x->window_start)->toDateTimeString(), 'window_end' => optional($x->window_end)->toDateTimeString(), 'provider' => $x->source_provider, 'snapshot_id' => $x->snapshot_id,
            'work_package_id' => $x->work_package_id, 'fingerprint' => $x->fingerprint];
    }

    public function tell(WeatherWarning $x, Warehouse $w, string $event): void
    {
        $fmt = fn ($t) => $t ? RiskEvaluator::when($t->format('Y-m-d H:i:s')) : '';
        WeatherAlerts::fire($event, $w, $x->title, $x->message, [
            'activity' => $x->details['activity'] ?? '', 'severity' => ucfirst($x->severity), 'provider' => $x->details['forecast_provider'] ?? '',
            'window' => trim($fmt($x->window_start) . ($x->window_end ? ' to ' . $fmt($x->window_end) : '')), 'workpackage' => $x->details['work_package']['title'] ?? '',
        ], route('weather.warnings.index', ['project' => $w->id]), null, 'weather_risk|' . $event . '|' . $x->fingerprint . '|' . optional($x->raised_at)->timestamp, (int) $w->account_id);
    }

    /** Provider failing or data stale beyond the limit: one notice per project per six hours. */
    public function reportUnavailable(Warehouse $w, array $state): bool
    {
        if (! in_array($state['status'], ['failed', 'stale'], true)) {
            return false;
        }
        if (! \Illuminate\Support\Facades\Cache::add('weather:unavailable:' . $w->id, 1, 21600)) {
            return false; // already reported in the last six hours
        }
        $msg = 'Weather data for ' . $w->name . ' is ' . ($state['stale'] ? 'out of date' : 'unavailable') . '. ' . $state['message'];
        WeatherAlerts::fire('data_unavailable', $w, 'Weather data unavailable', mb_substr($msg, 0, 300), ['provider' => $state['provider_label'] ?? ''], route('weather.overview'), null, 'weather_risk|data_unavailable|' . $w->id . '|' . intdiv(time(), 21600), (int) $w->account_id);
        log_activity('Weather data unavailable', ['warehouse_id' => $w->id, 'project' => $w->name, 'status' => $state['status'], 'message' => mb_substr($state['message'], 0, 300), 'provider' => $state['provider']], null, 'Weather');

        return true;
    }
}
