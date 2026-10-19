<?php

namespace App\Services\SmartBrief;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What is waiting for a person right now (not for a past period): open tasks, overdue tasks and reminders, and records that sit at a step
 * the person's role may act on. Each item is counted from the module's own table; an approval count means "items waiting at a step this
 * person is allowed to act on". The count is taken the way the module's own list is taken for that person (their projects, their account, their
 * visibility rules), so "3 waiting" always matches what they find when they open the list.
 */
class PendingCollector
{
    /** @return array<int, array{key:string,label:string,count:int,overdue:int,module_key:string,url:?string}> */
    public function for(User $subject, User $viewer): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $now = CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s');
        $items = [];
        $see = fn (string $module) => (int) $viewer->id === (int) $subject->id || Modules::visibleTo($viewer, $module);
        $can = fn (string $ability) => $subject->hasRole('Super Admin') || $subject->hasPermissionTo($ability);
        $has = function (string $ability) use ($subject) {
            try {
                return $subject->hasRole('Super Admin') || $subject->hasPermissionTo($ability);
            } catch (\Throwable) {
                return false;
            }
        };

        if ($see('task_manager')) {
            $open = DB::table('tasks as t')->join('task_assignees as a', 'a.task_id', '=', 't.id')
                ->where('a.assignee_type', User::class)->where('a.assignee_id', $subject->id)->whereNull('a.deleted_at')->whereNull('t.deleted_at')
                ->where('t.status', '!=', 'done');
            $count = (clone $open)->distinct()->count('t.id');
            if ($count) {
                $items[] = ['key' => 'tasks_open', 'label' => 'Open tasks', 'noun' => 'task', 'state' => 'open', 'count' => $count, 'overdue' => (clone $open)->whereNotNull('t.due_date')->where('t.due_date', '<', $today)->distinct()->count('t.id'),
                    'module_key' => 'task_manager', 'url' => $this->route('task-manager.index', $viewer, ['read-task-manager', 'read-all-task-manager']), 'records' => $this->taskRecords($viewer, [$subject->id]), 'hint' => 'Tasks not yet done, assigned to you.'];
            }
            $rem = DB::table('task_reminders')->where('user_id', $subject->id)->where('is_completed', 0)->whereNull('deleted_at');
            $remCount = (clone $rem)->count();
            if ($remCount) {
                $items[] = ['key' => 'reminders_open', 'label' => 'Open reminders', 'noun' => 'reminder', 'state' => 'open', 'count' => $remCount, 'overdue' => (clone $rem)->where('due_at', '<', $now)->count(),
                    'module_key' => 'task_manager', 'url' => $this->route('task-reminders.index', $viewer, ['read-task-reminders']), 'records' => [], 'hint' => 'Reminders not yet completed.'];
            }
        }

        if ($see('transfers') && $has('approve-transfers')) {
            $n = $this->approvalCount($subject, 'transfers');
            if ($n) {
                $items[] = ['key' => 'approve_transfers', 'label' => 'Transfers waiting for approval', 'noun' => 'transfer', 'state' => 'waiting for approval', 'count' => $n, 'overdue' => 0, 'module_key' => 'transfers', 'records' => $this->approvalRecords($subject, 'transfers'), 'hint' => 'Waiting for an approver. You can approve these.', 'url' => $this->route('transfers.index', $viewer, ['read-transfers'], ['view' => 'awaiting'])];
            }
        }
        if ($see('multi_transfers') && $has('approve-multi-transfers')) {
            $n = $this->approvalCount($subject, 'multi_transfers');
            if ($n) {
                $items[] = ['key' => 'approve_multi_transfers', 'label' => 'Multi Transfers waiting for approval', 'noun' => 'multi transfer', 'state' => 'waiting for approval', 'count' => $n, 'overdue' => 0, 'module_key' => 'multi_transfers', 'records' => $this->approvalRecords($subject, 'multi_transfers'), 'hint' => 'Waiting for an approver. You can approve these.', 'url' => $this->route('multi-transfers.index', $viewer, ['read-multi-transfers'])];
            }
        }
        if ($see('payment_vouchers') && $has('approve-payment-vouchers')) {
            $n = $this->approvalCount($subject, 'payment_vouchers');
            if ($n) {
                $items[] = ['key' => 'approve_vouchers', 'label' => 'Payment vouchers waiting for approval', 'noun' => 'payment voucher', 'state' => 'waiting for approval', 'count' => $n, 'overdue' => 0, 'module_key' => 'payment_vouchers', 'records' => $this->approvalRecords($subject, 'payment_vouchers'), 'hint' => 'Waiting for an approver. You can approve these.', 'url' => $this->route('payment-vouchers.index', $viewer, ['read-payment-vouchers'])];
            }
        }
        if ($see('material_requests') && $has('approve-material-requests')) {
            $n = $this->approvalCount($subject, 'material_requests');
            if ($n) {
                $items[] = ['key' => 'approve_requests', 'label' => 'Material requests waiting for approval', 'noun' => 'material request', 'state' => 'waiting for approval', 'count' => $n, 'overdue' => 0, 'module_key' => 'material_requests', 'records' => $this->approvalRecords($subject, 'material_requests'), 'hint' => 'Waiting for an approver. You can approve these.', 'url' => $this->route('material-requests.index', $viewer, ['read-material-requests', 'view-all-material-requests'], ['status' => 'Pending Approval'])];
            }
        }

        // every line says which module it belongs to (the same shape the Management tab uses)
        return array_map(fn ($i) => $i + ['module' => Modules::label($i['module_key'])], $items);
    }

    /**
     * How many records of a module wait at the approval step for this person, counted as that module's list would show them to this person:
     * the model's own account and visibility scopes (evaluated for the person, not for whoever is looking) and, for transfers, the person's projects.
     */
    public function approvalCount(User $who, string $module): int
    {
        return (int) $this->asUser($who, fn () => $this->approvalQuery($who, $module)?->count() ?? 0);
    }

    /** The first few waiting records (reference and where to open them), newest first, for the "Waiting" lists. */
    public function approvalRecords(User $who, string $module, int $limit = 5): array
    {
        return $this->asUser($who, function () use ($who, $module, $limit) {
            $q = $this->approvalQuery($who, $module);
            if (! $q) {
                return [];
            }
            [$route, $ability] = ['transfers' => ['transfers.show', 'read-transfers'], 'multi_transfers' => ['multi-transfers.show', 'read-multi-transfers'], 'payment_vouchers' => ['payment-vouchers.show', 'read-payment-vouchers'], 'material_requests' => ['material-requests.show', 'read-material-requests']][$module];
            $col = $module === 'payment_vouchers' ? 'pv_number' : 'reference';

            return $q->orderByDesc('id')->limit($limit)->get(['id', $col])->map(fn ($r) => [
                'reference' => (string) ($r->{$col} ?: ('#' . $r->id)),
                'url' => \Illuminate\Support\Facades\Route::has($route) && $who->can($ability) ? route($route, $r->id, false) : null,
            ])->values()->all();
        });
    }

    /** Open tasks assigned to these people: title and where to open each. */
    public function taskRecords(User $viewer, array $assigneeIds, int $limit = 5): array
    {
        if (! $assigneeIds) {
            return [];
        }
        $rows = DB::table('tasks as t')->join('task_assignees as a', 'a.task_id', '=', 't.id')->where('a.assignee_type', User::class)->whereIn('a.assignee_id', $assigneeIds)
            ->whereNull('a.deleted_at')->whereNull('t.deleted_at')->where('t.status', '!=', 'done')->groupBy('t.id', 't.title', 't.due_date')
            ->orderByRaw('t.due_date IS NULL, t.due_date')->limit($limit)->get(['t.id', 't.title', 't.due_date']);
        $can = $viewer->can('read-task-manager') || $viewer->can('read-all-task-manager');

        return $rows->map(fn ($r) => ['reference' => (string) $r->title, 'url' => $can && \Illuminate\Support\Facades\Route::has('task-manager.index') ? route('task-manager.index', ['task' => $r->id], false) : null])->values()->all();
    }

    /** The module's own query for "waiting at the approval step", seen as that person sees the module's list. */
    private function approvalQuery(User $who, string $module)
    {
        if ($module === 'transfers') {
            $q = \App\Models\Transfer::query()->where('approval_status', 'pending');
            $ids = $this->projectIds($who);
            if ($ids !== null) {
                $q->withoutGlobalScope('mine')->where(fn ($w) => $w->whereIn('from_warehouse_id', $ids)->orWhereIn('to_warehouse_id', $ids));
            }

            return $q;
        }
        if ($module === 'multi_transfers') {
            return \App\Models\MultiTransfer::query()->visibleTo($who)->whereHas('transfers', fn ($t) => $t->withoutGlobalScope('mine')->where('approval_status', 'pending'));
        }
        if ($module === 'payment_vouchers') {
            return \App\Models\PaymentVoucher::query()->where('status', 'Submitted');
        }
        if ($module === 'material_requests') {
            return \App\Models\MaterialRequest::query()->where('status', 'Pending Approval');
        }

        return null;
    }

    private function asUser(User $who, \Closure $fn)
    {
        $previous = auth()->user();
        auth()->setUser($who);
        try {
            return $fn();
        } finally {
            if ($previous) {
                auth()->setUser($previous);
            } elseif (method_exists(auth()->guard(), 'forgetUser')) {
                auth()->guard()->forgetUser();
            }
        }
    }

    /** The projects/warehouses a person works in (null = every one), the same rule the module lists use (accessible_warehouse_ids). */
    private function projectIds(User $who): ?array
    {
        $assigned = $who->warehouses()->pluck('warehouses.id')->all();
        if (! empty($assigned)) {
            return $assigned;
        }
        if ($who->hasRole(['Super Admin', 'Admin'])) {
            return null;
        }

        return $who->warehouse_id ? [$who->warehouse_id] : null;
    }

    public function route(string $name, User $viewer, array $abilities, array $query = []): ?string
    {
        if (! \Illuminate\Support\Facades\Route::has($name)) {
            return null;
        }
        foreach ($abilities as $a) {
            if ($viewer->can($a)) {
                return route($name, $query, false);
            }
        }

        return null;
    }
}
