<?php

namespace App\Services\SmartBrief;

use App\Models\User;
use App\Support\ActivityModules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated view for managers: how much recorded activity there was across the people the viewer is allowed to see, by person and by
 * module, plus what is waiting and what looks exceptional. Counts come from the same sources and the same exclusions as a single brief
 * (record events and workflow steps, sign-ins, reports), cut down to the modules the viewer may read. They describe recorded activity, never
 * productivity: nothing is ranked or scored, and people are listed alphabetically.
 */
class Overview
{
    private const LINE = '/(Item|Items|Line|Lines|Trail|Detail|Details|Recipient|Recipients|Assignee|Assignees|Attachment|Attachments|Channel|Photo|Photos)$/';

    public function build(User $viewer, Period $period, array $filters = []): array
    {
        $settings = Settings::get($viewer->account_id);
        $subjects = $this->subjects($viewer, $filters);
        $ids = $subjects->pluck('id')->all();
        $sensitive = $viewer->can('view-smart-brief-sensitive');
        $visible = array_flip(Modules::visibleKeys($viewer));
        $excluded = array_merge((array) $settings['excluded_log_names'], ['MaterialRequestStatusLog', 'DailySiteReportLog', 'MaterialReallocationLog', 'IssueReturnLog', 'DigitalAttendanceEvent']);

        $per = [];
        $modules = [];
        if ($ids) {
            $rows = DB::table('activity_log')->where('account_id', $viewer->account_id)->where('causer_type', User::class)->whereIn('causer_id', $ids)
                ->whereBetween('created_at', $period->sql())->whereNotIn('log_name', $excluded)->where('description', 'not like', '%logged in%')
                ->where(fn ($q) => $q->whereNull('event')->orWhere('event', '!=', 'updated')->orWhere('properties', 'like', '%"attributes":{%'))
                ->selectRaw('causer_id, log_name, count(*) as c, max(created_at) as last_at')->groupBy('causer_id', 'log_name')->get();
            foreach ($rows as $r) {
                if (preg_match(self::LINE, $r->log_name)) {
                    continue;
                }
                $key = ActivityModules::keyFor($r->log_name);
                if (! isset($visible[$key])) {
                    continue;
                }
                $per[$r->causer_id]['activity'] = ($per[$r->causer_id]['activity'] ?? 0) + $r->c;
                $per[$r->causer_id]['last'] = max($per[$r->causer_id]['last'] ?? '', $r->last_at);
                $modules[$key] = ($modules[$key] ?? 0) + $r->c;
            }
            DB::table('login_logs')->where('status', 'success')->whereIn('user_id', $ids)->whereBetween('attempted_at', $period->sql())
                ->selectRaw('user_id, count(*) c, max(attempted_at) last_at')->groupBy('user_id')->get()->each(function ($r) use (&$per) {
                    $per[$r->user_id]['logins'] = $r->c;
                    $per[$r->user_id]['last'] = max($per[$r->user_id]['last'] ?? '', $r->last_at);
                });
            if ($sensitive) {
                DB::table('login_logs')->where('status', 'failed')->whereIn('user_id', $ids)->whereBetween('attempted_at', $period->sql())
                    ->selectRaw('user_id, count(*) c')->groupBy('user_id')->get()->each(fn ($r) => $per[$r->user_id]['failed'] = $r->c);
            }
            DB::table('download_print_logs')->whereIn('user_id', $ids)->whereBetween('performed_at', $period->sql())
                ->selectRaw('user_id, count(*) c')->groupBy('user_id')->get()->each(fn ($r) => $per[$r->user_id]['reports'] = $r->c);
        }

        $people = $subjects->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'roles' => $u->roles->pluck('name')->values()->all(),
            'activity' => $per[$u->id]['activity'] ?? 0, 'logins' => $per[$u->id]['logins'] ?? 0, 'reports' => $per[$u->id]['reports'] ?? 0,
            'failed' => $sensitive ? ($per[$u->id]['failed'] ?? 0) : null, 'last_at' => ($per[$u->id]['last'] ?? '') ?: null,
        ])->values();

        return [
            'period' => $period->toArray(),
            'totals' => [
                'people' => $people->count(),
                'active' => $people->filter(fn ($p) => $p['activity'] > 0 || $p['logins'] > 0)->count(),
                'activity' => $people->sum('activity'), 'logins' => $people->sum('logins'), 'reports' => $people->sum('reports'),
                'failed' => $sensitive ? $people->sum('failed') : null,
            ],
            'people' => $people->all(),
            'modules' => collect($modules)->map(fn ($c, $k) => ['key' => $k, 'module' => Modules::label($k), 'count' => $c])->sortByDesc('count')->values()->all(),
            'waiting' => $this->waiting($viewer, $ids, $visible),
            'note' => 'These are counts of recorded activity. They are not a measure of productivity or performance: signing in, or creating many records, does not by itself mean someone is doing better or worse work.',
        ];
    }

    /** The users the viewer may include, after the optional role / project / search filters. */
    public function subjects(User $viewer, array $filters = [])
    {
        $q = Access::viewableUsers($viewer)->with('roles:id,name')->orderBy('name');
        if (! empty($filters['role'])) {
            $q->whereHas('roles', fn ($r) => $r->where('name', $filters['role']));
        }
        if (! empty($filters['project'])) {
            $q->whereIn('id', DB::table('user_warehouse')->where('warehouse_id', (int) $filters['project'])->pluck('user_id'));
        }
        if (! empty($filters['q'])) {
            $term = '%' . $filters['q'] . '%';
            $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('username', 'like', $term)->orWhere('email', 'like', $term));
        }
        if (! empty($filters['user_ids'])) {
            $q->whereIn('id', array_map('intval', (array) $filters['user_ids']));
        }

        return $q->limit((int) ($filters['limit'] ?? 300))->get(['id', 'name', 'username', 'email', 'account_id', 'last_seen_at']);
    }

    /**
     * What needs somebody's action right now, for the people in view. Each line says what it is, how many, and opens the list that holds them
     * (in a new tab). Approvals are only listed when the viewer may approve that kind of record, and are counted the way that module's own
     * list shows them to the viewer; they wait for an approver, not for one of the people in view, so they are labelled that way.
     */
    private function waiting(User $viewer, array $ids, array $visible): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $out = [];
        $pending = new PendingCollector();
        $can = fn (string $ability) => $viewer->hasRole('Super Admin') || $viewer->can($ability);
        $approvals = [
            ['transfers', 'approve-transfers', 'Transfers waiting for approval', 'transfers.index', ['read-transfers'], ['view' => 'awaiting']],
            ['multi_transfers', 'approve-multi-transfers', 'Multi Transfers waiting for approval', 'multi-transfers.index', ['read-multi-transfers'], []],
            ['payment_vouchers', 'approve-payment-vouchers', 'Payment vouchers waiting for approval', 'payment-vouchers.index', ['read-payment-vouchers'], []],
            ['material_requests', 'approve-material-requests', 'Material requests waiting for approval', 'material-requests.index', ['read-material-requests', 'view-all-material-requests'], ['status' => 'Pending Approval']],
        ];
        foreach ($approvals as [$module, $ability, $label, $route, $read, $query]) {
            if (! isset($visible[$module]) || ! $can($ability)) {
                continue;
            }
            if ($n = $pending->approvalCount($viewer, $module)) {
                $out[] = ['label' => $label, 'count' => $n, 'overdue' => 0, 'module' => Modules::label($module), 'url' => $pending->route($route, $viewer, $read, $query), 'hint' => 'Waiting for an approver. You can approve these.', 'records' => $pending->approvalRecords($viewer, $module)];
            }
        }
        if ($ids && isset($visible['task_manager'])) {
            $open = DB::table('tasks as t')->join('task_assignees as a', 'a.task_id', '=', 't.id')->where('a.assignee_type', User::class)->whereIn('a.assignee_id', $ids)
                ->whereNull('a.deleted_at')->whereNull('t.deleted_at')->where('t.status', '!=', 'done');
            $count = (clone $open)->distinct()->count('t.id');
            if ($count) {
                $out[] = ['label' => 'Open tasks assigned to these people', 'count' => $count, 'overdue' => (clone $open)->whereNotNull('t.due_date')->where('t.due_date', '<', $today)->distinct()->count('t.id'), 'module' => Modules::label('task_manager'), 'url' => $pending->route('task-manager.index', $viewer, ['read-task-manager', 'read-all-task-manager']), 'hint' => 'Tasks not yet done, assigned to the people listed here.', 'records' => $pending->taskRecords($viewer, $ids)];
            }
        }

        return $out;
    }
}
