<?php

namespace App\Http\Controllers;

use App\Http\Requests\DailySiteReportStoreRequest;
use App\Http\Requests\DailySiteReportUpdateRequest;
use App\Http\Resources\DailySiteReportResource;
use App\Models\DailySiteReport;
use App\Models\User;
use App\Models\Warehouse;
use App\Mail\MainWarehouseActivityMail;
use App\Notifications\AppNotification;
use App\Services\DailySiteReportArchiveService;
use App\Services\MainWarehouseEmailService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;
use Tecdiary\Laravel\Attachments\Attachment;
use App\Traits\GuardsCompletedWarehouse;

class DailySiteReportController extends Controller
{
    use GuardsCompletedWarehouse;

    public function index(Request $request)
    {
        $user = $request->user();

        $filters = $request->all('search', 'warehouse_id', 'submitted_by', 'report_type', 'status', 'start_date', 'end_date', 'sort', 'sort_direction');

        $baseQuery = DailySiteReport::query()->visibleTo($user);

        $reports = (clone $baseQuery)
            ->with(['warehouse:id,name', 'submittedBy:id,name', 'viewedBy:id,name', 'attachments'])
            ->filter($filters)
            ->orderByDesc('daily_site_reports.id')
            ->paginate()
            ->withQueryString();

        return Inertia::render('DailySiteReport/Index', [
            'filters' => $filters,
            'statuses' => DailySiteReport::statuses(),
            'reportTypes' => DailySiteReport::reportTypes(),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status', 'completed_at']),
            'siteEngineers' => User::whereHas('roles', fn ($q) => $q->whereIn('name', User::SITE_ENGINEER_ROLES))->orderBy('name')->get(['id', 'name']),
            'dailySiteReports' => DailySiteReportResource::collection($reports),
            'stats' => $this->buildStats($baseQuery),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', DailySiteReport::class);

        $user = $request->user();
        $isSiteEngineer = $user->hasRole(User::SITE_ENGINEER_ROLES) && ! $user->hasRole(['Super Admin', 'Admin']);

        $warehouses = $isSiteEngineer
            ? $user->warehouses()->where('active', 1)->visible()->orderBy('name')->get(['warehouses.id', 'warehouses.name', 'warehouses.status', 'warehouses.completed_at'])
            : Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status', 'completed_at']);

        return Inertia::render('DailySiteReport/Form', [
            'edit' => null,
            'reportTypes' => DailySiteReport::reportTypes(),
            'priorities' => DailySiteReport::priorities(),
            'warehouses' => $warehouses,
            'siteEngineers' => $isSiteEngineer ? [] : User::whereHas('roles', fn ($q) => $q->whereIn('name', User::SITE_ENGINEER_ROLES))->orderBy('name')->get(['id', 'name']),
            'isSiteEngineer' => $isSiteEngineer,
            'reportNumber' => $this->nextReportNumber(),
            'submittedBy' => $user->only(['id', 'name']),
            'reportDate' => now()->format('Y-m-d'),
            'minReportDate' => now()->subDays(3)->format('Y-m-d'),
            'maxAttachments' => config('daily_site_reports.max_attachments'),
            'maxAttachmentSizeKb' => config('daily_site_reports.max_attachment_size_kb'),
        ]);
    }

    public function store(DailySiteReportStoreRequest $request)
    {
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseCompleted($data['warehouse_id'] ?? null)) {
            return $response;
        }
        $user = $request->user();
        $isSiteEngineer = $user->hasRole(User::SITE_ENGINEER_ROLES) && ! $user->hasRole(['Super Admin', 'Admin']);

        $data['submitted_by'] = $isSiteEngineer ? $user->id : $data['submitted_by'];
        $data['status'] = DailySiteReport::STATUS_SUBMITTED;

        $report = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $data['report_number'] = $this->nextReportNumber();

            try {
                $report = new DailySiteReport();
                $report->fill($data)->save();
                break;
            } catch (QueryException $e) {
                $isDuplicateNumber = (string) $e->getCode() === '23000'
                    && str_contains($e->getMessage(), 'daily_site_reports_report_number_unique');

                if (! $isDuplicateNumber || $attempt === 2) {
                    throw $e;
                }
            }
        }

        if (! $report) {
            abort(500, 'Unable to generate a unique report number.');
        }

        $report->saveAttachments($request->file('attachments'));

        $this->writeLog($report, 'created', 'Report submitted.');

        $this->notifyAdminsAndPmsOfSubmission($report);
        $this->notifyWarehouseEmailSubscribersOfSubmission($report);

        return redirect()->route('daily-site-reports.show', $report->id)
            ->with('message', __('Site Report submitted successfully.'));
    }

    public function show(Request $request, DailySiteReport $daily_site_report)
    {
        $this->authorize('view', $daily_site_report);

        $user = $request->user();
        $this->trackView($daily_site_report, $user);

        $daily_site_report->load([
            'warehouse:id,name,code',
            'submittedBy:id,name,username',
            'viewedBy:id,name',
            'downloadedBy:id,name',
            'archivedBy:id,name',
            'logs.user:id,name',
            'attachments',
        ]);

        return Inertia::render('DailySiteReport/Show', [
            'dailySiteReport' => new DailySiteReportResource($daily_site_report),
        ]);
    }

    public function edit(Request $request, DailySiteReport $daily_site_report)
    {
        $this->authorize('update', $daily_site_report);
        if ($response = $this->rejectIfWarehouseCompleted($daily_site_report->warehouse_id)) {
            return $response;
        }

        $daily_site_report->load('attachments');
        $user = $request->user();

        $warehouses = $user->warehouses()->where('active', 1)->visible()->orderBy('name')->get(['warehouses.id', 'warehouses.name', 'warehouses.status', 'warehouses.completed_at']);

        if ($daily_site_report->warehouse_id && ! $warehouses->contains('id', $daily_site_report->warehouse_id)) {
            $selectedWarehouse = Warehouse::ofAccount()->whereKey($daily_site_report->warehouse_id)->get(['id', 'name', 'status', 'completed_at']);
            $warehouses = $warehouses->concat($selectedWarehouse)->unique('id')->sortBy('name')->values();
        }

        return Inertia::render('DailySiteReport/Form', [
            'edit' => new DailySiteReportResource($daily_site_report),
            'reportTypes' => DailySiteReport::reportTypes(),
            'priorities' => DailySiteReport::priorities(),
            'warehouses' => $warehouses,
            'siteEngineers' => [],
            'isSiteEngineer' => true,
            'reportNumber' => $daily_site_report->report_number,
            'submittedBy' => $user->only(['id', 'name']),
            'reportDate' => $daily_site_report->report_date?->format('Y-m-d'),
            'minReportDate' => now()->subDays(3)->format('Y-m-d'),
            'maxAttachments' => config('daily_site_reports.max_attachments'),
            'maxAttachmentSizeKb' => config('daily_site_reports.max_attachment_size_kb'),
        ]);
    }

    public function update(DailySiteReportUpdateRequest $request, DailySiteReport $daily_site_report)
    {
        $this->authorize('update', $daily_site_report);
        $data = $request->validated();
        if ($response = $this->rejectIfWarehouseCompleted($daily_site_report->warehouse_id, $data['warehouse_id'] ?? null)) {
            return $response;
        }

        $daily_site_report->update([
            'warehouse_id' => $data['warehouse_id'],
            'report_date' => $data['report_date'],
            'report_type' => $data['report_type'],
            'priority' => $data['priority'],
            'remarks' => $data['remarks'] ?? null,
        ]);

        $daily_site_report->saveAttachments($request->file('attachments'));

        $this->writeLog($daily_site_report, 'updated', 'Report updated by ' . $request->user()->name . '.');
        $this->alertSiteReport('updated', $daily_site_report, __('Site Report Edited'), $daily_site_report->report_number . ' was edited by ' . $request->user()->name . '.', ['skip_actor' => true]);

        return redirect()->route('daily-site-reports.show', $daily_site_report->id)
            ->with('message', __('Site Report updated successfully.'));
    }

    public function destroy(Request $request, DailySiteReport $daily_site_report)
    {
        $this->authorize('delete', $daily_site_report);
        if ($response = $this->rejectIfWarehouseCompleted($daily_site_report->warehouse_id)) {
            return $response;
        }

        $this->writeLog($daily_site_report, 'deleted', 'Report deleted by ' . $request->user()->name . '.');

        $daily_site_report->delete();
        $this->alertSiteReport('deleted', $daily_site_report, __('Site Report Deleted'), $daily_site_report->report_number . ' was deleted by ' . $request->user()->name . '.', ['skip_actor' => true, 'url' => route('daily-site-reports.index')]);

        return redirect()->route('daily-site-reports.index')
            ->with('message', __('Site Report deleted successfully.'));
    }

    public function archive(Request $request, DailySiteReport $daily_site_report)
    {
        $this->authorize('archive', $daily_site_report);
        if ($response = $this->rejectIfWarehouseCompleted($daily_site_report->warehouse_id)) {
            return $response;
        }

        DailySiteReportArchiveService::archive($daily_site_report, $request->user(), false);

        return back()->with('message', __('Site Report archived successfully.'));
    }

    public function downloadAttachment(Request $request, DailySiteReport $daily_site_report, Attachment $attachment)
    {
        $this->authorize('download', $daily_site_report);

        if ((int) $attachment->model_id !== (int) $daily_site_report->id || $attachment->model_type !== DailySiteReport::class) {
            abort(404);
        }

        abort_if(
            $daily_site_report->status === DailySiteReport::STATUS_ARCHIVED,
            410,
            'This report has been archived and its attachments have been removed.'
        );

        $user = $request->user();
        if ($this->qualifiesForReadLock($user, $daily_site_report)) {
            if (empty($daily_site_report->downloaded_by)) {
                $daily_site_report->update([
                    'downloaded_by' => $user->id,
                    'downloaded_at' => now(),
                    'status' => DailySiteReport::STATUS_DOWNLOADED,
                ]);
            }
        }

        $this->writeLog($daily_site_report, 'downloaded', 'Attachment downloaded: ' . $attachment->title);

        if ($request->boolean('preview')) {
            return Storage::disk($attachment->disk)->response($attachment->filepath, $attachment->title);
        }

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title);
    }

    public function print(Request $request, DailySiteReport $daily_site_report)
    {
        $this->authorize('print', $daily_site_report);

        $payload = $this->printPayload($daily_site_report);
        $this->lockOnQualifyingAccess($daily_site_report, $request->user());
        $this->writeLog($daily_site_report, 'printed', 'Report printed by ' . $request->user()->name . '.');

        return view('daily-site-report-print', $payload);
    }

    public function pdf(Request $request, DailySiteReport $daily_site_report)
    {
        $this->authorize('print', $daily_site_report);

        $payload = $this->printPayload($daily_site_report);
        $this->lockOnQualifyingAccess($daily_site_report, $request->user());
        $this->writeLog($daily_site_report, 'printed', 'Report PDF downloaded by ' . $request->user()->name . '.');

        return Pdf::loadView('daily-site-report-print', $payload)
            ->download('daily-site-report-' . $daily_site_report->report_number . '.pdf');
    }

    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers']), 403);

        $filters = $request->all('search', 'warehouse_id', 'submitted_by', 'report_type', 'status', 'start_date', 'end_date');

        $rows = DailySiteReport::query()
            ->visibleTo($user)
            ->with(['warehouse:id,name', 'submittedBy:id,name'])
            ->filter($filters)
            ->limit(10000)
            ->get();

        return (new FastExcel($rows))->download('daily-site-reports-' . now()->format('Ymd-His') . '.xlsx', function (DailySiteReport $report) {
            return [
                'Report Number' => $report->report_number,
                'Project' => optional($report->warehouse)->name,
                'Report Type' => $report->report_type,
                'Site Engineer' => optional($report->submittedBy)->name,
                'Report Date' => optional($report->report_date)->format('Y-m-d'),
                'Priority' => $report->priority,
                'Status' => $report->status,
                'Schedule Status' => $report->schedule_status,
                'Submitted At' => $report->submitted_at,
            ];
        });
    }

    public function printList(Request $request)
    {
        abort_unless($request->user()->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers']), 403);

        return view('daily-site-reports-list-print', $this->listPayload($request));
    }

    public function pdfList(Request $request)
    {
        abort_unless($request->user()->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers']), 403);

        $pdf = Pdf::loadView('daily-site-reports-list-print', $this->listPayload($request));

        return $pdf->download('site-reports-' . now()->format('Ymd-His') . '.pdf');
    }

    protected function listPayload(Request $request): array
    {
        $user = $request->user();
        $filters = $request->all('search', 'warehouse_id', 'submitted_by', 'report_type', 'status', 'start_date', 'end_date', 'sort', 'sort_direction');

        $reports = DailySiteReport::query()
            ->visibleTo($user)
            ->with(['warehouse:id,name', 'submittedBy:id,name'])
            ->filter($filters)
            ->orderByDesc('daily_site_reports.id')
            ->limit(3000)
            ->get();

        return [
            'reports' => $reports,
            'filters' => $filters,
            'logoPath' => $this->reportLogo(),
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => optional($user)->name,
        ];
    }

    protected function buildStats($baseQuery): array
    {
        $scoped = clone $baseQuery;

        return [
            'total' => (clone $scoped)->count(),
            'this_month' => (clone $scoped)->whereBetween('daily_site_reports.report_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->count(),
            // Late = submitted after the day the report is for (matches the Overdue schedule status).
            'overdue' => (clone $scoped)->whereRaw('DATE(daily_site_reports.created_at) > daily_site_reports.report_date')->count(),
            'submitted_today' => (clone $scoped)->whereDate('created_at', now()->toDateString())->count(),
            'pending_review' => (clone $scoped)->whereIn('status', [DailySiteReport::STATUS_SUBMITTED, DailySiteReport::STATUS_VIEWED])->count(),
            'downloaded' => (clone $scoped)->where('status', DailySiteReport::STATUS_DOWNLOADED)->count(),
            'archived' => (clone $scoped)->where('status', DailySiteReport::STATUS_ARCHIVED)->count(),
            'by_project' => (clone $scoped)->join('warehouses', 'warehouses.id', '=', 'daily_site_reports.warehouse_id')
                ->selectRaw('warehouses.id as warehouse_id, warehouses.name as label, count(*) as total')
                ->groupBy('warehouses.id', 'warehouses.name')
                ->orderByDesc('total')
                ->get(),
            'by_type' => (clone $scoped)->selectRaw('report_type as label, count(*) as total')
                ->groupBy('report_type')
                ->orderByDesc('total')
                ->get(),
        ];
    }

    protected function trackView(DailySiteReport $report, User $user): void
    {
        $this->writeLog($report, 'viewed', 'Report viewed by ' . $user->name . '.');
        $this->lockOnQualifyingAccess($report, $user);
    }

    /**
     * Once a report has been viewed, downloaded, or printed by Super Admin/Admin/PM,
     * the Site Engineer can no longer edit or delete it (read-protection business rule).
     */
    protected function lockOnQualifyingAccess(DailySiteReport $report, User $user): void
    {
        if (! $this->qualifiesForReadLock($user, $report) || ! empty($report->viewed_by)) {
            return;
        }

        $report->update([
            'viewed_by' => $user->id,
            'viewed_at' => now(),
            'status' => $report->status === DailySiteReport::STATUS_SUBMITTED ? DailySiteReport::STATUS_VIEWED : $report->status,
        ]);
    }

    protected function qualifiesForReadLock(User $user, DailySiteReport $report): bool
    {
        if ((int) $report->submitted_by === (int) $user->id) {
            return false;
        }

        return $user->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers']);
    }

    protected function printPayload(DailySiteReport $report): array
    {
        $report->load([
            'warehouse:id,name,code',
            'submittedBy:id,name,username',
            'logs.user:id,name',
        ]);

        return [
            'dailySiteReport' => $report,
            'logoPath' => $this->reportLogo(),
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => auth()->user()->name,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('daily_site_report', $report),
        ];
    }

    protected function reportLogo(): ?string
    {
        $logo = public_path('images/logo.png');
        if (! file_exists($logo)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode(file_get_contents($logo));
    }

    protected function formatFileSize(?int $bytes): string
    {
        if (! $bytes) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $exponent = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $exponent), 1) . ' ' . $units[$exponent];
    }

    protected function nextReportNumber(): string
    {
        $maxSuffix = (int) DB::table('daily_site_reports')
            ->selectRaw("MAX(CAST(SUBSTRING(report_number, 5) AS UNSIGNED)) as max_suffix")
            ->where('report_number', 'like', 'DSR-%')
            ->value('max_suffix');

        return 'DSR-' . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }

    protected function writeLog(DailySiteReport $report, string $action, ?string $remarks = null): void
    {
        $user = auth()->user();

        $report->logs()->create([
            'user_id' => $user?->id,
            'role' => $user ? optional($user->roles->first())->name : null,
            'action' => $action,
            'remarks' => $remarks,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * In-app "bell" notification (database channel) for Admins/Super Admins, plus
     * Project Managers who manage the report's warehouse. Uses AppNotification with
     * a 'site_reports' module tag so it surfaces in the alerts dropdown/unread count
     * (see AjaxController::alertsNotificationQuery and HandleInertiaRequests).
     */
    protected function notifyAdminsAndPmsOfSubmission(DailySiteReport $report): void
    {
        $report->loadMissing(['warehouse:id,name', 'submittedBy:id,name']);

        $message = $report->report_number . ' submitted by '
            . (optional($report->submittedBy)->name ?? 'a site engineer')
            . ' for ' . (optional($report->warehouse)->name ?? 'N/A') . '.';

        // Site Reports workflow page (Who Gets Notified / Messages): Admins and the Project Managers of this project, as before
        $this->alertSiteReport('submitted', $report, 'Site Report Submitted', $message);
    }

    private function alertSiteReport(string $event, DailySiteReport $report, string $title, string $message, array $ctx = []): void
    {
        $report->loadMissing(['warehouse:id,name', 'submittedBy:id,name']);
        \App\Services\Workflow\WorkflowMessenger::dispatch('site_reports', $event, $report, auth()->user(), $ctx + [
            'account_id' => getAccountId(1), 'title' => $title, 'message' => $message, 'url' => route('daily-site-reports.show', $report->id),
            'owner_user_id' => (int) ($report->submitted_by_user_id ?? $report->submitted_by ?? 0), 'warehouse_ids' => array_filter([$report->warehouse_id]),
            'values' => ['reference' => (string) $report->report_number, 'project' => optional($report->warehouse)->name ?? '', 'creator' => optional($report->submittedBy)->name ?? ''],
        ]);
    }

    /**
     * Real email (not just in-app) to the people chosen on the Site Reports Notification page (Email Report event) and Super Admins: report details and attachment names, but never the physical files.
     */
    protected function notifyWarehouseEmailSubscribersOfSubmission(DailySiteReport $report): void
    {
        $report->loadMissing(['warehouse:id,name', 'submittedBy:id,name', 'attachments']);

        $details = [
            'Report Type' => $report->report_type,
            'Report Date' => optional($report->report_date)->format('Y-m-d'),
            'Priority' => $report->priority,
            'Site Engineer' => optional($report->submittedBy)->name,
            'Remarks' => $report->remarks,
        ];

        $attachmentRows = $report->attachments->map(fn ($attachment) => [
            'name' => $attachment->title,
            'size' => $this->formatFileSize($attachment->filesize),
        ])->values()->all();

        $performedBy = optional(auth()->user())->name ?: optional($report->submittedBy)->name;
        $warehouseName = optional($report->warehouse)->name ?? 'N/A';

        // Site Reports workflow page: the event "Site Report Email Report" decides who gets this email
        MainWarehouseEmailService::report(
            'site_reports', 'report', $report,
            'Site Report',
            $report->report_number,
            $warehouseName,
            $details,
            getAccountId(1),
            $attachmentRows,
            $performedBy,
            'New Site Report Submitted',
            warehouseIds: array_filter([$report->warehouse_id])
        );

        $this->alertSiteReport('admin_copy', $report, __('Site Report Submitted - Admin Copy'), $report->report_number . ' submitted for ' . $warehouseName . ' by ' . optional($report->submittedBy)->name . '.');
    }
}
