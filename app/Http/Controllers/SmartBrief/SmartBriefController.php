<?php

namespace App\Http\Controllers\SmartBrief;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SmartBrief\Access;
use App\Services\SmartBrief\Alerts;
use App\Services\SmartBrief\Modules;
use App\Services\SmartBrief\Overview;
use App\Services\SmartBrief\Period;
use App\Services\SmartBrief\Settings;
use App\Services\SmartBrief\SmartBriefService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * WIMS Smart Brief: My Smart Brief and Management Smart Brief in one interface. Every action authorises explicitly (the page, the JSON data
 * and the exports all call Access); the auto-derived CRUD abilities are switched off because these are not CRUD routes.
 */
class SmartBriefController extends Controller
{
    public function __construct(private SmartBriefService $service, private Overview $overview)
    {
        $this->setAbilities([]);
    }

    /** /smart-brief: the right starting point for this person. */
    public function home(Request $request)
    {
        $user = $request->user();
        if (Access::canViewOwn($user)) {
            return redirect()->route('smart-brief.my.index');
        }
        abort_unless(Access::canOpenManagement($user), 403);

        return redirect()->route('smart-brief.management.index');
    }

    public function my(Request $request)
    {
        abort_unless(Access::canViewOwn($request->user()), 403);
        app(\App\Services\SmartBrief\LogCleaner::class)->autoClearIfDue((int) $request->user()->account_id);

        return $this->page($request, 'my');
    }

    /** The Clear button: removes this person's own Smart Brief log lines (never the record of opening somebody else's brief). */
    public function clear(Request $request)
    {
        abort_unless(Access::canViewOwn($request->user()), 403);
        $n = app(\App\Services\SmartBrief\LogCleaner::class)->clearOwn($request->user());

        return response()->json(['removed' => $n, 'message' => $n ? __(':n Smart Brief log line(s) were cleared.', ['n' => $n]) : __('There was nothing to clear.')]);
    }

    public function management(Request $request)
    {
        abort_unless(Access::canOpenManagement($request->user()), 403);

        return $this->page($request, 'management');
    }

    private function page(Request $request, string $mode)
    {
        $user = $request->user();
        $settings = Settings::get($user->account_id);

        return Inertia::render('SmartBrief/Index', [
            'mode' => $mode,
            'openUser' => $mode === 'management' && $request->integer('user') ? $request->integer('user') : null,
            'can' => $this->abilities($user),
            'prefs' => ['default_period' => $settings['default_period'], 'max_range_days' => $settings['max_range_days'], 'show_line_items' => (bool) $settings['show_line_items'], 'auto_clear_days' => (int) $settings['auto_clear_days']],
            'periods' => collect(Period::KEYS)->map(fn ($k) => ['key' => $k, 'label' => $k === 'custom' ? __('Custom range') : Period::resolve($k)->label])->values()->all(),
            'people' => $mode === 'management' ? $this->overview->subjects($user, ['limit' => 500])->map(fn ($u) => [
                'value' => $u->id, 'label' => $u->name . ($u->roles->isNotEmpty() ? ' (' . $u->roles->pluck('name')->implode(', ') . ')' : ''), 'name' => $u->name,
            ])->values()->all() : [],
            'filters' => $mode === 'management' ? [
                'roles' => \App\Models\Role::ofAccount()->orderBy('name')->pluck('name'),
                'projects' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'status']),
                'modules' => collect(Modules::visibleKeys($user))->map(fn ($k) => ['key' => $k, 'label' => Modules::label($k)])->sortBy('label')->values(),
                'kinds' => collect(['created', 'updated', 'submitted', 'approved', 'rejected', 'deleted', 'report', 'comment'])->map(fn ($k) => ['key' => $k, 'label' => ucfirst($k === 'report' ? 'reports and downloads' : $k)]),
            ] : null,
        ]);
    }

    /** The viewer's own brief (JSON). */
    public function myData(Request $request)
    {
        $user = $request->user();
        abort_unless(Access::canViewOwn($user), 403);

        return response()->json($this->service->brief($user, $user, $this->period($request, $user), $this->options($request)));
    }

    /** Searchable list of the people the viewer may open. */
    public function users(Request $request)
    {
        $user = $request->user();
        abort_unless(Access::canOpenManagement($user), 403);
        $filters = $request->only(['q', 'role', 'project']) + ['limit' => 40];

        return response()->json($this->overview->subjects($user, $filters)->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'roles' => $u->roles->pluck('name')->values()->all(),
        ])->values());
    }

    /** Another person's brief (JSON). Authorised on the server for this exact person; the view is recorded. */
    public function userBrief(Request $request, User $user)
    {
        $viewer = $request->user();
        abort_unless(Access::canViewSubject($viewer, $user), 403, __('You are not allowed to view this person\'s Smart Brief.'));
        $period = $this->period($request, $viewer);
        $brief = $this->service->brief($viewer, $user, $period, $this->options($request));
        Access::audit($viewer, $user, 'viewed', ['period' => $period->label, 'from' => $period->from->toDateString(), 'to' => $period->to->toDateString(), 'sections' => array_keys(array_filter($brief['sections']))]);
        Alerts::fire('brief_viewed', $viewer, $user, __('Your Smart Brief was opened'), __(':v opened your Smart Brief (:p).', ['v' => $viewer->name, 'p' => $period->label]), ['period' => $period->label]);

        return response()->json($brief);
    }

    /** Aggregated summary of the people the viewer may see. */
    public function overview(Request $request)
    {
        $viewer = $request->user();
        abort_unless(Access::canOpenManagement($viewer), 403);
        $period = $this->period($request, $viewer);
        $filters = $request->only(['role', 'project', 'q']);
        $data = $this->overview->build($viewer, $period, $filters);
        log_activity('Smart Brief team summary viewed', ['viewer_user_id' => $viewer->id, 'viewer' => $viewer->name, 'people' => $data['totals']['people'], 'period' => $period->label,
            'from' => $period->from->toDateString(), 'to' => $period->to->toDateString(), 'filters' => array_filter($filters)], null, 'Smart Brief');
        Alerts::fire('team_viewed', $viewer, null, __('Team summary opened'), __(':v opened the Smart Brief team summary (:p).', ['v' => $viewer->name, 'p' => $period->label]), ['period' => $period->label], route('smart-brief.management.index'));

        return response()->json($data);
    }

    private function abilities(User $user): array
    {
        return [
            'own' => Access::canViewOwn($user),
            'management' => Access::canOpenManagement($user),
            'team' => Access::canOpenManagement($user) && Access::feature('smart_brief_team', $user),
            'reports' => $user->can('view-smart-brief-reports') && Access::feature('smart_brief_reports', $user),
            'settings' => $user->can('manage-smart-brief-settings') && Access::feature('smart_brief_settings', $user),
            'print' => Access::canViewOwn($user) && Access::feature('smart_brief_export', $user),
            'export' => Access::feature('smart_brief_export', $user),
        ];
    }

    private function period(Request $request, User $user): Period
    {
        $settings = Settings::get($user->account_id);
        try {
            return Period::resolve($request->query('period', $settings['default_period']), $request->query('from'), $request->query('to'), (int) $settings['max_range_days']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    private function options(Request $request): array
    {
        return array_filter([
            'module' => $request->query('module') ?: null,
            'kind' => $request->query('kind') ?: null,
            'lines' => $request->boolean('lines') ?: null,
        ], fn ($v) => $v !== null);
    }
}
