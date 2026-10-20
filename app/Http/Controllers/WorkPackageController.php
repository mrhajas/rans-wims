<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkPackage;
use App\Models\WorkPackageIssue;
use App\Http\Requests\WorkPackageStoreRequest;
use App\Http\Requests\WorkPackageUpdateRequest;
use App\Http\Resources\WorkPackageCollection;
use App\Http\Resources\WorkPackageDailyUpdateResource;
use App\Http\Resources\WorkPackageEquipmentForecastResource;
use App\Http\Resources\WorkPackageIssueResource;
use App\Http\Resources\WorkPackageLabourForecastResource;
use App\Http\Resources\WorkPackageMaterialForecastResource;
use App\Http\Resources\WorkPackageResource;
use App\Traits\LogsPemAudit;
use App\Traits\GuardsCompletedWarehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class WorkPackageController extends Controller
{
    use LogsPemAudit;
    use GuardsCompletedWarehouse;

    public function index(Request $request)
    {
        $this->authorize('viewAny', WorkPackage::class);

        $filters = $request->only('search', 'warehouse_id', 'status', 'priority');
        $user = $request->user();

        $query = WorkPackage::with(['warehouse:id,name,code', 'engineer:id,name', 'foreman:id,name'])
            ->filter($filters)
            ->when(warehouse_scope_ids() !== null, fn ($q) => $q->whereIn('warehouse_id', warehouse_scope_ids()))
            ->when($user->hasRole(User::SITE_ENGINEER_ROLES), fn ($q) => $q->where('engineer_id', $user->id))
            ->orderByDesc('id');

        // Tile numbers follow the same visibility scope as the list (ignoring search/filters).
        $statsRow = WorkPackage::query()
            ->when(warehouse_scope_ids() !== null, fn ($q) => $q->whereIn('warehouse_id', warehouse_scope_ids()))
            ->when($user->hasRole(User::SITE_ENGINEER_ROLES), fn ($q) => $q->where('engineer_id', $user->id))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as not_started', [WorkPackage::STATUS_NOT_STARTED])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as in_progress', [WorkPackage::STATUS_IN_PROGRESS])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as on_hold', [WorkPackage::STATUS_ON_HOLD])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as completed', [WorkPackage::STATUS_COMPLETED])
            ->first();

        return Inertia::render('ProjectExecution/WorkPackages/Index', [
            'stats' => [
                'total' => (int) $statsRow->total,
                'not_started' => (int) $statsRow->not_started,
                'in_progress' => (int) $statsRow->in_progress,
                'on_hold' => (int) $statsRow->on_hold,
                'completed' => (int) $statsRow->completed,
            ],
            'filters' => $filters,
            'workPackages' => new WorkPackageCollection($query->paginate(20)->withQueryString()),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'code', 'status', 'completed_at']),
            'statuses' => WorkPackage::statuses(),
            'priorities' => WorkPackage::priorities(),
            'canCreate' => $user->can('create', WorkPackage::class),
        ]);
    }

    public function create()
    {
        $this->authorize('create', WorkPackage::class);

        return Inertia::render('ProjectExecution/WorkPackages/Form', $this->formProps());
    }

    public function store(WorkPackageStoreRequest $request)
    {
        $validated = $request->validated();
        $warehouseId = $validated['warehouse_id'];
        if ($response = $this->rejectIfWarehouseCompleted($warehouseId)) {
            return $response;
        }
        $nextSuffix = $this->nextReferenceSuffix();
        $createdBy = $request->user()->id;

        $workPackages = collect($validated['work_packages'])->map(function (array $row) use (&$nextSuffix, $warehouseId, $createdBy) {
            $row['warehouse_id'] = $warehouseId;
            $row['created_by'] = $createdBy;
            $row['reference'] = 'RWP-' . str_pad($nextSuffix, 4, '0', STR_PAD_LEFT);
            $row['planned_duration'] = $row['planned_duration']
                ?? \Carbon\Carbon::parse($row['planned_start'])->diffInDays(\Carbon\Carbon::parse($row['planned_finish']));
            $row['expected_progress_weight'] = $row['expected_progress_weight'] ?? 1;
            $nextSuffix++;

            $workPackage = WorkPackage::create($row);

            $this->logPemAudit($workPackage, 'Work Package Created', null, $workPackage->only([
                'title', 'warehouse_id', 'planned_start', 'planned_finish', 'status',
            ]));

            return $workPackage;
        });

        if ($workPackages->count() === 1) {
            return redirect()->route('work-packages.show', $workPackages->first())
                ->with('message', __('Work Package created.'));
        }

        return redirect()->route('work-packages.index')
            ->with('message', __(':count Work Packages created.', ['count' => $workPackages->count()]));
    }

    public function show(Request $request, WorkPackage $work_package)
    {
        $this->authorize('view', $work_package);

        $work_package->load([
            'warehouse:id,name,code',
            'engineer:id,name',
            'foreman:id,name',
            'subcontractor:id,name',
            'creator:id,name',
            'attachments',
            'auditLogs.user:id,name',
            'dailyUpdates.submitter:id,name',
            'dailyUpdates.approver:id,name',
            'dailyUpdates.attachments',
            'materialForecasts.item.unit',
            'materialForecasts.creator:id,name',
            'labourForecasts.contact',
            'labourForecasts.creator:id,name',
            'equipmentForecasts.item',
            'equipmentForecasts.creator:id,name',
            'issues.assignee:id,name',
            'issues.creator:id,name',
            'issues.attachments',
        ]);

        $user = $request->user();

        // Preview/download/delete URLs built server-side (same shape as
        // IssueMaterialController::show()/AssetController::presentDetail())
        // so the Show page can offer View/Download/Remove per file without
        // constructing routes itself. Attachment::filetype always stores the
        // full MIME type (e.g. "image/png"), never a bare extension, so this
        // checks the MIME prefix rather than matching against extension
        // strings the column never actually contains.
        $attachments = $work_package->attachments->map(fn (Attachment $a) => [
            'id' => $a->id,
            'title' => $a->title,
            'filesize' => $a->filesize,
            'is_image' => str_starts_with(strtolower($a->filetype ?? ''), 'image/'),
            'preview_url' => route('work-packages.attachments.download', [$work_package->id, $a->id]) . '?disposition=inline',
            'download_url' => route('work-packages.attachments.download', [$work_package->id, $a->id]),
            'delete_url' => route('work-packages.attachments.destroy', [$work_package->id, $a->id]),
        ])->values();

        return Inertia::render('ProjectExecution/WorkPackages/Show', [
            'workPackage' => new WorkPackageResource($work_package),
            'attachments' => $attachments,
            'canManageAttachments' => $user->can('manageAttachments', $work_package),
            'dailyUpdates' => WorkPackageDailyUpdateResource::collection($work_package->dailyUpdates),
            'canSubmitUpdate' => $user->can('submitUpdate', $work_package),
            'canManageForecast' => $user->can('manageForecast', $work_package),
            'materialForecasts' => WorkPackageMaterialForecastResource::collection($work_package->materialForecasts),
            'labourForecasts' => WorkPackageLabourForecastResource::collection($work_package->labourForecasts),
            'equipmentForecasts' => WorkPackageEquipmentForecastResource::collection($work_package->equipmentForecasts),
            'issues' => WorkPackageIssueResource::collection($work_package->issues),
            'issuePriorities' => WorkPackageIssue::priorities(),
            'issueStatuses' => WorkPackageIssue::statuses(),
            'assignableUsers' => User::whereHas('roles', fn ($q) => $q->whereIn('name', [
                'Super Admin', 'Admin', 'Project Manager', 'Project Managers', ...User::SITE_ENGINEER_ROLES,
            ]))->orderBy('name')->get(['id', 'name']),
            'items' => Item::ofAccount()->orderBy('name')->get(['id', 'name', 'code', 'unit_id']),
            'contacts' => Contact::ofAccount()->orderBy('name')->get(['id', 'name', 'category']),
            'timeline' => $work_package->auditLogs->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user?->name,
                'role' => $log->role,
                'remarks' => $log->remarks,
                'previous_value' => $log->previous_value,
                'new_value' => $log->new_value,
                'created_at' => $log->created_at,
            ]),
        ]);
    }

    public function edit(WorkPackage $work_package)
    {
        $this->authorize('update', $work_package);
        if ($response = $this->rejectIfWarehouseCompleted($work_package->warehouse_id)) {
            return $response;
        }

        return Inertia::render('ProjectExecution/WorkPackages/Form', array_merge(
            $this->formProps(),
            ['edit' => new WorkPackageResource($work_package)]
        ));
    }

    public function update(WorkPackageUpdateRequest $request, WorkPackage $work_package)
    {
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseCompleted($work_package->warehouse_id, $data['warehouse_id'] ?? null)) {
            return $response;
        }
        $previous = $work_package->only([
            'title', 'warehouse_id', 'planned_start', 'planned_finish', 'planned_duration',
            'status', 'actual_progress_percent', 'engineer_id', 'foreman_id', 'subcontractor_id',
        ]);

        $work_package->update($data);

        $this->logPemAudit(
            $work_package,
            'Work Package Updated',
            $previous,
            $work_package->only(array_keys($previous))
        );

        return redirect()->route('work-packages.show', $work_package)
            ->with('message', __('Work Package updated.'));
    }

    public function destroy(WorkPackage $work_package)
    {
        $this->authorize('delete', $work_package);
        if ($response = $this->rejectIfWarehouseCompleted($work_package->warehouse_id)) {
            return $response;
        }

        $this->logPemAudit($work_package, 'Work Package Deleted');
        $work_package->delete();

        return redirect()->route('work-packages.index')->with('message', __('Work Package deleted.'));
    }

    public function restore(WorkPackage $work_package)
    {
        abort_unless(auth()->user()->can('delete-work-packages'), 403);

        $work_package->restore();
        $this->logPemAudit($work_package, 'Work Package Restored');

        return back()->with('message', __('Work Package restored.'));
    }

    public function destroyPermanently(WorkPackage $work_package)
    {
        $this->authorize('delete', $work_package);

        $work_package->forceDelete();

        return redirect()->route('work-packages.index')->with('message', __('Work Package permanently deleted.'));
    }

    public function storeAttachments(Request $request, WorkPackage $work_package)
    {
        $this->authorize('manageAttachments', $work_package);

        $data = $request->validate([
            'attachments' => 'required|array',
            'attachments.*' => 'file|max:20480|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,dwg,zip',
        ]);

        $work_package->saveAttachments($request->file('attachments'));

        return back()->with('message', __('Attachment(s) uploaded.'));
    }

    public function downloadAttachment(Request $request, WorkPackage $work_package, Attachment $attachment)
    {
        $this->authorize('view', $work_package);

        abort_unless((int) $attachment->model_id === (int) $work_package->id && $attachment->model_type === WorkPackage::class, 404);

        // ?disposition=inline lets the browser render the file directly
        // (used by the Show page's preview UI) instead of forcing a
        // download dialog — same convention as IssueMaterialController.
        if ($request->query('disposition') === 'inline') {
            return Storage::disk($attachment->disk)->response($attachment->filepath, $attachment->title);
        }

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title);
    }

    public function destroyAttachment(WorkPackage $work_package, Attachment $attachment)
    {
        $this->authorize('manageAttachments', $work_package);

        abort_unless((int) $attachment->model_id === (int) $work_package->id && $attachment->model_type === WorkPackage::class, 404);

        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        return back()->with('message', __('Attachment removed.'));
    }

    protected function formProps(): array
    {
        return [
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'code', 'status', 'completed_at']),
            'engineers' => User::whereHas('roles', fn ($q) => $q->whereIn('name', User::SITE_ENGINEER_ROLES))
                ->orderBy('name')->get(['id', 'name']),
            'foremen' => Contact::where('category', 'Foreman')->orderBy('name')->get(['id', 'name']),
            'subcontractors' => Contact::where('category', 'SubContractors')->orderBy('name')->get(['id', 'name']),
            'priorities' => WorkPackage::priorities(),
            'statuses' => WorkPackage::statuses(),
        ];
    }

    protected function nextReferenceSuffix(): int
    {
        $maxSuffix = (int) DB::table('work_packages')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', 'RWP-%')
            ->value('max_suffix');

        return $maxSuffix + 1;
    }
}
