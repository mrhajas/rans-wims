<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkPackage;
use App\Models\WorkPackageIssue;
use App\Http\Requests\WorkPackageIssueStoreRequest;
use App\Http\Requests\WorkPackageIssueUpdateRequest;
use App\Http\Resources\WorkPackageIssueCollection;
use App\Http\Resources\WorkPackageIssueResource;
use App\Traits\LogsPemAudit;
use App\Traits\GuardsCompletedWarehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WorkPackageIssueController extends Controller
{
    use LogsPemAudit;
    use GuardsCompletedWarehouse;

    public function index(Request $request)
    {
        $this->authorize('viewAny', WorkPackageIssue::class);

        $filters = $request->only('search', 'status', 'priority', 'assigned_user_id');
        $user = $request->user();
        $isPlanner = $user->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers']);

        $scope = fn ($q) => $q
            ->when(! $isPlanner, fn ($q2) => $q2->whereHas('workPackage', fn ($w) => $w->where('engineer_id', $user->id)))
            ->when(warehouse_scope_ids() !== null, fn ($q2) => $q2->whereHas('workPackage', fn ($w) => $w->whereIn('warehouse_id', warehouse_scope_ids())));

        $issues = WorkPackageIssue::with(['workPackage:id,reference,title,warehouse_id,engineer_id', 'workPackage.warehouse:id,name', 'assignee:id,name', 'creator:id,name'])
            ->filter($filters)
            ->tap($scope)
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // Tile numbers follow the same visibility scope as the list (ignoring search/filters).
        $statsRow = WorkPackageIssue::query()
            ->tap($scope)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as open_count', [WorkPackageIssue::STATUS_OPEN])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as in_progress', [WorkPackageIssue::STATUS_IN_PROGRESS])
            ->selectRaw('COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) as done', [WorkPackageIssue::STATUS_RESOLVED, WorkPackageIssue::STATUS_CLOSED])
            ->selectRaw('COALESCE(SUM(CASE WHEN priority = ? AND status IN (?, ?) THEN 1 ELSE 0 END), 0) as critical', [WorkPackageIssue::PRIORITY_CRITICAL, WorkPackageIssue::STATUS_OPEN, WorkPackageIssue::STATUS_IN_PROGRESS])
            ->first();

        return Inertia::render('ProjectExecution/Issues/Index', [
            'stats' => [
                'total' => (int) $statsRow->total,
                'open' => (int) $statsRow->open_count,
                'in_progress' => (int) $statsRow->in_progress,
                'done' => (int) $statsRow->done,
                'critical' => (int) $statsRow->critical,
            ],
            'issues' => new WorkPackageIssueCollection($issues),
            'filters' => $filters,
            'statuses' => WorkPackageIssue::statuses(),
            'priorities' => WorkPackageIssue::priorities(),
            'isPlanner' => $isPlanner,
        ]);
    }

    public function store(WorkPackageIssueStoreRequest $request, WorkPackage $work_package)
    {
        if ($response = $this->rejectIfWarehouseCompleted($work_package->warehouse_id)) {
            return $response;
        }
        $data = $request->validated();
        unset($data['photos'], $data['attachments']);

        $data['work_package_id'] = $work_package->id;
        $data['reference'] = $this->nextReference();
        $data['created_by'] = $request->user()->id;
        $data['status'] = WorkPackageIssue::STATUS_OPEN;

        $issue = WorkPackageIssue::create($data);

        $issue->saveAttachments($request->file('photos'), 'photos');
        $issue->saveAttachments($request->file('attachments'), 'attachments');

        $this->logPemAudit($work_package, 'Issue Created', null, [
            'reference' => $issue->reference,
            'title' => $issue->title,
            'priority' => $issue->priority,
        ]);

        \App\Services\ProjectExecutionNotifier::issue('issue_reported', $issue, __('Issue Reported'), "Issue {$issue->reference} was reported: {$issue->title}", null);

        if ($issue->assigned_user_id) {
            $this->notifyAssignee($issue);
        }

        return back()->with('message', __('Issue reported.'));
    }

    public function update(WorkPackageIssueUpdateRequest $request, WorkPackage $work_package, WorkPackageIssue $issue)
    {
        if ($response = $this->rejectIfWarehouseCompleted($work_package->warehouse_id)) {
            return $response;
        }
        $data = $request->validated();
        $previous = $issue->only(['status', 'priority', 'assigned_user_id', 'deadline']);
        $previousAssignee = $issue->assigned_user_id;

        if (($data['status'] ?? null) === WorkPackageIssue::STATUS_RESOLVED && $issue->status !== WorkPackageIssue::STATUS_RESOLVED) {
            $data['resolved_at'] = now();
            $data['resolved_by'] = $request->user()->id;
        }

        $issue->update($data);

        $this->logPemAudit($work_package, 'Issue Updated', $previous, $issue->only(['status', 'priority', 'assigned_user_id', 'deadline']));

        if ($issue->status === WorkPackageIssue::STATUS_RESOLVED && $previous['status'] !== WorkPackageIssue::STATUS_RESOLVED) {
            $this->logPemAudit($work_package, 'Issue Resolved', null, ['resolution' => $issue->resolution], "Resolved by {$request->user()->name}.");
        }

        if ($issue->status === WorkPackageIssue::STATUS_RESOLVED && $previous['status'] !== WorkPackageIssue::STATUS_RESOLVED) {
            \App\Services\ProjectExecutionNotifier::issue('issue_resolved', $issue, __('Issue Resolved'), "Issue {$issue->reference} was resolved: {$issue->title}", (int) $issue->created_by, $issue->resolution);
        }

        if ($issue->assigned_user_id && $issue->assigned_user_id !== $previousAssignee) {
            $this->notifyAssignee($issue);
        }

        return back()->with('message', __('Issue updated.'));
    }

    public function destroy(WorkPackage $work_package, WorkPackageIssue $issue)
    {
        $this->authorize('delete', WorkPackageIssue::class);
        if ($response = $this->rejectIfWarehouseCompleted($work_package->warehouse_id)) {
            return $response;
        }

        $this->logPemAudit($work_package, 'Issue Deleted', ['reference' => $issue->reference], null);

        $issue->delete();

        return back()->with('message', __('Issue deleted.'));
    }

    /** Project Execution workflow page: the assignee is told that an issue is theirs (switch off or add people there). */
    protected function notifyAssignee(WorkPackageIssue $issue): void
    {
        if (! $issue->assigned_user_id) {
            return;
        }

        \App\Services\ProjectExecutionNotifier::issue(
            'issue_assigned',
            $issue,
            'Issue Assigned',
            "You have been assigned issue {$issue->reference}: {$issue->title}",
            (int) $issue->assigned_user_id
        );
    }

    protected function nextReference(): string
    {
        $maxSuffix = (int) DB::table('work_package_issues')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', 'RWI-%')
            ->value('max_suffix');

        return 'RWI-' . str_pad($maxSuffix + 1, 4, '0', STR_PAD_LEFT);
    }
}
