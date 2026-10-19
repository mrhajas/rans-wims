<?php

namespace App\Services\SmartBrief;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds a Smart Brief for $subject as seen by $viewer. Everything the viewer may not see is removed BEFORE any counting or wording, so the
 * summary, the timeline, the numbers and any export can never contain more than the viewer's own permissions allow.
 */
class SmartBriefService
{
    public function __construct(private ActivityCollector $collector, private PendingCollector $pending, private Narrative $narrative)
    {
    }

    public function brief(User $viewer, User $subject, Period $period, array $options = []): array
    {
        $settings = Settings::get($subject->account_id);
        $sections = Access::sections($viewer, $subject);
        $own = $sections['own'];

        $collected = $this->collector->collect($subject, $period, $settings);
        $events = $this->visible($viewer, $subject, $collected['events'], $sections);
        if (! empty($options['module'])) {
            $events = $events->filter(fn ($e) => $e['module_key'] === $options['module'])->values();
        }
        if (! empty($options['kind'])) {
            $events = $events->filter(fn ($e) => $e['kind'] === $options['kind'])->values();
        }
        $showLines = (bool) ($options['lines'] ?? $settings['show_line_items']);

        $sessions = $sections['sessions'] ? $this->sessions($subject, $period, $events, $sections['sensitive']) : [];
        $pending = ($own || $sections['approvals']) ? $this->pending->for($subject, $viewer) : [];

        $narrative = $this->narrative->build($events, $sessions, $pending, $subject->name, $own, $period, ['sessions' => $sections['sessions'], 'sensitive' => $sections['sensitive'], 'show_lines' => $showLines]);
        $polished = AiPolish::polish($narrative['text'], $settings);

        $shown = $sections['timeline'] ? $this->timeline($viewer, $events->when(! $showLines, fn ($c) => $c->reject(fn ($e) => $e['line'])), $sections) : [];

        return [
            'subject' => ['id' => $subject->id, 'name' => $subject->name, 'username' => $subject->username, 'roles' => $subject->roles->pluck('name')->values()->all(), 'is_self' => $own],
            'period' => $period->toArray(),
            'sections' => $sections,
            'summary' => ['sentences' => $narrative['sentences'], 'text' => $polished ?? $narrative['text'], 'ai' => $polished !== null],
            'stats' => $this->stats($events),
            'sessions' => $sessions,
            'timeline' => $shown,
            'timeline_total' => $events->count(),
            'pending' => $pending,
            'coverage' => $this->coverage($collected, $sections, $period),
            'filters' => ['module' => $options['module'] ?? null, 'kind' => $options['kind'] ?? null],
            'truncated' => $collected['truncated'],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** Events the viewer may see: module permission, section permission, private/administrative categories. Own events are never cut. */
    public function visible(User $viewer, User $subject, Collection $events, array $sections): Collection
    {
        if ($sections['own']) {
            return $events->values();
        }
        $modules = array_flip(Modules::visibleKeys($viewer));

        return $events->filter(function ($e) use ($modules, $sections, $viewer) {
            if (! isset($modules[$e['module_key']])) {
                return false;
            }
            if (! empty($e['meta']['administrative']) && ! $sections['sensitive']) {
                return false;
            }
            return match ($e['section']) {
                'sessions' => $sections['sessions'] && ($e['kind'] !== 'login_failed' || $sections['sensitive']),
                'approvals' => $sections['approvals'],
                default => true,
            };
        })->map(function ($e) use ($sections) {
            if (! $sections['changes']) {
                $e['changes'] = null;
            }
            if (! $sections['sensitive']) {
                unset($e['meta']['ip'], $e['meta']['location']);
            }

            return $e;
        })->values();
    }

    private function sessions(User $subject, Period $period, Collection $events, bool $sensitive): array
    {
        $logins = $events->where('kind', 'login')->sortBy('at')->values();
        $logouts = $events->where('kind', 'logout')->sortBy('at')->values();
        // a sign-out just after the period end still closes the last session of the period
        $late = DB::table('activity_log')->where('causer_id', $subject->id)->where('causer_type', User::class)->where('description', 'like', '%logged out%')
            ->whereBetween('created_at', [$period->to->format('Y-m-d H:i:s'), $period->to->addDay()->format('Y-m-d H:i:s')])->orderBy('created_at')->get(['id', 'created_at']);
        $logouts = $logouts->merge($late->map(fn ($r) => ['id' => 'a:' . $r->id, 'at' => $r->created_at]))->sortBy('at')->values();
        $activity = $events->reject(fn ($e) => in_array($e['kind'], ['login', 'logout', 'login_failed'], true))->pluck('at');
        $seen = $subject->last_seen_at ? strtotime($subject->last_seen_at) : 0;
        $out = [];
        foreach ($logins as $i => $l) {
            $next = $logins[$i + 1]['at'] ?? null;
            $out_ = $logouts->first(fn ($x) => $x['at'] >= $l['at'] && ($next === null || $x['at'] <= $next));
            $endBound = $out_['at'] ?? $next ?? $period->to->format('Y-m-d H:i:s');
            $last = $activity->filter(fn ($t) => $t >= $l['at'] && $t <= $endBound)->max();
            $isLatest = $next === null;
            $end = $out_ ? 'logout' : (($isLatest && $seen && (time() - $seen) <= 180) ? 'active' : 'not_recorded');
            $out[] = [
                'login_id' => $l['id'], 'login_at' => $l['at'], 'logout_id' => $out_['id'] ?? null, 'logout_at' => $out_['at'] ?? null,
                'last_activity_at' => $last ?: null, 'end' => $end,
                'device' => $l['meta']['device'] ?? null, 'ip' => $sensitive ? ($l['meta']['ip'] ?? null) : null,
                'location' => $sensitive ? ($l['meta']['location'] ?? null) : null,
            ];
        }

        return $out;
    }

    private function timeline(User $viewer, Collection $events, array $sections): array
    {
        $links = (new RecordLinks())->forEvents($viewer, $events);

        return $events->map(function ($e) use ($viewer, $links) {
            return [
                'id' => $e['id'], 'at' => $e['at'], 'time' => date('H:i', strtotime($e['at'])), 'date' => date('Y-m-d', strtotime($e['at'])),
                'kind' => $e['kind'], 'module' => $e['module'], 'module_key' => $e['module_key'], 'title' => $e['title'], 'record' => $e['record'],
                'result' => $e['result'], 'note' => $e['note'], 'line' => $e['line'], 'changes' => $e['changes'],
                'device' => $e['meta']['device'] ?? null, 'ip' => $e['meta']['ip'] ?? null, 'location' => $e['meta']['location'] ?? null,
                'url' => $links[$e['id']] ?? null,
            ];
        })->values()->all();
    }

    private function stats(Collection $events): array
    {
        $main = $events->reject(fn ($e) => $e['line']);
        $by = fn (string ...$kinds) => $main->whereIn('kind', $kinds)->count();

        return [
            'total' => $main->count(),
            'created' => $by('created'), 'updated' => $by('updated'), 'submitted' => $by('submitted'), 'approved' => $by('approved'), 'rejected' => $by('rejected'),
            'deleted' => $by('deleted'), 'reports' => $by('report'), 'comments' => $by('comment'),
            'logins' => $events->where('kind', 'login')->count(), 'failed_logins' => $events->where('kind', 'login_failed')->count(),
            'modules' => $main->whereNotIn('kind', ['login', 'logout', 'login_failed'])->groupBy('module')->map->count()->sortDesc()->map(fn ($c, $m) => ['module' => $m, 'count' => $c])->values()->all(),
        ];
    }

    private function coverage(array $collected, array $sections, Period $period): array
    {
        $notes = [
            'WIMS records completed actions. A save that failed or was rolled back is not recorded, so it cannot appear here (failed sign-ins are the exception).',
            'Opening or viewing a page is not recorded, so a brief says nothing about what was only read.',
            'A session that simply expired has no recorded sign-out. It is shown as "no sign-out recorded", never with an invented time.',
            'Counts show recorded activity, not effort or performance: they are not a measure of how well anyone is doing their job.',
        ];
        if ($collected['truncated']) {
            $notes[] = 'There was more activity than the limit allows; the earliest part of the period is shown. Narrow the period to see everything.';
        }
        if (($collected['on_account'] ?? 0) > 0 && $sections['sensitive']) {
            $notes[] = $collected['on_account'] . ' action(s) were taken on this account by an administrator who was impersonating the user; they are not counted as the user\'s own.';
        }
        if (! $sections['own']) {
            $notes[] = 'This brief only includes the modules and categories you are permitted to see.';
        }

        return $notes;
    }
}
