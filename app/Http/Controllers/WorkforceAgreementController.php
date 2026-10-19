<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkforceAgreementStoreRequest;
use App\Http\Requests\WorkforceAgreementUpdateRequest;
use App\Http\Resources\WorkforceAgreementCollection;
use App\Models\Contact;
use App\Models\LabourWorkItem;
use App\Models\Staff;
use App\Models\Warehouse;
use App\Models\WorkforceAgreement;
use App\Services\Signatories\Documents\Support\RefusesWithoutSignatures;
use App\Services\Signatories\Documents\WorkforceAgreementDocument;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class WorkforceAgreementController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', WorkforceAgreement::class);
        $filters = $request->all('search', 'warehouse_id', 'status');

        $agreements = WorkforceAgreement::query()
            ->with(['warehouse:id,name'])
            ->filter($filters)
            ->orderByDesc('id')
            ->paginate()
            ->withQueryString();

        $agreements->getCollection()->transform(fn (WorkforceAgreement $a) => $this->presentSummary($a));

        return Inertia::render('WorkforceAgreements/Index', [
            'filters' => $filters,
            'statuses' => WorkforceAgreement::statuses(),
            'warehouses' => $this->projectWarehouses(),
            'canCreate' => $request->user()->can('create', WorkforceAgreement::class),
            'agreements' => new WorkforceAgreementCollection($agreements),
            'cards' => $this->financialCards($filters),
        ]);
    }

    public function printList(Request $request)
    {
        $this->authorize('viewAny', WorkforceAgreement::class);

        return view('workforce-agreements-list-print', $this->listPrintPayload($request, false));
    }

    public function pdfList(Request $request)
    {
        $this->authorize('viewAny', WorkforceAgreement::class);
        $pdf = Pdf::loadView('workforce-agreements-list-print', $this->listPrintPayload($request, true));

        return $pdf->download('workforce-agreements-' . now()->format('Ymd-His') . '.pdf');
    }

    /**
     * `ids` (comma-separated, from the Index page's row checkboxes) restricts
     * the report to just those records — a deliberate "print only what I
     * checked" escape hatch on top of the normal search/warehouse/status
     * filters, which still apply if `ids` is absent.
     */
    protected function listPrintPayload(Request $request, bool $isPdf): array
    {
        $filters = $request->all('search', 'warehouse_id', 'status');
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))));

        $query = WorkforceAgreement::query()->with('warehouse:id,name')->filter($filters)->orderByDesc('id');
        if ($ids) {
            $query->whereIn('id', $ids);
        } else {
            $query->limit(2000);
        }

        $agreements = $query->get()->map(fn (WorkforceAgreement $a) => $this->presentSummary($a));

        return [
            'agreements' => $agreements,
            'totals' => [
                'agreed' => $agreements->sum('agreed_amount'),
                'paid' => $agreements->sum('total_paid'),
                'outstanding' => $agreements->sum('outstanding_balance'),
            ],
            'isSelection' => (bool) $ids,
            'isPdf' => $isPdf,
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => $request->user()->name,
        ];
    }

    /**
     * Server-side sums for the Index page's financial cards — scoped by the
     * same filters as the list itself, unaffected by pagination. Same
     * per-row live-computed sum pattern as byProject(), just ungrouped.
     *
     * Excludes Cancelled agreements by default (a cancelled agreement's
     * balance was never going to be paid, so it shouldn't read as "owed")
     * unless the user explicitly filtered by status — matching
     * projectRollup()'s own hardcoded Active-only scoping, which previously
     * disagreed with this method's "everything unless filtered" default and
     * left the Index and By-Project totals unable to reconcile.
     */
    protected function financialCards(array $filters): array
    {
        $agreements = WorkforceAgreement::query()->filter($filters)
            ->when(empty($filters['status']), fn ($q) => $q->where('status', '!=', WorkforceAgreement::STATUS_CANCELLED))
            ->get();

        return [
            'total_agreed' => $agreements->sum(fn (WorkforceAgreement $a) => (float) $a->agreed_amount),
            'total_paid' => $agreements->sum(fn (WorkforceAgreement $a) => $a->totalPaid()),
            'total_outstanding' => $agreements->sum(fn (WorkforceAgreement $a) => $a->outstandingBalance()),
        ];
    }

    /** Grouped-by-project financial rollup — the "PROJECT A / Worker A / Worker B" view. */
    public function byProject(Request $request)
    {
        $this->authorize('viewAny', WorkforceAgreement::class);

        $warehouseId = $request->query('warehouse_id');

        return Inertia::render('WorkforceAgreements/ByProject', [
            'projects' => $this->projectRollup($warehouseId),
            'warehouses' => $this->projectWarehouses(),
            'filters' => ['warehouse_id' => $warehouseId],
        ]);
    }

    public function printByProject(Request $request)
    {
        $this->authorize('viewAny', WorkforceAgreement::class);

        return view('workforce-agreements-by-project-print', $this->byProjectPrintPayload($request, false));
    }

    public function pdfByProject(Request $request)
    {
        $this->authorize('viewAny', WorkforceAgreement::class);
        $pdf = Pdf::loadView('workforce-agreements-by-project-print', $this->byProjectPrintPayload($request, true));

        return $pdf->download('workforce-agreements-by-project-' . now()->format('Ymd-His') . '.pdf');
    }

    protected function byProjectPrintPayload(Request $request, bool $isPdf): array
    {
        $warehouseId = $request->query('warehouse_id');
        $projects = $this->projectRollup($warehouseId);

        return [
            'projects' => $projects,
            'totals' => [
                'agreed' => $projects->sum('total_agreed'),
                'paid' => $projects->sum('total_paid'),
                'outstanding' => $projects->sum('total_outstanding'),
            ],
            'isPdf' => $isPdf,
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => $request->user()->name,
        ];
    }

    /** Shared by byProject()'s Inertia render and its print/PDF views — one grouping computation, two presentations. */
    protected function projectRollup($warehouseId)
    {
        $query = WorkforceAgreement::query()->with('warehouse:id,name')->where('status', WorkforceAgreement::STATUS_ACTIVE);
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $agreements = $query->orderBy('warehouse_id')->get();
        $grouped = $agreements->groupBy(fn (WorkforceAgreement $a) => optional($a->warehouse)->name ?? '—');

        return $grouped->map(function ($rows, $warehouseName) {
            $workers = $rows->map(fn (WorkforceAgreement $a) => $this->presentSummary($a))->values();

            return [
                'warehouse_name' => $warehouseName,
                'total_agreed' => $rows->sum(fn ($a) => (float) $a->agreed_amount),
                'total_requested' => $rows->sum(fn ($a) => $a->totalRequested()),
                'total_approved' => $rows->sum(fn ($a) => $a->totalApproved()),
                'total_paid' => $rows->sum(fn ($a) => $a->totalPaid()),
                'total_outstanding' => $rows->sum(fn ($a) => $a->outstandingBalance()),
                'worker_count' => $rows->count(),
                'pending_count' => $rows->filter(fn ($a) => $a->paymentStatus() !== 'Fully Paid')->count(),
                'paid_count' => $rows->filter(fn ($a) => $a->paymentStatus() === 'Fully Paid')->count(),
                'workers' => $workers,
            ];
        })->values();
    }

    public function create(Request $request)
    {
        $this->authorize('create', WorkforceAgreement::class);

        return Inertia::render('WorkforceAgreements/Form', [
            'edit' => null,
            'prefill' => $this->createPrefill($request),
            'warehouses' => $this->projectWarehouses(),
            'staff' => Staff::query()->orderBy('name')->get(['id', 'name']),
            'contacts' => Contact::query()->whereIn('category', LabourController::LABOUR_PERSONNEL_CATEGORIES)->orderBy('name')->get(['id', 'name', 'category']),
            'workItems' => $this->workItemsBank(),
            'reference' => $this->nextReference(),
        ]);
    }

    /**
     * Optional pre-selection when arriving from Labour/Form.vue's "Create
     * Work Agreement" link (shown once a request exceeds its agreement's
     * outstanding balance) — carries the worker/project already chosen
     * there over as query params, purely a UI convenience. Every id is
     * verified to actually exist before being trusted; an invalid/missing
     * one is silently dropped rather than passed through.
     */
    protected function createPrefill(Request $request): ?array
    {
        $warehouseId = $request->query('warehouse_id');
        $payeeType = $request->query('payee_type');
        $contactId = $request->query('contact_id');
        $staffId = $request->query('staff_id');

        if (! $warehouseId && ! $payeeType && ! $contactId && ! $staffId) {
            return null;
        }

        return [
            'warehouse_id' => Warehouse::whereKey($warehouseId)->exists() ? (int) $warehouseId : null,
            'payee_type' => in_array($payeeType, [WorkforceAgreement::PAYEE_TYPE_STAFF, WorkforceAgreement::PAYEE_TYPE_EXTERNAL], true) ? $payeeType : null,
            'contact_id' => Contact::whereKey($contactId)->exists() ? (int) $contactId : null,
            'staff_id' => Staff::whereKey($staffId)->exists() ? (int) $staffId : null,
        ];
    }

    public function store(WorkforceAgreementStoreRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();

        $payeeName = $data['payee_type'] === WorkforceAgreement::PAYEE_TYPE_STAFF
            ? optional(Staff::find($data['staff_id']))->name
            : optional(Contact::find($data['contact_id']))->name;

        // Signatories: when the document setup requires a signature and blocks finalising, refuse to create the agreement without it (nothing changes with the default setup).
        if ($blocked = RefusesWithoutSignatures::message('workforce_agreement', app(WorkforceAgreementDocument::class)->asIfCreated((int) $user->id), __('created'))) {
            return back()->withInput()->with('error', $blocked);
        }

        $this->rememberWorkItem($data['type_of_work'] ?? '');

        $agreement = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $agreement = WorkforceAgreement::create([
                    'reference' => $this->nextReference(),
                    'warehouse_id' => $data['warehouse_id'],
                    'payee_type' => $data['payee_type'],
                    'contact_id' => $data['payee_type'] === WorkforceAgreement::PAYEE_TYPE_EXTERNAL ? $data['contact_id'] : null,
                    'staff_id' => $data['payee_type'] === WorkforceAgreement::PAYEE_TYPE_STAFF ? $data['staff_id'] : null,
                    'payee_name' => $payeeName ?: '—',
                    'type_of_work' => $data['type_of_work'] ?? null,
                    'scope_of_work' => $data['scope_of_work'] ?? null,
                    'agreed_amount' => $data['agreed_amount'] ?? 0,
                    'opening_balance' => $data['opening_balance'] ?? 0,
                    'agreement_reference' => $data['agreement_reference'] ?? null,
                    'agreement_date' => $data['agreement_date'],
                    'notes' => $data['notes'] ?? null,
                    'status' => WorkforceAgreement::STATUS_ACTIVE,
                    'created_by_user_id' => $user->id,
                ]);
                break;
            } catch (QueryException $e) {
                // The unique-reference race (retry with a fresh number) and
                // the opening-balance race (GuardsUniqueOpeningBalance's own
                // check-then-create is only best-effort — this DB constraint
                // is what actually closes it) are different failure modes:
                // one is worth retrying, the other never will be.
                if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'workforce_agreements_opening_balance_lock_unique')) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'opening_balance' => __('This worker already has an opening balance recorded on this project. Only one opening balance is allowed per worker per project.'),
                    ]);
                }

                $isDuplicate = (string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'workforce_agreements_reference_unique');
                if (! $isDuplicate || $attempt === 2) {
                    throw $e;
                }
            }
        }

        if (! $agreement) {
            abort(500, 'Unable to generate a unique agreement reference.');
        }

        $agreement->saveAttachments($request->file('attachments'), 'workforce-agreement');
        log_activity(__('Workforce Agreement created.'), ['reference' => $agreement->reference, 'agreed_amount' => $agreement->agreed_amount, 'by_user_id' => $user->id], $agreement, 'Workforce Agreement');

        return redirect()->route('workforce-agreements.show', $agreement->id)->with('message', __('Workforce Agreement created.'));
    }

    public function show(Request $request, WorkforceAgreement $workforce_agreement)
    {
        $this->authorize('view', $workforce_agreement);
        $workforce_agreement->load(['warehouse:id,name', 'contact:id,name', 'staff:id,name', 'createdBy:id,name', 'attachments']);

        return Inertia::render('WorkforceAgreements/Show', [
            'agreement' => $this->presentDetail($workforce_agreement),
            'can' => [
                'update' => $request->user()->can('update', $workforce_agreement),
                'cancel' => $request->user()->can('cancel', $workforce_agreement),
            ],
        ]);
    }

    public function edit(Request $request, WorkforceAgreement $workforce_agreement)
    {
        $this->authorize('update', $workforce_agreement);

        return Inertia::render('WorkforceAgreements/Form', [
            'edit' => $this->presentDetail($workforce_agreement),
            'warehouses' => $this->projectWarehouses(),
            'staff' => Staff::query()->orderBy('name')->get(['id', 'name']),
            'contacts' => Contact::query()->whereIn('category', LabourController::LABOUR_PERSONNEL_CATEGORIES)->orderBy('name')->get(['id', 'name', 'category']),
            'workItems' => $this->workItemsBank(),
            'reference' => $workforce_agreement->reference,
        ]);
    }

    public function update(WorkforceAgreementUpdateRequest $request, WorkforceAgreement $workforce_agreement)
    {
        $data = $request->validated();

        $payeeName = $data['payee_type'] === WorkforceAgreement::PAYEE_TYPE_STAFF
            ? optional(Staff::find($data['staff_id']))->name
            : optional(Contact::find($data['contact_id']))->name;

        $this->rememberWorkItem($data['type_of_work'] ?? '');

        try {
            $workforce_agreement->update([
                'warehouse_id' => $data['warehouse_id'],
                'payee_type' => $data['payee_type'],
                'contact_id' => $data['payee_type'] === WorkforceAgreement::PAYEE_TYPE_EXTERNAL ? $data['contact_id'] : null,
                'staff_id' => $data['payee_type'] === WorkforceAgreement::PAYEE_TYPE_STAFF ? $data['staff_id'] : null,
                'payee_name' => $payeeName ?: '—',
                'type_of_work' => $data['type_of_work'] ?? null,
                'scope_of_work' => $data['scope_of_work'] ?? null,
                'agreed_amount' => $data['agreed_amount'] ?? 0,
                'opening_balance' => $data['opening_balance'] ?? 0,
                'agreement_reference' => $data['agreement_reference'] ?? null,
                'agreement_date' => $data['agreement_date'],
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'workforce_agreements_opening_balance_lock_unique')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'opening_balance' => __('This worker already has an opening balance recorded on this project. Only one opening balance is allowed per worker per project.'),
                ]);
            }

            throw $e;
        }

        $workforce_agreement->saveAttachments($request->file('attachments'), 'workforce-agreement');
        log_activity(__('Workforce Agreement updated.'), ['reference' => $workforce_agreement->reference], $workforce_agreement, 'Workforce Agreement');

        return redirect()->route('workforce-agreements.show', $workforce_agreement->id)->with('message', __('Workforce Agreement updated.'));
    }

    public function cancel(Request $request, WorkforceAgreement $workforce_agreement)
    {
        $this->authorize('cancel', $workforce_agreement);

        DB::transaction(function () use ($workforce_agreement, $request) {
            // Locked and re-checked — without this, two near-simultaneous
            // cancel clicks both pass the Active check and both write,
            // producing a duplicate "cancelled" activity-log entry for one
            // real cancellation.
            $locked = WorkforceAgreement::whereKey($workforce_agreement->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === WorkforceAgreement::STATUS_ACTIVE, 422, __('This agreement is no longer Active.'));

            $locked->update(['status' => WorkforceAgreement::STATUS_CANCELLED, 'cancelled_by_user_id' => $request->user()->id, 'cancelled_at' => now()]);
            log_activity(__('Workforce Agreement cancelled.'), ['reference' => $locked->reference, 'by_user_id' => $request->user()->id], $locked, 'Workforce Agreement');
        });

        return back()->with('message', __('Workforce Agreement cancelled.'));
    }

    /** The agreement as its own printable document: terms, the live balance (the same model methods the Show screen uses) and the signatures. */
    public function printDocument(Request $request, WorkforceAgreement $workforce_agreement)
    {
        $this->authorizePrint($request, $workforce_agreement);

        return view('workforce-agreements.print', $this->documentPayload($workforce_agreement, false));
    }

    public function pdfDocument(Request $request, WorkforceAgreement $workforce_agreement)
    {
        $this->authorizePrint($request, $workforce_agreement);

        return Pdf::loadView('workforce-agreements.print', $this->documentPayload($workforce_agreement, true))->download('workforce-agreement-' . $workforce_agreement->reference . '.pdf');
    }

    /** Same reach as the policy (Super Admin and Admin pass, others need the read ability) plus the dedicated print ability. */
    protected function authorizePrint(Request $request, WorkforceAgreement $agreement): void
    {
        abort_unless($request->user()->hasRole(['Super Admin', 'Admin']) || $request->user()->can('print-workforce-agreements'), 403);
        $this->authorize('view', $agreement);
    }

    protected function documentPayload(WorkforceAgreement $agreement, bool $isPdf): array
    {
        $agreement->load(['warehouse:id,name', 'contact:id,name', 'staff:id,name', 'createdBy:id,name', 'cancelledBy:id,name']);

        return [
            'agreement' => array_merge($this->presentDetail($agreement), [
                'available_balance' => $agreement->availableBalance(),
                'total_entitlement' => $agreement->totalEntitlement(),
                'cancelled_by_name' => optional($agreement->cancelledBy)->name,
                'cancelled_at' => optional($agreement->cancelled_at)->format('d M Y H:i'),
                'agreement_date_label' => optional($agreement->agreement_date)->format('d M Y'),
            ]),
            'companyName' => \App\Models\PurchaseOrder::COMPANY_NAME,
            'qr' => PrintQrCode::dataUri(route('workforce-agreements.show', $agreement->id)),
            'isPdf' => $isPdf,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('workforce_agreement', $agreement),
        ];
    }

    /** Used by Labour/Form.vue to look up a worker's active agreement(s) + live outstanding balance for a given project, before a request is submitted. */
    public function forPersonnel(Request $request)
    {
        $this->authorize('viewAny', WorkforceAgreement::class);

        $data = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'payee_type' => 'required|in:' . WorkforceAgreement::PAYEE_TYPE_STAFF . ',' . WorkforceAgreement::PAYEE_TYPE_EXTERNAL,
            'contact_id' => 'nullable|exists:contacts,id',
            'staff_id' => 'nullable|exists:staffs,id',
        ]);

        $query = WorkforceAgreement::query()
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('payee_type', $data['payee_type'])
            ->where('status', WorkforceAgreement::STATUS_ACTIVE);

        if ($data['payee_type'] === WorkforceAgreement::PAYEE_TYPE_STAFF) {
            $query->where('staff_id', $data['staff_id'] ?? null);
        } else {
            $query->where('contact_id', $data['contact_id'] ?? null);
        }

        $agreements = $query->orderByDesc('id')->get()->map(fn (WorkforceAgreement $a) => $this->presentSummary($a));

        return response()->json(['agreements' => $agreements]);
    }

    protected function presentSummary(WorkforceAgreement $a): array
    {
        return [
            'id' => $a->id,
            'reference' => $a->reference,
            'warehouse_id' => $a->warehouse_id,
            'warehouse_name' => optional($a->warehouse)->name,
            'payee_type' => $a->payee_type,
            'payee_name' => $a->payee_name,
            'scope_of_work' => $a->scope_of_work,
            'agreement_date' => optional($a->agreement_date)->format('Y-m-d'),
            'status' => $a->status,
            'agreed_amount' => (float) $a->agreed_amount,
            'opening_balance' => (float) $a->opening_balance,
            'total_requested' => $a->totalRequested(),
            'total_approved' => $a->totalApproved(),
            'total_paid' => $a->totalPaid(),
            'outstanding_balance' => $a->outstandingBalance(),
            'payment_status' => $a->paymentStatus(),
        ];
    }

    protected function presentDetail(WorkforceAgreement $a): array
    {
        $a->loadMissing(['labourListItems.labourList:id,reference,status', 'labourListItems.paymentVoucher:id,pv_number,status,paid_at,labour_list_item_id']);

        return array_merge($this->presentSummary($a), [
            'contact_id' => $a->contact_id,
            'staff_id' => $a->staff_id,
            'type_of_work' => $a->type_of_work,
            'scope_of_work' => $a->scope_of_work,
            'agreement_reference' => $a->agreement_reference,
            'notes' => $a->notes,
            'is_locked' => $a->isLocked(),
            'created_by_name' => optional($a->createdBy)->name,
            'created_at' => optional($a->created_at)->format('Y-m-d H:i'),
            'attachments' => $a->attachments->map(fn ($file) => ['id' => $file->id, 'name' => $file->title ?: $file->filename, 'download_url' => route('workforce-agreements.attachments.download', [$a->id, $file->id])]),
            'requests' => $a->labourListItems->map(fn ($item) => [
                'id' => $item->id,
                'labour_list_id' => $item->labour_list_id,
                'labour_list_reference' => optional($item->labourList)->reference,
                'labour_list_status' => optional($item->labourList)->status,
                'requested_amount' => (float) $item->amount,
                'approved_amount' => $item->approved_amount !== null ? (float) $item->approved_amount : null,
                'revision_reason' => $item->revision_reason,
                'voucher' => $item->paymentVoucher ? [
                    'id' => $item->paymentVoucher->id,
                    'pv_number' => $item->paymentVoucher->pv_number,
                    'status' => $item->paymentVoucher->status,
                    'paid' => ! is_null($item->paymentVoucher->paid_at),
                ] : null,
            ])->values(),
        ]);
    }

    public function downloadAttachment(Request $request, WorkforceAgreement $workforce_agreement, Attachment $attachment)
    {
        $this->authorize('view', $workforce_agreement);
        abort_unless((int) $attachment->model_id === (int) $workforce_agreement->id && $attachment->model_type === WorkforceAgreement::class, 404);

        return \Illuminate\Support\Facades\Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }

    protected function nextReference(): string
    {
        $year = now()->format('Y');
        $prefixLength = strlen("WFA-{$year}-") + 1;
        $maxSuffix = (int) DB::table('workforce_agreements')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, {$prefixLength}) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', "WFA-{$year}-%")
            ->value('max_suffix');

        return "WFA-{$year}-" . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }

    /** A workforce agreement's Project/Site picker must never offer a Main Warehouse — agreements only ever apply to a project/site. Same exclusion pattern used across the app (ProjectCertificateController, ProjectExecutionController, etc). */
    protected function projectWarehouses()
    {
        return Warehouse::ofAccount()->active()->visible()
            ->whereNotIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses'])
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** Same growable-bank pattern as LabourController::rememberWorkItem()/workItemsBank() — Type of Work shares the exact same LabourWorkItem bank as Labour's own Work Performed field. */
    protected function rememberWorkItem(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $record = LabourWorkItem::firstOrCreate(['text' => $text]);
        if (! $record->wasRecentlyCreated) {
            $record->increment('use_count');
        }
    }

    protected function workItemsBank(): array
    {
        return LabourWorkItem::orderByDesc('use_count')->orderBy('id')->limit(200)->pluck('text')->values()->all();
    }
}
