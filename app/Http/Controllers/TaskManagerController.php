<?php

namespace App\Http\Controllers;

use App\Events\TaskAssigned;
use App\Events\TaskStatusChanged;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\TaskDueReminder;
use App\Models\User;
use App\Support\TaskReferenceRegistry;
use App\Traits\HasActivityHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;
use Tecdiary\Laravel\Attachments\Attachment;

class TaskManagerController extends Controller
{
    use HasActivityHistory;

    // Team Manager is an internal-team tool — assignment is WIMS Users
    // only (no Staff/Contact), so every assignee row this controller writes
    // always carries this type. The underlying task_assignees pivot stays
    // polymorphic (Eloquent's standard shape), just unused for other types.
    protected const ASSIGNEE_TYPE = User::class;

    // Custom actions here (move/assign/etc.) aren't in Authorizable's
    // $abilities map, so they'd get no automatic permission check at all —
    // opting out entirely and authorizing every action explicitly against
    // TaskPolicy is the same pattern CaseTrackingController uses for the
    // same reason.
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Task::class);

        $data = $request->validate([
            'search' => 'nullable|string|max:150',
            'assigned_to_user_id' => 'nullable|integer',
            'priority' => 'nullable|string|in:low,normal,high,urgent',
            'category' => 'nullable|string|max:100',
            'created_by_user_id' => 'nullable|string|max:20',
        ]);

        $mine = $request->routeIs('task-manager.mine');

        $query = $this->scopedQuery($request, $request->user(), $mine);

        if (! empty($data['search'])) {
            $query->where(function ($q) use ($data) {
                $q->where('title', 'like', '%' . $data['search'] . '%')
                  ->orWhere('description', 'like', '%' . $data['search'] . '%');
            });
        }

        if (! empty($data['priority'])) {
            $query->where('priority', $data['priority']);
        }

        if (! empty($data['category'])) {
            $query->where('category', $data['category']);
        }

        if (! $mine && ! empty($data['assigned_to_user_id'])) {
            $query->whereHas('assignees', function ($q) use ($data) {
                $q->where('assignee_type', User::class)->where('assignee_id', $data['assigned_to_user_id']);
            });
        }

        // "Created By" filter — Team Manager only (My Tasks is already
        // scoped to the viewer), and only for whoever can see everyone's
        // tasks in the first place (Super Admin/Admin, or anyone granted
        // the "Global" permission below) — for everyone else this filter
        // would be a no-op on top of scopedQuery()'s own-tasks-only
        // restriction anyway, so it's left out entirely rather than shown
        // and silently ignored.
        $canUseGlobalFilter = ! $mine && ($request->user()->hasRole(['Super Admin', 'Admin']) || $request->user()->can('global-task-manager'));

        $createdByFilter = $data['created_by_user_id'] ?? null;
        if ($canUseGlobalFilter) {
            // Absent entirely (first load, no query string yet) defaults to
            // "all" — the frontend's filter dropdown mirrors this same
            // default so its selected option matches what's actually shown.
            if ($createdByFilter === null) {
                $createdByFilter = 'all';
            }
            if ($createdByFilter !== 'all') {
                $query->where('created_by_user_id', (int) $createdByFilter);
            }
        } else {
            $createdByFilter = null;
        }

        return Inertia::render('TaskManager/Board', [
            'tasks' => $query->orderBy('position')->get(),
            'assignableUsers' => User::ofAccount()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'taskCategories' => $this->availableCategories(),
            'team' => $mine ? [] : $this->teamRoster(),
            'recentActivity' => $mine ? [] : $this->recentActivity(),
            'taskCreators' => $canUseGlobalFilter ? $this->taskCreators() : [],
            'canUseGlobalCreatorFilter' => $canUseGlobalFilter,
            'referenceModules' => TaskReferenceRegistry::moduleKeys(),
            'referenceModuleList' => TaskReferenceRegistry::moduleList(),
            'currentUser' => ['id' => $request->user()->id, 'name' => $request->user()->name],
            'filters' => array_merge($data, ['created_by_user_id' => $createdByFilter]),
            'mine' => $mine,
        ]);
    }

    public function detail(Task $task)
    {
        $this->authorize('view', $task);

        $task->load([
            'createdBy:id,name',
            'assignees.assignee',
            'comments.user:id,name',
            'checklistItems',
            'attachments',
        ]);

        return response()->json([
            'task' => $task,
            'activity' => $this->activityHistory(Task::class, $task->id),
        ]);
    }

    /**
     * Resolves a checklist "@module (CODE)" mention to the real record's
     * show page and redirects there. Deliberately only whitelisted modules
     * (TaskReferenceRegistry) — never settings/logs/backups. The target
     * page's own route/policy still applies on landing, so a user who can't
     * actually view that record is stopped there, same as navigating to it
     * directly.
     */
    public function resolveReference(Request $request)
    {
        $data = $request->validate([
            'module' => 'required|string',
            'code' => 'required|string|max:100',
        ]);

        $config = TaskReferenceRegistry::modules()[$data['module']] ?? null;
        abort_unless($config, 404);

        $model = $config['model'];
        $record = ($config['match'])($model::query(), trim($data['code']))->first();
        abort_unless($record, 404, __('No :label matches ":code".', ['label' => $config['label'], 'code' => $data['code']]));

        return redirect()->route($config['route'], [$config['param'] => $record->id]);
    }

    /**
     * Powers the "@" autocomplete in the checklist input — partial search
     * within one whitelisted module, returning just enough (code + a short
     * title) to tell candidates apart while typing.
     */
    public function searchReferences(Request $request)
    {
        $data = $request->validate([
            'module' => 'required|string',
            'q' => 'nullable|string|max:100',
        ]);

        $config = TaskReferenceRegistry::modules()[$data['module']] ?? null;
        abort_unless($config, 404);

        $model = $config['model'];
        $query = $model::query();
        $term = trim((string) ($data['q'] ?? ''));
        if ($term !== '') {
            $query = ($config['search'])($query, $term);
        }

        $results = $query->latest()->limit(8)->get()
            ->map(fn ($r) => ['code' => ($config['code'])($r), 'title' => ($config['title'])($r)])
            ->filter(fn ($r) => filled($r['code']))
            ->values();

        return response()->json(['results' => $results]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Task::class);

        $data = $this->validateTask($request);

        $maxPosition = (int) Task::where('status', 'todo')->max('position');

        // "personal_task" is a UI-intent signal from the "My Tasks" create
        // modal (never a security boundary by itself — created_by_user_id
        // below is always the authenticated user, never client-supplied),
        // only ever making the CREATOR's own task more private to
        // everyone else, never less. Restricted to Super Admin per the
        // feature request — a non-Super-Admin's personal task keeps the
        // existing (non-private) visibility rules unchanged.
        $isPrivate = $request->boolean('personal_task') && $request->user()->hasRole('Super Admin');

        $task = Task::create(array_merge($data, [
            'status' => 'todo',
            'position' => $maxPosition + 1,
            'created_by_user_id' => $request->user()->id,
            'is_private' => $isPrivate,
        ]));

        if ($request->hasFile('attachments')) {
            $task->saveAttachments($request->file('attachments'));
        }

        log_activity(__(':title created.', ['title' => $task->title]), [], $task, 'Task Manager');

        $assigneeIds = $request->validate([
            'assignees' => 'sometimes|array',
            'assignees.*.id' => 'required_with:assignees|integer',
        ])['assignees'] ?? [];

        if ($assigneeIds && Gate::allows('assignMultiple', $task)) {
            $user = $request->user();

            $added = [];

            foreach ($assigneeIds as $a) {
                $taskAssignee = TaskAssignee::create([
                    'task_id' => $task->id,
                    'assignee_type' => self::ASSIGNEE_TYPE,
                    'assignee_id' => $a['id'],
                    'assigned_by_user_id' => $user->id,
                ]);

                $name = $taskAssignee->assignee?->name ?? __('Unknown');
                log_activity(__(':name assigned to :title.', ['name' => $name, 'title' => $task->title]), [], $task, 'Task Manager');

                $added[] = $taskAssignee;
            }

            // ONE event for the whole save, so each recipient is notified once however many people were assigned.
            if ($added) {
                event(new TaskAssigned($task, $added, $user->id));
            }
        }

        \App\Services\TaskWorkflowNotifier::fire('created', $task, $request->user(), __('Task Created'), $task->title . ' was created by ' . $request->user()->name . '.');

        return back()->with('message', __('Task created.'));
    }

    public function update(Request $request, Task $task)
    {
        $this->authorize('update', $task);

        $data = $this->validateTask($request);

        $originalDueDate = $task->due_date?->format('Y-m-d');
        $originalDueTime = $task->due_time;

        $task->update($data);

        // A changed due date/time invalidates any already-sent due reminder
        // for this task, so DispatchTaskDueReminders re-evaluates it against
        // the new due time instead of staying silenced forever. Force-delete
        // (not the default soft delete) so the (task_id, user_id) unique
        // index doesn't block the row being recreated once the reminder
        // fires again.
        // Compare as H:i. The database hands back "09:00:00" while the form posts "09:00", so a plain
        // !== treated EVERY save as a changed due time, wiped the "already reminded" marker, and the
        // next scheduler run sent the due reminder again — the second, later notification people saw.
        $hhmm = fn ($t) => $t ? substr((string) $t, 0, 5) : null;

        if ($originalDueDate !== $task->due_date?->format('Y-m-d') || $hhmm($originalDueTime) !== $hhmm($task->due_time)) {
            TaskDueReminder::where('task_id', $task->id)->forceDelete();
        }

        if ($request->hasFile('attachments')) {
            $task->saveAttachments($request->file('attachments'));
        }

        log_activity(__(':title updated.', ['title' => $task->title]), [], $task, 'Task Manager');

        \App\Services\TaskWorkflowNotifier::fire('updated', $task->fresh(), $request->user(), __('Task Edited'), $task->title . ' was edited by ' . $request->user()->name . '.');

        return back()->with('message', __('Task updated.'));
    }

    public function move(Request $request, Task $task)
    {
        $data = $request->validate([
            'status' => 'required|string|in:todo,in_progress,review,done',
            'ordered_ids' => 'required|array',
            'ordered_ids.*' => 'integer',
        ]);

        // Entering or leaving "done" is the privileged "mark complete /
        // un-complete" action (see TaskPolicy::markComplete) — reshuffling
        // between the other columns only needs the broader changeStatus.
        if ($data['status'] === 'done' || $task->status === 'done') {
            $this->authorize('markComplete', $task);
        } else {
            $this->authorize('changeStatus', $task);
        }

        $user = $request->user();

        // Only reorder tasks this user is actually allowed to see — the moved
        // task is authorised above, but the client also submits every other id in the column.
        $reorderableIds = Task::whereIn('id', $data['ordered_ids'])->get()
            ->filter(fn (Task $t) => $t->id === $task->id || $user->can('view', $t))
            ->pluck('id')->all();

        DB::transaction(function () use ($task, $data, $user, $reorderableIds) {
            $locked = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $previousStatus = $locked->status;

            $locked->update([
                'status' => $data['status'],
                'completed_at' => $data['status'] === 'done' ? ($locked->completed_at ?? now()) : null,
            ]);

            foreach ($data['ordered_ids'] as $index => $id) {
                if (in_array($id, $reorderableIds, true)) {
                    Task::whereKey($id)->update(['position' => $index]);
                }
            }

            if ($previousStatus !== $data['status']) {
                log_activity(
                    __(':title moved from :from to :to.', ['title' => $locked->title, 'from' => $previousStatus, 'to' => $data['status']]),
                    [],
                    $locked,
                    'Task Manager'
                );

                event(new TaskStatusChanged($locked->fresh(), $previousStatus, $user->id));
            }
        });

        return back()->with('message', __('Task updated.'));
    }

    /**
     * Replace-set sync for the whole assignee list — accepts the full
     * intended membership and diffs it against current pivot rows inside a
     * transaction, so notifications/activity only fire for what actually
     * changed rather than re-announcing everyone on every edit.
     */
    public function syncAssignees(Request $request, Task $task)
    {
        $this->authorize('assignMultiple', $task);

        $data = $request->validate([
            'assignees' => 'present|array',
            'assignees.*.id' => 'required|integer',
        ]);

        $wanted = collect($data['assignees'])->pluck('id')->unique();
        $user = $request->user();

        $removedUsers = [];
        DB::transaction(function () use ($task, $wanted, $user, &$removedUsers) {
            $existing = $task->assignees()->where('assignee_type', self::ASSIGNEE_TYPE)->get()->keyBy('assignee_id');

            // Remove assignees no longer wanted.
            foreach ($existing as $id => $row) {
                if (! $wanted->contains($id)) {
                    $name = $row->assignee?->name ?? __('Unknown');
                    if ($row->assignee instanceof \App\Models\User) {
                        $removedUsers[] = $row->assignee;
                    }
                    $row->delete();
                    log_activity(__(':name removed from :title.', ['name' => $name, 'title' => $task->title]), [], $task, 'Task Manager');
                }
            }

            // Add newly-wanted assignees.
            $added = [];

            foreach ($wanted as $id) {
                if ($existing->has($id)) {
                    continue;
                }

                // a person taken off earlier is brought back (the row was only soft-deleted and the pair is unique)
                $taskAssignee = TaskAssignee::withTrashed()->where(['task_id' => $task->id, 'assignee_type' => self::ASSIGNEE_TYPE, 'assignee_id' => $id])->first();
                if ($taskAssignee) {
                    $taskAssignee->restore();
                    $taskAssignee->update(['assigned_by_user_id' => $user->id]);
                } else {
                    $taskAssignee = TaskAssignee::create([
                        'task_id' => $task->id,
                        'assignee_type' => self::ASSIGNEE_TYPE,
                        'assignee_id' => $id,
                        'assigned_by_user_id' => $user->id,
                    ]);
                }

                $name = $taskAssignee->assignee?->name ?? __('Unknown');
                log_activity(__(':name assigned to :title.', ['name' => $name, 'title' => $task->title]), [], $task, 'Task Manager');

                $added[] = $taskAssignee;
            }

            // ONE event for the whole save (see store()).
            if ($added) {
                event(new TaskAssigned($task, $added, $user->id));
            }
        });

        if ($removedUsers) {
            \App\Services\TaskWorkflowNotifier::fire('unassigned', $task->fresh(), $user, __('Task Unassigned'), collect($removedUsers)->pluck('name')->implode(', ') . ' was taken off ' . $task->title . ' by ' . $user->name . '.', ['new_users' => $removedUsers]);
        }

        return back()->with('message', __('Task assignment updated.'));
    }

    public function destroy(Request $request, Task $task)
    {
        $this->authorize('delete', $task);

        $task->loadMissing('createdBy', 'assignees.assignee');
        $actorName = $request->user()->name ?? __('Someone');

        $task->delete();

        \App\Services\TaskWorkflowNotifier::fire('deleted', $task, $request->user(), __('Task Deleted'), $task->title . ' was deleted by ' . $actorName . '.');

        return back()->with('message', __('Task deleted.'));
    }

    public function activity(Task $task)
    {
        $this->authorize('view', $task);

        return response()->json($this->activityHistory(Task::class, $task->id));
    }

    public function downloadAttachment(Task $task, Attachment $attachment)
    {
        $this->authorize('view', $task);
        abort_unless((int) $attachment->model_id === (int) $task->id && $attachment->model_type === Task::class, 404);

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }

    public function destroyAttachment(Task $task, Attachment $attachment)
    {
        $this->authorize('attach', $task);
        abort_unless((int) $attachment->model_id === (int) $task->id && $attachment->model_type === Task::class, 404);

        $name = $attachment->title ?: $attachment->filename;
        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        log_activity(__('Attachment :name removed from :title.', ['name' => $name, 'title' => $task->title]), [], $task, 'Task Manager');

        return back()->with('message', __('Attachment removed.'));
    }

    protected function scopedQuery(Request $request, User $user, bool $mine): Builder
    {
        $query = Task::query()
            ->with(['createdBy:id,name', 'assignees.assignee'])
            ->withCount([
                'comments',
                'checklistItems',
                'checklistItems as checklist_done_count' => fn ($q) => $q->where('is_done', true),
                'attachments',
            ]);

        // "My Tasks" always scopes to the current user, even for someone who
        // holds read-all-task-manager — the whole point of that view is
        // "just mine", not "everyone's, filtered" (same reasoning as
        // Case Tracking's .mine route).
        if ($mine || (! $user->hasRole(['Super Admin', 'Admin']) && ! $user->can('read-all-task-manager'))) {
            $query->where(function ($q) use ($user) {
                $q->where('created_by_user_id', $user->id)
                  ->orWhereHas('assignees', fn ($aq) => $aq->where('assignee_type', User::class)->where('assignee_id', $user->id));
            });
        } else {
            // Full-visibility viewer (Super Admin/Admin/read-all-task-manager)
            // on the Team Manager list — still excludes another user's
            // private personal task, matching TaskPolicy::view()'s own
            // is_private check for direct access.
            $query->where(function ($q) use ($user) {
                $q->where('is_private', false)
                  ->orWhere('created_by_user_id', $user->id)
                  ->orWhereHas('assignees', fn ($aq) => $aq->where('assignee_type', User::class)->where('assignee_id', $user->id));
            });
        }

        return $query;
    }

    protected function validateTask(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'priority' => 'required|in:low,normal,high,urgent',
            'category' => 'nullable|string|max:100',
            'start_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
            'due_date' => 'nullable|date',
            'due_time' => 'nullable|date_format:H:i',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:10240',
        ]);

        unset($data['attachments']);

        return $data;
    }

    /**
     * 20 sensible defaults, unioned with whatever categories have actually
     * been typed in already — no dedicated task_categories table exists (or
     * is needed yet), so "add a category" is just typing a new value on the
     * Create/Edit form; from then on it's part of everyone's suggestion list
     * because it's already sitting in the tasks table.
     */
    public static function defaultCategories(): array
    {
        return [
            'General', 'Maintenance', 'Installation', 'Repair', 'Procurement',
            'Inspection', 'Documentation', 'Meeting', 'Follow-up', 'Deadline',
            'Security', 'Cleaning', 'Training', 'Compliance', 'Site Visit',
            'Equipment', 'Logistics', 'Client Request', 'Emergency', 'Reporting',
        ];
    }

    protected function availableCategories(): array
    {
        $used = Task::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();

        return collect(self::defaultCategories())->merge($used)->unique()->values()->all();
    }

    /**
     * Users the "Created By" filter offers — whoever currently holds both
     * create-task-manager and assign-task-manager (directly or via role),
     * i.e. the people who can actually be a task's creator/manager. Checked
     * in PHP via the normal permission-resolution path (roles + direct
     * grants) rather than hand-rolling the equivalent SQL joins, since this
     * runs once per Team Manager page load against a modest user count.
     */
    protected function taskCreators(): array
    {
        return User::ofAccount()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->filter(fn ($u) => $u->can('create-task-manager') && $u->can('assign-task-manager'))
            ->values()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }

    /**
     * The "Team" panel's roster — whoever currently has at least one
     * (non-deleted) task assigned, with their open-task count. Deliberately
     * not a real Team model (no membership to manage) — just a live view of
     * existing assignment data, per the chosen scope for this pass.
     */
    protected function teamRoster(): array
    {
        $rows = TaskAssignee::query()
            ->where('assignee_type', self::ASSIGNEE_TYPE)
            ->join('tasks', 'tasks.id', '=', 'task_assignees.task_id')
            ->whereNull('tasks.deleted_at')
            ->where('tasks.status', '!=', 'done')
            ->selectRaw('task_assignees.assignee_id, count(*) as task_count')
            ->groupBy('task_assignees.assignee_id')
            ->orderByDesc('task_count')
            ->limit(12)
            ->get();

        $userIds = $rows->pluck('assignee_id');
        $users = User::whereIn('id', $userIds)->get(['id', 'name', 'profile_photo_path'])->keyBy('id');

        // Eager-loaded once for every roster member's tooltip, rather than a
        // query per member — small dataset (this pass's task volume), fine
        // to filter in memory.
        $tasksByAssignee = Task::query()
            ->whereHas('assignees', fn ($q) => $q->where('assignee_type', self::ASSIGNEE_TYPE)->whereIn('assignee_id', $userIds))
            ->with(['assignees' => fn ($q) => $q->where('assignee_type', self::ASSIGNEE_TYPE)->whereIn('assignee_id', $userIds)])
            ->get(['id', 'title', 'status'])
            ->flatMap(fn ($task) => $task->assignees->map(fn ($a) => ['assignee_id' => $a->assignee_id, 'task' => $task]))
            ->groupBy('assignee_id');

        return $rows->map(function ($row) use ($users, $tasksByAssignee) {
            $user = $users->get($row->assignee_id);
            if (! $user) {
                return null;
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'has_photo' => (bool) $user->profile_photo_path,
                'profile_photo_url' => $user->profile_photo_url,
                'task_count' => $row->task_count,
                'tasks' => ($tasksByAssignee->get($row->assignee_id) ?? collect())
                    ->pluck('task')
                    ->reject(fn ($t) => $t->status === 'done')
                    ->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'status' => $t->status])
                    ->values(),
            ];
        })->filter()->values()->all();
    }

    protected function recentActivity(): array
    {
        $activities = Activity::where('log_name', 'Task Manager')
            ->with('causer:id,name')
            ->latest()
            ->limit(15)
            ->get(['id', 'description', 'causer_id', 'causer_type', 'created_at', 'subject_id', 'properties']);

        $tasks = Task::with('assignees.assignee')
            ->whereIn('id', $activities->pluck('subject_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return $activities
            ->map(function ($a) use ($tasks) {
                $task = $tasks->get($a->subject_id);

                return [
                    'description' => $a->description,
                    'causer' => $a->causer?->name ?? __('System'),
                    // See HasActivityHistory::activityHistory() — same
                    // dual-identity surfacing for impersonated actions.
                    'actingAs' => $a->properties['impersonation']['effective_user_name'] ?? null,
                    'created_at' => $a->created_at?->toDateTimeString(),
                    'task_id' => $task?->id,
                    'task_status' => $task?->status,
                    'assignees' => $task
                        ? $task->assignees->map(fn ($ta) => $ta->assignee?->name)->filter()->values()
                        : [],
                ];
            })
            ->all();
    }
}
