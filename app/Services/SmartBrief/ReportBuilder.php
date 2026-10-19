<?php

namespace App\Services\SmartBrief;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Smart Brief REPORTS: turns the data the engine already produces into printable / exportable tables.
 *
 * Nothing here reads another person's activity on its own. Per-person data comes from SmartBriefService::brief() or from
 * ActivityCollector + SmartBriefService::visible() (the exact same cut brief() applies), group counts come from Overview::build(), so a
 * report can never hold more than the viewer may already see on screen. Section abilities are re-checked here as well.
 *
 * build() returns: title, subtitle, reference, type, columns [{key,label,align}], rows [assoc by column key], tiles [{label,value}],
 * sections (extra tables / paragraphs, each with placement before|after the main table), filters [label => value], subject, notes,
 * qr_url, plus period, table_title, total_rows, truncated.
 */
class ReportBuilder
{
    /** Hard cap on printed / exported rows. */
    public const MAX_ROWS = 2000;

    /** How many people an event based aggregate (module, sessions, approvals, changes) will read in one report. */
    public const MAX_PEOPLE = 300;

    /** key => [code (reference), label, description, kind]. kind: person | group | events | admin */
    public const TYPES = [
        'individual_daily' => ['code' => 'DAILY', 'label' => 'Individual brief', 'kind' => 'person',
            'description' => 'The brief of one person for the period: summary sentences, key figures, sign-in sessions and a condensed timeline.'],
        'individual_history' => ['code' => 'HIST', 'label' => 'Individual activity history', 'kind' => 'person',
            'description' => 'The full activity timeline of one person for the period, line items and record changes included.'],
        'team' => ['code' => 'TEAM', 'label' => 'Team summary', 'kind' => 'group',
            'description' => 'One row per person you may see: recorded activity, sign-ins, reports and last activity.'],
        'role' => ['code' => 'ROLE', 'label' => 'Summary by role', 'kind' => 'group',
            'description' => 'The same figures added up for each role. WIMS has no departments, so roles are the grouping used.'],
        'project' => ['code' => 'PROJ', 'label' => 'Summary by project', 'kind' => 'group',
            'description' => 'The same figures added up for each project or warehouse the people are assigned to.'],
        'module' => ['code' => 'MOD', 'label' => 'Activity by module', 'kind' => 'events',
            'description' => 'How many records were created, updated, submitted, approved, rejected or deleted in each module.'],
        'sessions' => ['code' => 'SESS', 'label' => 'Sign-in sessions', 'kind' => 'events',
            'description' => 'Sign-in, sign-out, last activity and how each session ended, per person.'],
        'approvals' => ['code' => 'APPR', 'label' => 'Approval activity', 'kind' => 'events',
            'description' => 'Submissions, approvals, rejections and items sent back, with who did what and when.'],
        'changes' => ['code' => 'CHG', 'label' => 'Record change history', 'kind' => 'events',
            'description' => 'What was changed in records, field by field, with the before and after values.'],
        'access_history' => ['code' => 'ACC', 'label' => 'Smart Brief access history', 'kind' => 'admin',
            'description' => 'Administrative: who viewed or exported whose brief, and access grants and revocations.'],
    ];

    /** Types that describe ONE person (and the types that may be narrowed to one person). */
    public const PERSON_TYPES = ['individual_daily', 'individual_history'];

    public const OPTIONAL_PERSON_TYPES = ['sessions', 'approvals', 'changes'];

    public function __construct(private SmartBriefService $service, private ActivityCollector $collector, private Overview $overview)
    {
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function needsPerson(string $type): bool
    {
        return in_array($type, self::PERSON_TYPES, true);
    }

    /** Wide tables are printed landscape. */
    public static function landscape(array $report): bool
    {
        return count($report['columns']) > 5;
    }

    /** Which report types this viewer can run at all (a given person may still be refused when the report is built). */
    public static function availability(User $viewer): array
    {
        $management = Access::canOpenManagement($viewer);
        $person = $management || Access::canViewOwn($viewer);
        $admin = $viewer->can('manage-smart-brief-access') && Access::feature('smart_brief_access', $viewer);
        $out = [];
        foreach (self::TYPES as $key => $t) {
            $allowed = match ($t['kind']) {
                'person' => $person,
                'admin' => $admin,
                default => $management || (in_array($key, self::OPTIONAL_PERSON_TYPES, true) && $person),
            };
            $out[$key] = [
                'key' => $key, 'label' => __($t['label']), 'description' => __($t['description']), 'kind' => $t['kind'],
                'person' => $t['kind'] === 'person', 'person_optional' => in_array($key, self::OPTIONAL_PERSON_TYPES, true),
                'groupable' => in_array($key, ['team', 'role', 'project'], true),
                'allowed' => (bool) $allowed,
            ];
        }

        return $out;
    }

    /** Does this report hold only the viewer's own activity? (Then exporting it needs no extra ability.) */
    public static function isOwnBrief(User $viewer, string $type, array $filters): bool
    {
        return self::needsPerson($type) && (int) ($filters['user_id'] ?? 0) === (int) $viewer->id;
    }

    /** The person a report is about, after authorisation (null for group reports). Aborts with 403 / 422. */
    public function authorize(User $viewer, string $type, array $filters): ?User
    {
        abort_unless(self::exists($type), 404, __('Unknown report type.'));
        $kind = self::TYPES[$type]['kind'];

        if ($kind === 'admin') {
            abort_unless($viewer->can('manage-smart-brief-access') && Access::feature('smart_brief_access', $viewer), 403, __('You are not allowed to view the Smart Brief access history.'));

            return null;
        }

        $userId = (int) ($filters['user_id'] ?? 0);
        if (self::needsPerson($type) || ($userId && in_array($type, self::OPTIONAL_PERSON_TYPES, true))) {
            abort_unless($userId > 0, 422, __('Choose a person for this report.'));
            $subject = User::query()->where('account_id', $viewer->account_id)->find($userId);
            // a missing person and a person you may not see give the same answer
            abort_unless($subject && Access::canViewSubject($viewer, $subject), 403, __('You are not allowed to view this person\'s Smart Brief.'));

            return $subject;
        }

        abort_unless(Access::canOpenManagement($viewer), 403, __('You are not allowed to view group Smart Brief reports.'));

        return null;
    }

    public function build(User $viewer, string $type, Period $period, array $filters = []): array
    {
        $subject = $this->authorize($viewer, $type, $filters);

        $report = match ($type) {
            'individual_daily' => $this->individualDaily($viewer, $subject, $period),
            'individual_history' => $this->individualHistory($viewer, $subject, $period),
            'team' => $this->team($viewer, $period, $filters),
            'role' => $this->byRole($viewer, $period, $filters),
            'project' => $this->byProject($viewer, $period, $filters),
            'module' => $this->byModule($viewer, $period, $filters),
            'sessions' => $this->sessions($viewer, $subject, $period, $filters),
            'approvals' => $this->approvals($viewer, $subject, $period, $filters),
            'changes' => $this->changes($viewer, $subject, $period, $filters),
            'access_history' => $this->accessHistory($viewer, $period),
        };

        $t = self::TYPES[$type];
        $report['type'] = $type;
        $report['title'] = __($t['label']);
        $report['reference'] = 'SB-' . $t['code'] . '-' . now()->format('Ymd') . '-' . ($subject->id ?? $viewer->id);
        $report['period'] = $period->toArray();
        $report['subject'] = $subject ? ['id' => $subject->id, 'name' => $subject->name, 'username' => $subject->username, 'roles' => $subject->roles->pluck('name')->values()->all(), 'is_self' => (int) $subject->id === (int) $viewer->id] : null;
        $report['filters'] = $this->filterLabels($period, $subject, $filters, $type);
        $report['qr_url'] = $subject && self::needsPerson($type)
            ? ((int) $subject->id === (int) $viewer->id ? route('smart-brief.my.index') : route('smart-brief.management.index', ['user' => $subject->id]))
            : null;
        $report += ['sections' => [], 'tiles' => [], 'notes' => [], 'table_title' => null];

        // hard cap
        $report['total_rows'] = count($report['rows']);
        $report['truncated'] = false;
        if ($report['total_rows'] > self::MAX_ROWS) {
            $report['rows'] = array_slice($report['rows'], 0, self::MAX_ROWS);
            $report['truncated'] = true;
            $report['notes'][] = 'Only the first ' . number_format(self::MAX_ROWS) . ' of ' . number_format($report['total_rows']) . ' rows are shown. Narrow the period or the people to see everything.';
        }
        foreach ($report['sections'] as &$s) {
            if (($s['type'] ?? '') === 'table' && count($s['rows']) > self::MAX_ROWS) {
                $s['rows'] = array_slice($s['rows'], 0, self::MAX_ROWS);
                $report['notes'][] = 'The table "' . $s['title'] . '" is limited to its first ' . number_format(self::MAX_ROWS) . ' rows.';
            }
        }
        unset($s);
        // a person's brief already carries the engine's own coverage notes (the same limits, worded there)
        $report['notes'] = array_values(array_unique(array_merge($report['notes'], self::needsPerson($type) ? [] : $this->standardNotes())));
        $report['subtitle'] = $report['subtitle'] ?? null;

        return $report;
    }

    private function standardNotes(): array
    {
        return [
            'Counts show recorded activity, not effort or performance. They are not a measure of how well anyone is doing their job.',
            'Page views are not recorded: opening or reading a record leaves no trace, so it cannot appear here.',
            'Only completed actions are recorded. A session that simply expired has no sign-out time, and none is invented.',
        ];
    }

    // ================================================================================================================ helpers

    private function col(string $key, string $label, string $align = 'left'): array
    {
        return ['key' => $key, 'label' => __($label), 'align' => $align];
    }

    private function dt(?string $at): string
    {
        return $at ? date('d M Y, H:i', strtotime($at)) : '';
    }

    private function filterLabels(Period $period, ?User $subject, array $filters, string $type): array
    {
        $out = [__('Period') => $period->isSingleDay() ? $period->label . ($period->key === 'custom' ? '' : ' (' . $period->from->format('d M Y') . ')')
            : ($period->key === 'custom' ? $period->label : $period->label . ' (' . $period->from->format('d M Y') . ' - ' . $period->to->format('d M Y') . ')')];
        if ($subject) {
            $out[__('Person')] = $subject->name . ' (' . $subject->username . ')';
        }
        if (! empty($filters['role']) && in_array($type, ['team', 'role', 'project', 'module', 'sessions', 'approvals', 'changes'], true)) {
            $out[__('Role')] = $filters['role'];
        }
        if (! empty($filters['project']) && in_array($type, ['team', 'role', 'project', 'module', 'sessions', 'approvals', 'changes'], true)) {
            $out[__('Project')] = Warehouse::query()->whereKey((int) $filters['project'])->value('name') ?: '#' . (int) $filters['project'];
        }

        return $out;
    }

    /** The people an aggregated report covers (already cut to the viewer's scope by Access::viewableUsers). */
    private function people(User $viewer, array $filters, int $limit): Collection
    {
        return $this->overview->subjects($viewer, array_filter([
            'role' => $filters['role'] ?? null, 'project' => $filters['project'] ?? null, 'q' => $filters['q'] ?? null, 'limit' => $limit,
        ]));
    }

    private function groupFilters(array $filters): array
    {
        return array_filter(['role' => $filters['role'] ?? null, 'project' => $filters['project'] ?? null, 'q' => $filters['q'] ?? null]) + ['limit' => self::MAX_ROWS];
    }

    /** [events cut for the viewer, truncated, sections] for one person: the same cut brief() applies. */
    private function visibleEvents(User $viewer, User $subject, Period $period): array
    {
        $settings = Settings::get($subject->account_id);
        $sections = Access::sections($viewer, $subject);
        $collected = $this->collector->collect($subject, $period, $settings);

        return [$this->service->visible($viewer, $subject, $collected['events'], $sections), (bool) $collected['truncated'], $sections];
    }

    /** Subjects of an event based aggregate: the one chosen person, or the people in scope (capped). [Collection, ?note] */
    private function eventSubjects(User $viewer, ?User $subject, array $filters): array
    {
        if ($subject) {
            return [collect([$subject]), null];
        }
        $people = $this->people($viewer, $filters, self::MAX_PEOPLE + 1);
        $note = null;
        if ($people->count() > self::MAX_PEOPLE) {
            $people = $people->take(self::MAX_PEOPLE);
            $note = 'This report reads the first ' . self::MAX_PEOPLE . ' people (alphabetically). Filter by role or project, or choose one person, to cover the others.';
        }

        return [$people->values(), $note];
    }

    private function stat(Collection $briefStats, string $k): int
    {
        return (int) ($briefStats[$k] ?? 0);
    }

    // ============================================================================================================ person reports

    private function individualDaily(User $viewer, User $subject, Period $period): array
    {
        $b = $this->service->brief($viewer, $subject, $period, ['lines' => false]);
        $own = $b['subject']['is_self'];
        $st = collect($b['stats']);

        $tiles = [
            ['label' => __('Recorded activity'), 'value' => $st['total']], ['label' => __('Created'), 'value' => $st['created']],
            ['label' => __('Updated'), 'value' => $st['updated']], ['label' => __('Submitted'), 'value' => $st['submitted']],
            ['label' => __('Approved'), 'value' => $st['approved']], ['label' => __('Rejected'), 'value' => $st['rejected']],
            ['label' => __('Reports and downloads'), 'value' => $st['reports']],
        ];
        if ($b['sections']['sessions']) {
            $tiles[] = ['label' => __('Sign-ins'), 'value' => $st['logins']];
        }
        if ($b['sections']['sensitive'] && $st['failed_logins'] > 0) {
            $tiles[] = ['label' => __('Failed sign-ins'), 'value' => $st['failed_logins']];
        }

        $sections = [['type' => 'paragraphs', 'placement' => 'before', 'title' => __('Summary'), 'items' => array_map(fn ($s) => $s['text'], $b['summary']['sentences'])]];
        if ($b['sections']['sessions']) {
            $sections[] = [
                'type' => 'table', 'placement' => 'before', 'title' => __('Sign-in sessions'),
                'columns' => $this->sessionColumns($b['sections']['sensitive']),
                'rows' => $this->sessionRows($b['sessions'], $b['sections']['sensitive']),
                'empty' => __('No sign-ins were recorded in this period.'),
            ];
        }
        if ($st['modules']) {
            $sections[] = [
                'type' => 'table', 'placement' => 'after', 'title' => __('Activity by module'),
                'columns' => [$this->col('module', 'Module'), $this->col('count', 'Recorded activity', 'right')],
                'rows' => collect($st['modules'])->map(fn ($m) => ['module' => $m['module'], 'count' => $m['count']])->all(), 'empty' => '',
            ];
        }
        if ($own && ! empty($b['pending'])) {
            $sections[] = [
                'type' => 'table', 'placement' => 'after', 'title' => __('Waiting for this person'),
                'columns' => [$this->col('label', 'What'), $this->col('count', 'Count', 'right'), $this->col('overdue', 'Overdue', 'right')],
                'rows' => collect($b['pending'])->map(fn ($p) => ['label' => (string) $p['label'], 'count' => (int) $p['count'], 'overdue' => (int) $p['overdue']])->all(), 'empty' => '',
            ];
        }

        $notes = $b['coverage'];
        $timeline = collect($b['timeline'])->reject(fn ($e) => $e['line'] || in_array($e['kind'], ['login', 'logout', 'login_failed'], true))->values();
        if (! $b['sections']['timeline']) {
            $notes[] = 'The detailed activity timeline of this person is not available to you.';
        }
        $multi = ! $period->isSingleDay();
        $cap = 150;
        if ($timeline->count() > $cap) {
            $notes[] = 'The condensed timeline shows the first ' . $cap . ' of ' . $timeline->count() . ' entries. The Individual activity history report lists them all.';
            $timeline = $timeline->take($cap)->values();
        }
        $rows = $timeline->map(fn ($e) => [
            'when' => $multi ? $this->dt($e['at']) : $e['time'], 'module' => $e['module'], 'activity' => $e['title'], 'record' => (string) ($e['record'] ?? ''),
            'result' => $e['result'] === 'failed' ? __('Failed') : __('Done'),
        ])->all();

        return [
            'subtitle' => $subject->name . ' - ' . $period->label,
            'columns' => [$this->col('when', $multi ? 'Date and time' : 'Time'), $this->col('module', 'Module'), $this->col('activity', 'Activity'), $this->col('record', 'Record'), $this->col('result', 'Result')],
            'rows' => $rows, 'tiles' => $tiles, 'sections' => $sections, 'notes' => $notes, 'table_title' => __('Condensed timeline'),
        ];
    }

    private function individualHistory(User $viewer, User $subject, Period $period): array
    {
        $b = $this->service->brief($viewer, $subject, $period, ['lines' => true]);
        $own = $b['subject']['is_self'];
        $st = $b['stats'];
        $withChanges = (bool) $b['sections']['changes'];
        $sensitive = (bool) $b['sections']['sensitive'];

        $columns = [$this->col('date', 'Date'), $this->col('time', 'Time'), $this->col('module', 'Module'), $this->col('kind', 'Type'), $this->col('activity', 'Activity'), $this->col('record', 'Record'), $this->col('result', 'Result')];
        if ($withChanges) {
            $columns[] = $this->col('changes', 'Changes (before > after)');
        }
        $columns[] = $this->col('note', $sensitive ? 'Note and device' : 'Note');

        $rows = collect($b['timeline'])->map(function ($e) use ($withChanges, $sensitive) {
            $row = [
                'date' => date('d M Y', strtotime($e['at'])), 'time' => $e['time'], 'module' => $e['module'], 'kind' => ucfirst(str_replace('_', ' ', $e['kind'])) . ($e['line'] ? ' (line)' : ''),
                'activity' => $e['title'], 'record' => (string) ($e['record'] ?? ''), 'result' => $e['result'] === 'failed' ? __('Failed') : __('Done'),
            ];
            if ($withChanges) {
                $row['changes'] = $e['changes'] ? collect($e['changes'])->map(fn ($c) => $c['field'] . ': ' . ($c['from'] === '' ? '(empty)' : $c['from']) . ' > ' . ($c['to'] === '' ? '(empty)' : $c['to']))->implode('; ') : '';
            }
            $row['note'] = trim(($e['note'] ?? '') . ($sensitive && $e['device'] ? ($e['note'] ? ' | ' : '') . $e['device'] . ($e['ip'] ? ' ' . $e['ip'] : '') : ''));

            return $row;
        })->all();

        $notes = $b['coverage'];
        if (! $b['sections']['timeline']) {
            $notes[] = 'The detailed activity timeline of this person is not available to you, so this history is empty.';
        }
        if ($b['truncated']) {
            $notes[] = 'The activity limit was reached: the earliest part of the period is shown.';
        }

        return [
            'subtitle' => $subject->name . ' - ' . $period->label,
            'columns' => $columns, 'rows' => $rows, 'table_title' => __('Activity timeline'),
            'tiles' => [
                ['label' => __('Entries listed'), 'value' => count($rows)], ['label' => __('Recorded activity'), 'value' => $st['total']],
                ['label' => __('Created'), 'value' => $st['created']], ['label' => __('Updated'), 'value' => $st['updated']],
                ['label' => __('Approved'), 'value' => $st['approved']], ['label' => __('Deleted'), 'value' => $st['deleted']],
            ],
            'sections' => [], 'notes' => $notes,
        ];
    }

    // ============================================================================================================ group reports

    private function team(User $viewer, Period $period, array $filters): array
    {
        $o = $this->overview->build($viewer, $period, $this->groupFilters($filters));
        $showFailed = $o['totals']['failed'] !== null;
        $columns = [$this->col('name', 'Person'), $this->col('username', 'Username'), $this->col('roles', 'Roles'), $this->col('activity', 'Recorded activity', 'right'), $this->col('logins', 'Sign-ins', 'right')];
        if ($showFailed) {
            $columns[] = $this->col('failed', 'Failed sign-ins', 'right');
        }
        $columns[] = $this->col('reports', 'Reports and downloads', 'right');
        $columns[] = $this->col('last', 'Last activity');

        $rows = collect($o['people'])->map(function ($p) use ($showFailed) {
            $r = ['name' => $p['name'], 'username' => $p['username'], 'roles' => implode(', ', $p['roles']), 'activity' => $p['activity'], 'logins' => $p['logins']];
            if ($showFailed) {
                $r['failed'] = (int) $p['failed'];
            }
            $r['reports'] = $p['reports'];
            $r['last'] = $this->dt($p['last_at']);

            return $r;
        })->all();

        $t = $o['totals'];
        $tiles = [['label' => __('People'), 'value' => $t['people']], ['label' => __('People with activity'), 'value' => $t['active']], ['label' => __('Recorded activity'), 'value' => $t['activity']],
            ['label' => __('Sign-ins'), 'value' => $t['logins']], ['label' => __('Reports and downloads'), 'value' => $t['reports']]];
        if ($showFailed) {
            $tiles[] = ['label' => __('Failed sign-ins'), 'value' => $t['failed']];
        }
        $sections = [];
        if ($o['modules']) {
            $sections[] = ['type' => 'table', 'placement' => 'after', 'title' => __('Activity by module'), 'columns' => [$this->col('module', 'Module'), $this->col('count', 'Recorded activity', 'right')],
                'rows' => collect($o['modules'])->map(fn ($m) => ['module' => $m['module'], 'count' => $m['count']])->all(), 'empty' => ''];
        }
        if ($o['waiting']) {
            $sections[] = ['type' => 'table', 'placement' => 'after', 'title' => __('Waiting right now'), 'columns' => [$this->col('label', 'What'), $this->col('module', 'Module'), $this->col('count', 'Count', 'right'), $this->col('overdue', 'Overdue', 'right')],
                'rows' => collect($o['waiting'])->map(fn ($w) => ['label' => $w['label'], 'module' => $w['module'], 'count' => $w['count'], 'overdue' => $w['overdue']])->all(), 'empty' => ''];
        }
        $notes = [$o['note'], 'People are listed alphabetically. Nobody is ranked or scored.'];
        if (count($rows) >= self::MAX_ROWS) {
            $notes[] = 'More people matched than the report limit allows.';
        }

        return ['subtitle' => $period->label, 'columns' => $columns, 'rows' => $rows, 'tiles' => $tiles, 'sections' => $sections, 'notes' => $notes, 'table_title' => __('People')];
    }

    /** Group rows [name, people:[ids...]] summed from Overview's per-person figures. */
    private function groupRows(array $people, array $groups): array
    {
        $byId = collect($people)->keyBy('id');
        $rows = [];
        foreach ($groups as $name => $ids) {
            $members = collect($ids)->map(fn ($id) => $byId[$id] ?? null)->filter();
            $last = $members->pluck('last_at')->filter()->max();
            $rows[] = [
                'group' => (string) $name, 'people' => $members->count(),
                'active' => $members->filter(fn ($p) => $p['activity'] > 0 || $p['logins'] > 0)->count(),
                'activity' => $members->sum('activity'), 'logins' => $members->sum('logins'), 'reports' => $members->sum('reports'), 'last' => $this->dt($last),
            ];
        }

        return $rows;
    }

    private function groupColumns(string $label): array
    {
        return [$this->col('group', $label), $this->col('people', 'People', 'right'), $this->col('active', 'People with activity', 'right'), $this->col('activity', 'Recorded activity', 'right'),
            $this->col('logins', 'Sign-ins', 'right'), $this->col('reports', 'Reports and downloads', 'right'), $this->col('last', 'Last activity')];
    }

    private function byRole(User $viewer, Period $period, array $filters): array
    {
        $o = $this->overview->build($viewer, $period, $this->groupFilters($filters));
        $groups = [];
        foreach ($o['people'] as $p) {
            foreach ($p['roles'] ?: ['(no role)'] as $role) {
                $groups[$role][] = $p['id'];
            }
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'subtitle' => $period->label, 'columns' => $this->groupColumns('Role'), 'rows' => $this->groupRows($o['people'], $groups), 'table_title' => __('By role'),
            'tiles' => [['label' => __('Roles'), 'value' => count($groups)], ['label' => __('People'), 'value' => $o['totals']['people']], ['label' => __('Recorded activity'), 'value' => $o['totals']['activity']], ['label' => __('Sign-ins'), 'value' => $o['totals']['logins']]],
            'sections' => [],
            'notes' => ['WIMS has no departments. Roles are the closest grouping, so this report is by role.',
                'A person who holds several roles is counted in each of them, so the role rows add up to more than the totals.', $o['note']],
        ];
    }

    private function byProject(User $viewer, Period $period, array $filters): array
    {
        $o = $this->overview->build($viewer, $period, $this->groupFilters($filters));
        $ids = collect($o['people'])->pluck('id')->all();
        $names = Warehouse::query()->pluck('name', 'id');
        $groups = [];
        if ($ids) {
            foreach (DB::table('user_warehouse')->whereIn('user_id', $ids)->get(['user_id', 'warehouse_id']) as $r) {
                $groups[$names[$r->warehouse_id] ?? ('Project #' . $r->warehouse_id)][] = (int) $r->user_id;
            }
        }
        $assigned = collect($groups)->flatten()->unique();
        $none = collect($ids)->diff($assigned)->values()->all();
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        if ($none) {
            $groups['(no project assigned)'] = $none;
        }

        return [
            'subtitle' => $period->label, 'columns' => $this->groupColumns('Project / warehouse'), 'rows' => $this->groupRows($o['people'], $groups), 'table_title' => __('By project'),
            'tiles' => [['label' => __('Projects'), 'value' => count($groups) - ($none ? 1 : 0)], ['label' => __('People'), 'value' => $o['totals']['people']], ['label' => __('Recorded activity'), 'value' => $o['totals']['activity']], ['label' => __('Sign-ins'), 'value' => $o['totals']['logins']]],
            'sections' => [],
            'notes' => ['People are grouped by the projects or warehouses they are assigned to (user to warehouse assignment).',
                'A person assigned to several projects is counted in each of them, so the project rows add up to more than the totals.', $o['note']],
        ];
    }

    // ============================================================================================================ event based

    private function byModule(User $viewer, Period $period, array $filters): array
    {
        [$people, $cutNote] = $this->eventSubjects($viewer, null, $filters);
        $kinds = ['created' => 'Created', 'updated' => 'Updated', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected', 'returned' => 'Sent back', 'deleted' => 'Deleted', 'report' => 'Reports', 'comment' => 'Comments'];
        $matrix = [];
        $labels = [];
        foreach ($people as $p) {
            [$events] = $this->visibleEvents($viewer, $p, $period);
            foreach ($events as $e) {
                if ($e['line'] || in_array($e['kind'], ['login', 'logout', 'login_failed'], true)) {
                    continue;
                }
                $labels[$e['module_key']] = $e['module'];
                $k = isset($kinds[$e['kind']]) ? $e['kind'] : 'other';
                $matrix[$e['module_key']][$k] = ($matrix[$e['module_key']][$k] ?? 0) + 1;
            }
        }
        $cols = [$this->col('module', 'Module')];
        foreach ($kinds as $k => $l) {
            $cols[] = $this->col($k, $l, 'right');
        }
        $cols[] = $this->col('other', 'Other', 'right');
        $cols[] = $this->col('total', 'Total', 'right');

        $rows = [];
        foreach ($matrix as $key => $counts) {
            $r = ['module' => $labels[$key]];
            foreach (array_keys($kinds) as $k) {
                $r[$k] = $counts[$k] ?? 0;
            }
            $r['other'] = $counts['other'] ?? 0;
            $r['total'] = array_sum($counts);
            $rows[] = $r;
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['module'], $b['module']));
        $sum = fn (string $k) => array_sum(array_column($rows, $k));
        $notes = array_filter([$cutNote, 'Only the modules you are permitted to read are counted. Line items (rows inside a record) are not counted separately.']);

        return [
            'subtitle' => $period->label . ' - ' . $people->count() . ' ' . __('people'), 'columns' => $cols, 'rows' => $rows, 'table_title' => __('Module by kind of action'),
            'tiles' => [['label' => __('Modules with activity'), 'value' => count($rows)], ['label' => __('Recorded activity'), 'value' => $sum('total')], ['label' => __('Created'), 'value' => $sum('created')],
                ['label' => __('Updated'), 'value' => $sum('updated')], ['label' => __('Approved'), 'value' => $sum('approved')], ['label' => __('Deleted'), 'value' => $sum('deleted')]],
            'sections' => [], 'notes' => array_values($notes),
        ];
    }

    private function sessionColumns(bool $sensitive): array
    {
        $c = [$this->col('login', 'Signed in'), $this->col('logout', 'Signed out'), $this->col('last', 'Last activity'), $this->col('end', 'How it ended'), $this->col('device', 'Device')];
        if ($sensitive) {
            $c[] = $this->col('ip', 'IP address');
        }

        return $c;
    }

    private function sessionRows(array $sessions, bool $sensitive): array
    {
        $end = ['logout' => __('Signed out'), 'active' => __('Still active'), 'not_recorded' => __('No sign-out recorded')];

        return collect($sessions)->map(function ($s) use ($end, $sensitive) {
            $r = ['login' => $this->dt($s['login_at']), 'logout' => $this->dt($s['logout_at']), 'last' => $this->dt($s['last_activity_at']), 'end' => $end[$s['end']] ?? $s['end'], 'device' => (string) ($s['device'] ?? '')];
            if ($sensitive) {
                $r['ip'] = (string) ($s['ip'] ?? '');
            }

            return $r;
        })->all();
    }

    private function sessions(User $viewer, ?User $subject, Period $period, array $filters): array
    {
        [$people, $cutNote] = $this->eventSubjects($viewer, $subject, $filters);
        // brief() is expensive: only people with a recorded sign-in in the period are read
        if (! $subject) {
            $ids = $people->pluck('id')->all();
            $with = $ids ? DB::table('login_logs')->where('status', 'success')->whereIn('user_id', $ids)->whereBetween('attempted_at', $period->sql())->distinct()->pluck('user_id')->all() : [];
            $people = $people->filter(fn ($u) => in_array($u->id, $with))->values();
        }
        $rows = [];
        $anyIp = false;
        $denied = 0;
        $counts = ['logout' => 0, 'active' => 0, 'not_recorded' => 0];
        $end = ['logout' => __('Signed out'), 'active' => __('Still active'), 'not_recorded' => __('No sign-out recorded')];
        foreach ($people as $p) {
            $b = $this->service->brief($viewer, $p, $period, ['lines' => false]);
            if (! $b['sections']['sessions']) {
                $denied++;
                continue;
            }
            $anyIp = $anyIp || $b['sections']['sensitive'];
            foreach ($b['sessions'] as $s) {
                $counts[$s['end']] = ($counts[$s['end']] ?? 0) + 1;
                $rows[] = ['name' => $p->name, 'login' => $this->dt($s['login_at']), 'logout' => $this->dt($s['logout_at']), 'last' => $this->dt($s['last_activity_at']),
                    'end' => $end[$s['end']] ?? $s['end'], 'device' => (string) ($s['device'] ?? ''), 'ip' => (string) ($s['ip'] ?? '')];
            }
        }
        $cols = array_merge([$this->col('name', 'Person')], $this->sessionColumns($anyIp));
        usort($rows, fn ($a, $b) => [$a['name'], $a['login']] <=> [$b['name'], $b['login']]);

        $notes = array_filter([$cutNote, $denied ? 'The sessions of ' . $denied . ' person(s) are not included because you may not see sign-in and session activity for them.' : null,
            'A session that simply expired has no recorded sign-out; it is shown as "No sign-out recorded" and no time is invented.']);

        return [
            'subtitle' => ($subject ? $subject->name . ' - ' : '') . $period->label, 'columns' => $cols, 'rows' => $rows, 'table_title' => __('Sessions'),
            'tiles' => [['label' => __('Sessions'), 'value' => count($rows)], ['label' => __('People'), 'value' => count(array_unique(array_column($rows, 'name')))],
                ['label' => __('Signed out'), 'value' => $counts['logout']], ['label' => __('No sign-out recorded'), 'value' => $counts['not_recorded']], ['label' => __('Still active'), 'value' => $counts['active']]],
            'sections' => [], 'notes' => array_values($notes),
        ];
    }

    private function approvals(User $viewer, ?User $subject, Period $period, array $filters): array
    {
        [$people, $cutNote] = $this->eventSubjects($viewer, $subject, $filters);
        $rows = [];
        $denied = 0;
        $c = ['submitted' => 0, 'approved' => 0, 'rejected' => 0, 'returned' => 0];
        $label = ['submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected', 'returned' => 'Sent back'];
        foreach ($people as $p) {
            [$events, , $sections] = $this->visibleEvents($viewer, $p, $period);
            if (! $sections['approvals']) {
                $denied++;
                continue;
            }
            foreach ($events as $e) {
                if ($e['line'] || ! isset($c[$e['kind']])) {
                    continue;
                }
                $c[$e['kind']]++;
                $rows[] = ['at' => $e['at'], 'when' => $this->dt($e['at']), 'name' => $p->name, 'action' => $label[$e['kind']], 'module' => $e['module'], 'activity' => $e['title'], 'record' => (string) ($e['record'] ?? ''), 'note' => (string) ($e['note'] ?? '')];
            }
        }
        usort($rows, fn ($a, $b) => strcmp($b['at'], $a['at']));
        $rows = array_map(fn ($r) => array_diff_key($r, ['at' => 1]), $rows);
        $notes = array_filter([$cutNote, $denied ? 'Approval activity of ' . $denied . ' person(s) is not included because you may not see it.' : null,
            'Only steps WIMS recorded as an approval step are listed. A submission is never presented as an approval, nor the other way round.']);

        return [
            'subtitle' => ($subject ? $subject->name . ' - ' : '') . $period->label,
            'columns' => [$this->col('when', 'Date and time'), $this->col('name', 'Person'), $this->col('action', 'Action'), $this->col('module', 'Module'), $this->col('activity', 'Activity'), $this->col('record', 'Record'), $this->col('note', 'Remarks')],
            'rows' => $rows, 'table_title' => __('Approval events'),
            'tiles' => [['label' => __('Events'), 'value' => array_sum($c)], ['label' => __('Submitted'), 'value' => $c['submitted']], ['label' => __('Approved'), 'value' => $c['approved']], ['label' => __('Rejected'), 'value' => $c['rejected']], ['label' => __('Sent back'), 'value' => $c['returned']]],
            'sections' => [], 'notes' => array_values($notes),
        ];
    }

    private function changes(User $viewer, ?User $subject, Period $period, array $filters): array
    {
        [$people, $cutNote] = $this->eventSubjects($viewer, $subject, $filters);
        $rows = [];
        $denied = 0;
        $records = 0;
        $when = [];
        foreach ($people as $p) {
            [$events, , $sections] = $this->visibleEvents($viewer, $p, $period);
            if (! $sections['changes']) {
                $denied++;
                continue;
            }
            foreach ($events as $e) {
                if (empty($e['changes'])) {
                    continue;
                }
                $records++;
                foreach ($e['changes'] as $ch) {
                    $rows[] = ['at' => $e['at'], 'when' => $this->dt($e['at']), 'name' => $p->name, 'module' => $e['module'], 'record' => (string) ($e['record'] ?? ''),
                        'field' => $ch['field'], 'from' => $ch['from'], 'to' => $ch['to']];
                }
            }
        }
        usort($rows, fn ($a, $b) => strcmp($b['at'], $a['at']));
        $rows = array_map(fn ($r) => array_diff_key($r, ['at' => 1]), $rows);
        $notes = array_filter([$cutNote, $denied ? 'Record changes of ' . $denied . ' person(s) are not included because you may not see them.' : null,
            'Values that look like passwords, tokens or keys are always hidden. Long values are shortened. Each field changed is one row.']);

        return [
            'subtitle' => ($subject ? $subject->name . ' - ' : '') . $period->label,
            'columns' => [$this->col('when', 'Date and time'), $this->col('name', 'Changed by'), $this->col('module', 'Module'), $this->col('record', 'Record'), $this->col('field', 'Field'), $this->col('from', 'Before'), $this->col('to', 'After')],
            'rows' => $rows, 'table_title' => __('Changes'),
            'tiles' => [['label' => __('Records changed'), 'value' => $records], ['label' => __('Fields changed'), 'value' => count($rows)], ['label' => __('People'), 'value' => count(array_unique(array_column($rows, 'name')))]],
            'sections' => [], 'notes' => array_values($notes),
        ];
    }

    // ============================================================================================================ administrative

    private function accessHistory(User $viewer, Period $period): array
    {
        $logs = DB::table('activity_log as a')->leftJoin('users as u', function ($j) {
            $j->on('u.id', '=', 'a.causer_id')->where('a.causer_type', '=', User::class);
        })->where('a.account_id', $viewer->account_id)->where('a.log_name', 'Smart Brief')->whereBetween('a.created_at', $period->sql())
            ->orderByDesc('a.created_at')->limit(self::MAX_ROWS + 1)->get(['a.id', 'a.description', 'a.properties', 'a.created_at', 'u.name as causer']);
        $truncated = $logs->count() > self::MAX_ROWS;
        $kinds = ['viewed' => 0, 'export' => 0, 'granted' => 0, 'revoked' => 0, 'other' => 0];
        $rows = $logs->take(self::MAX_ROWS)->map(function ($l) use (&$kinds) {
            $p = is_string($l->properties) ? (json_decode($l->properties, true) ?: []) : [];
            $d = strtolower($l->description);
            $kinds[str_contains($d, 'export') ? 'export' : (str_contains($d, 'granted') ? 'granted' : (str_contains($d, 'revoked') ? 'revoked' : (str_contains($d, 'viewed') || str_contains($d, 'previewed') || str_contains($d, 'built') ? 'viewed' : 'other')))]++;
            $details = array_filter([
                ! empty($p['type']) ? 'Report: ' . $p['type'] : null, ! empty($p['format']) ? 'Format: ' . $p['format'] : null,
                ! empty($p['period']) ? 'Period: ' . (is_array($p['period']) ? ($p['period']['label'] ?? '') : $p['period']) : null,
                ! empty($p['starts_on']) ? 'From ' . $p['starts_on'] : null, ! empty($p['ends_on']) ? 'Until ' . $p['ends_on'] : null,
            ]);

            return [
                'when' => $this->dt($l->created_at), 'who' => $l->causer ?: ($p['viewer'] ?? ''), 'action' => $l->description,
                'subject' => (string) ($p['subject'] ?? ''), 'viewer' => (string) ($p['viewer'] ?? ''), 'details' => implode('; ', $details),
            ];
        })->all();

        return [
            'subtitle' => $period->label,
            'columns' => [$this->col('when', 'Date and time'), $this->col('who', 'Done by'), $this->col('action', 'Action'), $this->col('subject', 'Whose brief'), $this->col('viewer', 'Viewer'), $this->col('details', 'Details')],
            'rows' => $rows, 'table_title' => __('Access log'),
            'tiles' => [['label' => __('Entries'), 'value' => count($rows)], ['label' => __('Views'), 'value' => $kinds['viewed']], ['label' => __('Exports'), 'value' => $kinds['export']],
                ['label' => __('Access granted'), 'value' => $kinds['granted']], ['label' => __('Access revoked'), 'value' => $kinds['revoked']]],
            'sections' => [],
            'notes' => $truncated ? ['More entries exist than the report limit; the most recent are shown.'] : ['Own views of your own brief are not logged. Opening a person\'s brief, building a report on it and every export are logged.'],
        ];
    }
}
