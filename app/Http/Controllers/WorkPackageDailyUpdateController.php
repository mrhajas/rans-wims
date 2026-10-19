<?php

namespace App\Http\Controllers;

use App\Models\WorkPackage;
use App\Models\WorkPackageDailyUpdate;
use App\Http\Requests\WorkPackageDailyUpdateStoreRequest;
use App\Http\Resources\WorkPackageDailyUpdateCollection;
use App\Traits\LogsPemAudit;
use App\Traits\GuardsCompletedWarehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkPackageDailyUpdateController extends Controller
{
    use LogsPemAudit;
    use GuardsCompletedWarehouse;

    public function index(Request $request)
    {
        $this->authorize('viewAny', WorkPackage::class);

        $user = $request->user();
        $isPlanner = $user->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers']);
        $status = $request->input('status', $isPlanner ? WorkPackageDailyUpdate::STATUS_PENDING : null);

        $updates = WorkPackageDailyUpdate::with(['workPackage:id,reference,title,warehouse_id', 'workPackage.warehouse:id,name', 'submitter:id,name', 'approver:id,name'])
            ->filter(['status' => $status])
            ->when(! $isPlanner, fn ($q) => $q->where('submitted_by', $user->id))
            ->when(warehouse_scope_ids() !== null, fn ($q) => $q->whereHas('workPackage', fn ($w) => $w->whereIn('warehouse_id', warehouse_scope_ids())))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // Tile numbers follow the same visibility scope as the list (ignoring the status tab).
        $statsRow = WorkPackageDailyUpdate::query()
            ->when(! $isPlanner, fn ($q) => $q->where('submitted_by', $user->id))
            ->when(warehouse_scope_ids() !== null, fn ($q) => $q->whereHas('workPackage', fn ($w) => $w->whereIn('warehouse_id', warehouse_scope_ids())))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as pending', [WorkPackageDailyUpdate::STATUS_PENDING])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as approved', [WorkPackageDailyUpdate::STATUS_APPROVED])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as rejected', [WorkPackageDailyUpdate::STATUS_REJECTED])
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as today', [now()->startOfDay()])
            ->first();

        return Inertia::render('ProjectExecution/DailyUpdates/Index', [
            'stats' => [
                'total' => (int) $statsRow->total,
                'pending' => (int) $statsRow->pending,
                'approved' => (int) $statsRow->approved,
                'rejected' => (int) $statsRow->rejected,
                'today' => (int) $statsRow->today,
            ],
            'updates' => new WorkPackageDailyUpdateCollection($updates),
            'filters' => ['status' => $status],
            'isPlanner' => $isPlanner,
            'pendingCount' => $isPlanner
                ? WorkPackageDailyUpdate::where('status', WorkPackageDailyUpdate::STATUS_PENDING)
                    ->when(warehouse_scope_ids() !== null, fn ($q) => $q->whereHas('workPackage', fn ($w) => $w->whereIn('warehouse_id', warehouse_scope_ids())))
                    ->count()
                : 0,
        ]);
    }

    public function store(WorkPackageDailyUpdateStoreRequest $request, WorkPackage $work_package)
    {
        if ($response = $this->rejectIfWarehouseCompleted($work_package->warehouse_id)) {
            return $response;
        }
        $data = $request->validated();
        unset($data['photos'], $data['videos'], $data['documents']);

        $data['work_package_id'] = $work_package->id;
        $data['submitted_by'] = $request->user()->id;
        $data['status'] = WorkPackageDailyUpdate::STATUS_PENDING;

        $update = WorkPackageDailyUpdate::create($data);

        $update->saveAttachments($request->file('photos'), 'photos');
        $update->saveAttachments($request->file('videos'), 'videos');
        $update->saveAttachments($request->file('documents'), 'documents');

        $this->logPemAudit($work_package, 'Daily Update Submitted', null, [
            'progress_percent' => $update->progress_percent,
        ], "Submitted by {$request->user()->name}.");
        \App\Services\ProjectExecutionNotifier::dailyUpdate('daily_update_submitted', $update, __('Daily Update Submitted'), "A daily update for {$work_package->reference} was sent for approval ({$update->progress_percent}%).");

        return back()->with('message', __('Daily update submitted for approval.'));
    }

    public function approve(Request $request, WorkPackageDailyUpdate $daily_update)
    {
        $this->authorize('approveUpdate', WorkPackage::class);
        if ($response = $this->rejectIfWarehouseCompleted($daily_update->workPackage->warehouse_id)) {
            return $response;
        }

        if ($daily_update->status !== WorkPackageDailyUpdate::STATUS_PENDING) {
            return back()->withErrors(['status' => __('This update has already been reviewed.')]);
        }

        $workPackage = $daily_update->workPackage;
        $previousProgress = $workPackage->actual_progress_percent;

        $daily_update->update([
            'status' => WorkPackageDailyUpdate::STATUS_APPROVED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        $workPackage->update(['actual_progress_percent' => $daily_update->progress_percent]);

        $this->logPemAudit($workPackage, 'Daily Update Approved', null, null, "Approved by {$request->user()->name}.");
        $this->logPemAudit($workPackage, 'Progress Updated', ['actual_progress_percent' => $previousProgress], ['actual_progress_percent' => $daily_update->progress_percent]);
        \App\Services\ProjectExecutionNotifier::dailyUpdate('daily_update_approved', $daily_update, __('Daily Update Approved'), "Your daily update for {$workPackage->reference} was approved ({$daily_update->progress_percent}%).");

        return back()->with('message', __('Daily update approved.'));
    }

    public function reject(Request $request, WorkPackageDailyUpdate $daily_update)
    {
        $this->authorize('approveUpdate', WorkPackage::class);
        if ($response = $this->rejectIfWarehouseCompleted($daily_update->workPackage->warehouse_id)) {
            return $response;
        }

        if ($daily_update->status !== WorkPackageDailyUpdate::STATUS_PENDING) {
            return back()->withErrors(['status' => __('This update has already been reviewed.')]);
        }

        $data = $request->validate(['approval_remarks' => 'required|string']);

        $daily_update->update([
            'status' => WorkPackageDailyUpdate::STATUS_REJECTED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'approval_remarks' => $data['approval_remarks'],
        ]);

        $this->logPemAudit($daily_update->workPackage, 'Daily Update Rejected', null, null, $data['approval_remarks']);
        \App\Services\ProjectExecutionNotifier::dailyUpdate('daily_update_rejected', $daily_update, __('Daily Update Rejected'), "Your daily update for {$daily_update->workPackage->reference} was rejected.", $data['approval_remarks']);

        return back()->with('message', __('Daily update rejected.'));
    }
}
