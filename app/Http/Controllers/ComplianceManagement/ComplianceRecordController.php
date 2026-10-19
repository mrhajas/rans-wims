<?php

namespace App\Http\Controllers\ComplianceManagement;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComplianceRecordCollection;
use App\Models\Asset;
use App\Models\ComplianceCategory;
use App\Models\ComplianceDocumentVersion;
use App\Models\ComplianceHistory;
use App\Models\ComplianceIssuingAuthority;
use App\Models\ComplianceRecord;
use App\Models\ComplianceType;
use App\Models\Contact;
use App\Models\User;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class ComplianceRecordController extends Controller
{
    // destroy() here is a soft "Cancel" action (never a hard delete — see
    // destroy() below), gated on the distinct dispose-compliance-records
    // permission instead of the base trait's auto-derived
    // delete-compliance-records, so it isn't double-gated behind two
    // separate permissions.
    public function __construct()
    {
        $this->setAbilities([
            'index' => 'read', 'show' => 'read', 'edit' => 'update', 'update' => 'update',
            'create' => 'create', 'store' => 'create', 'destroy' => 'dispose',
        ]);
    }

    public function reportsHub()
    {
        $this->authorize('read-compliance-records');

        return Inertia::render('Compliance/Reports');
    }

    public function index(Request $request)
    {
        $filters = [
            'compliance_category_id' => $request->query('compliance_category_id'),
            'compliance_type' => $request->query('compliance_type'),
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'warehouse_id' => $request->query('warehouse_id'),
            'responsible_user_id' => $request->query('responsible_user_id'),
            'department' => $request->query('department'),
            'issuing_authority' => $request->query('issuing_authority'),
            'bank_name' => $request->query('bank_name'),
            'supplier_id' => $request->query('supplier_id'),
            'asset_id' => $request->query('asset_id'),
            'scope' => $request->query('scope'),
            'expiry_from' => $request->query('expiry_from'),
            'expiry_to' => $request->query('expiry_to'),
            'expired' => $request->boolean('expired') ?: null,
            'due_soon' => $request->boolean('due_soon') ?: null,
            'renewal_in_progress' => $request->boolean('renewal_in_progress') ?: null,
            'search' => trim((string) $request->query('search', '')) ?: null,
        ];

        $records = ComplianceRecord::query()
            ->with(['category', 'warehouse', 'responsibleUser'])
            ->filter($filters)
            ->orderByDesc('id')
            ->paginate()
            ->withQueryString()
            ->through(fn (ComplianceRecord $r) => $this->presentSummary($r));

        return Inertia::render('Compliance/Index', [
            'filters' => $filters,
            'records' => new ComplianceRecordCollection($records),
            'categories' => $this->categoryOptions(),
            'warehouses' => $this->warehouseOptions(),
            'statuses' => ComplianceRecord::STATUSES,
            'priorities' => ComplianceRecord::PRIORITIES,
            'types' => ComplianceRecord::TYPES,
            'scopes' => ComplianceRecord::SCOPES,
        ]);
    }

    public function create()
    {
        return Inertia::render('Compliance/Form', [
            'record' => null,
            'categories' => $this->categoryOptions(),
            'warehouses' => $this->warehouseOptions(),
            'suppliers' => $this->supplierOptions(),
            'assets' => $this->assetOptions(),
            'users' => $this->userOptions(),
            'statuses' => ComplianceRecord::STATUSES,
            'priorities' => ComplianceRecord::PRIORITIES,
            'types' => $this->typesBank(),
            'authorities' => $this->authoritiesBank(),
            'scopes' => ComplianceRecord::SCOPES,
            'guaranteeTypes' => ComplianceRecord::GUARANTEE_TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        unset($data['photo']);
        $this->rememberType($data['compliance_type'] ?? '');
        $this->rememberAuthority($data['issuing_authority'] ?? '');

        $record = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $record = new ComplianceRecord();
                $record->fill($data);
                $record->compliance_number = $this->nextReference();
                if ($request->hasFile('photo')) {
                    $record->photo = $request->file('photo')->store('compliance', 'assets');
                }
                $record->save();
                break;
            } catch (QueryException $e) {
                $isDuplicate = (string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'compliance_records_compliance_number_unique');
                if (! $isDuplicate || $attempt === 2) {
                    throw $e;
                }
                $record = null;
            }
        }

        if (! $record) {
            abort(500, 'Unable to generate a unique compliance number.');
        }

        $record->recalculateStatusAndPriority();
        ComplianceHistory::log($record, 'Created', __('Compliance record created.'));
        \App\Services\ComplianceNotifier::fire('created', $record, __('Compliance Record Created'), $record->title . ' (' . $record->compliance_number . ') was created.');

        if ($request->hasFile('attachments')) {
            $this->storeDocuments($record, $request->file('attachments'), $request->input('document_type', 'Supporting Documents'), $request->user()->id);
        }

        return redirect()->route('compliance-records.show', $record->id)->with('message', __choice('action_text', ['record' => 'Compliance Record', 'action' => 'created']));
    }

    public function show(ComplianceRecord $compliance_record)
    {
        $compliance_record->load(['category', 'warehouse', 'supplier', 'contact', 'asset', 'responsibleUser', 'escalationRecipient', 'renewalHistory', 'documentVersions.attachment', 'reminderLog.recipient', 'history.user', 'attachments']);

        return Inertia::render('Compliance/Show', [
            'record' => $this->presentDetail($compliance_record),
        ]);
    }

    public function edit(ComplianceRecord $compliance_record)
    {
        $compliance_record->load(['category']);

        return Inertia::render('Compliance/Form', [
            'record' => $this->presentDetail($compliance_record),
            'categories' => $this->categoryOptions(),
            'warehouses' => $this->warehouseOptions(),
            'suppliers' => $this->supplierOptions(),
            'assets' => $this->assetOptions(),
            'users' => $this->userOptions(),
            'statuses' => ComplianceRecord::STATUSES,
            'priorities' => ComplianceRecord::PRIORITIES,
            'types' => $this->typesBank(),
            'authorities' => $this->authoritiesBank(),
            'scopes' => ComplianceRecord::SCOPES,
            'guaranteeTypes' => ComplianceRecord::GUARANTEE_TYPES,
        ]);
    }

    public function update(Request $request, ComplianceRecord $compliance_record)
    {
        $data = $this->validated($request, $compliance_record->id);
        unset($data['photo']);
        $this->rememberType($data['compliance_type'] ?? '');
        $this->rememberAuthority($data['issuing_authority'] ?? '');
        $changes = array_filter($data, fn ($value, $key) => $compliance_record->{$key} != $value, ARRAY_FILTER_USE_BOTH);

        if ($request->hasFile('photo')) {
            if ($compliance_record->photo) {
                Storage::disk('assets')->delete($compliance_record->photo);
            }
            $data['photo'] = $request->file('photo')->store('compliance', 'assets');
        }

        $compliance_record->update($data);
        $compliance_record->recalculateStatusAndPriority();

        if (! empty($changes)) {
            ComplianceHistory::log($compliance_record, 'Edited', __(':count field(s) updated.', ['count' => count($changes)]));
        }

        if ($request->hasFile('attachments')) {
            $this->storeDocuments($compliance_record, $request->file('attachments'), $request->input('document_type', 'Supporting Documents'), $request->user()->id);
        }

        \App\Services\ComplianceNotifier::fire('updated', $compliance_record, __('Compliance Record Edited'), $compliance_record->title . ' (' . $compliance_record->compliance_number . ') was edited by ' . $request->user()->name . '.');

        return back()->with('message', __choice('action_text', ['record' => 'Compliance Record', 'action' => 'updated']));
    }

    public function destroy(ComplianceRecord $compliance_record)
    {
        $compliance_record->update(['status' => 'Cancelled']);
        ComplianceHistory::log($compliance_record, 'Cancelled', __('Compliance record cancelled.'));
        \App\Services\ComplianceNotifier::fire('cancelled', $compliance_record, __('Compliance Record Cancelled'), $compliance_record->title . ' (' . $compliance_record->compliance_number . ') was cancelled.');

        return redirect()->route('compliance-records.index')->with('message', __('Compliance record cancelled.'));
    }

    public function destroyPhoto(ComplianceRecord $compliance_record)
    {
        $this->authorize('update-compliance-records');

        if ($compliance_record->photo && Storage::disk('assets')->delete($compliance_record->photo)) {
            $compliance_record->update(['photo' => null]);

            return back()->with('message', __('Photo removed.'));
        }

        return back()->with('error', __('The photo could not be removed.'));
    }

    public function destroyAttachment(ComplianceRecord $compliance_record, Attachment $attachment)
    {
        $this->authorize('update-compliance-records');

        abort_unless((int) $attachment->model_id === (int) $compliance_record->id && $attachment->model_type === ComplianceRecord::class, 404);

        ComplianceDocumentVersion::where('compliance_record_id', $compliance_record->id)->where('attachment_id', $attachment->id)->delete();
        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        ComplianceHistory::log($compliance_record, 'Document Removed', __('A document was removed.'));
        \App\Services\ComplianceNotifier::fireWith('document_removed', $compliance_record, __('Compliance Document Removed'), 'A document of ' . $compliance_record->title . ' (' . $compliance_record->compliance_number . ') was removed by ' . auth()->user()->name . '.', ['file' => (string) ($attachment->filename ?: $attachment->title)]);

        return back()->with('message', __('Attachment removed.'));
    }

    public function uploadDocument(Request $request, ComplianceRecord $compliance_record)
    {
        $this->authorize('upload-compliance-documents');

        $data = $request->validate([
            'attachments' => 'required|array',
            'attachments.*' => 'file|max:10240|mimes:' . env('ATTACHMENT_EXTS', 'jpg,png,pdf,docx,xlsx,zip'),
            'document_type' => 'required|string|max:100',
        ]);

        $this->storeDocuments($compliance_record, $request->file('attachments'), $data['document_type'], $request->user()->id);
        \App\Services\ComplianceNotifier::fireWith('document_added', $compliance_record, __('Compliance Document Uploaded'), count($request->file('attachments')) . ' document(s) (' . $data['document_type'] . ') were added to ' . $compliance_record->title . ' (' . $compliance_record->compliance_number . ') by ' . $request->user()->name . '.', ['file' => (string) $data['document_type']]);

        return back()->with('message', __('Document uploaded.'));
    }

    public function downloadAttachment(Request $request, ComplianceRecord $compliance_record, Attachment $attachment)
    {
        $this->authorize('read-compliance-records');

        abort_unless((int) $attachment->model_id === (int) $compliance_record->id && $attachment->model_type === ComplianceRecord::class, 404);

        if ($request->query('disposition') === 'inline') {
            return Storage::disk($attachment->disk)->response($attachment->filepath, $attachment->title);
        }

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title);
    }

    public function print(ComplianceRecord $compliance_record)
    {
        $this->authorize('read-compliance-records');
        $this->loadForPrint($compliance_record);

        return view('compliance-records.print', $this->printData($compliance_record));
    }

    public function pdf(ComplianceRecord $compliance_record)
    {
        $this->authorize('read-compliance-records');
        $this->loadForPrint($compliance_record);

        return Pdf::loadView('compliance-records.print', $this->printData($compliance_record))
            ->setPaper('a4')
            ->download('compliance-' . $compliance_record->compliance_number . '.pdf');
    }

    public function printList(Request $request)
    {
        $this->authorize('read-compliance-records');

        return view('compliance-records-list-print', $this->listPayload($request) + ['isPdf' => false]);
    }

    public function pdfList(Request $request)
    {
        $this->authorize('read-compliance-records');

        return Pdf::loadView('compliance-records-list-print', $this->listPayload($request) + ['isPdf' => true])
            ->setPaper('a4', 'landscape')
            ->download('compliance-records-' . now()->format('Ymd-His') . '.pdf');
    }

    public function export(Request $request)
    {
        $this->authorize('export-compliance-records');

        $rows = ComplianceRecord::query()->with(['category', 'warehouse', 'responsibleUser'])->filter($request->query())->orderBy('compliance_number')->limit(10000)->get();

        return (new \Rap2hpoutre\FastExcel\FastExcel($rows))->download('compliance-records-' . now()->format('Ymd-His') . '.xlsx', function (ComplianceRecord $r) {
            return [
                'Compliance No.' => $r->compliance_number,
                'Title' => $r->title,
                'Type' => $r->compliance_type,
                'Category' => optional($r->category)->name,
                'Responsible' => optional($r->responsibleUser)->name,
                'Project' => optional($r->warehouse)->name,
                'Expiry Date' => optional($r->expiry_date)->format('Y-m-d'),
                'Days Remaining' => $r->days_remaining,
                'Status' => $r->status,
                'Priority' => $r->priority,
            ];
        });
    }

    private function storeDocuments(ComplianceRecord $record, array $files, string $documentType, int $userId): void
    {
        $existingIds = $record->attachments()->pluck('id')->all();

        $record->saveAttachments($files, $documentType);

        $newAttachments = $record->attachments()->whereNotIn('id', $existingIds)->get();

        ComplianceDocumentVersion::where('compliance_record_id', $record->id)
            ->where('document_type', $documentType)
            ->update(['is_current' => false]);

        foreach ($newAttachments as $index => $attachment) {
            $nextVersion = (int) (ComplianceDocumentVersion::where('compliance_record_id', $record->id)->where('document_type', $documentType)->max('version_number')) + 1;

            ComplianceDocumentVersion::create([
                'compliance_record_id' => $record->id,
                'attachment_id' => $attachment->id,
                'document_type' => $documentType,
                'version_number' => $nextVersion,
                'is_current' => true,
                'uploaded_by_user_id' => $userId,
            ]);
        }

        ComplianceHistory::log($record, $existingIds ? 'Document Replaced' : 'Document Added', __(':type document uploaded.', ['type' => $documentType]));
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'compliance_type' => 'required|string|max:100',
            'compliance_category_id' => 'nullable|exists:compliance_categories,id',
            'title' => 'required|string|max:190',
            'photo' => 'nullable|image|mimes:png,jpg,jpeg,svg|max:5120',
            'description' => 'nullable|string',
            'reference_number' => 'nullable|string|max:190',
            'issuing_authority' => 'nullable|string|max:190',
            'issued_to' => 'nullable|string|max:190',
            'responsible_user_id' => 'nullable|exists:users,id',
            'department' => 'nullable|string|max:190',

            'issue_date' => 'nullable|date',
            'effective_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'renewal_date' => 'nullable|date',
            'submission_date' => 'nullable|date',
            'next_review_date' => 'nullable|date',

            'warehouse_id' => 'nullable|exists:warehouses,id',
            'supplier_id' => 'nullable|exists:contacts,id',
            'contact_id' => 'nullable|exists:contacts,id',
            'asset_id' => 'nullable|exists:assets,id',
            'bank_name' => 'nullable|string|max:190',
            'contract_reference' => 'nullable|string|max:190',

            'scope' => 'required|in:' . implode(',', ComplianceRecord::SCOPES),
            'priority_is_manual_override' => 'nullable|boolean',
            'priority' => 'nullable|in:' . implode(',', ComplianceRecord::PRIORITIES),

            'guarantee_number' => 'nullable|string|max:190',
            'guarantee_bank' => 'nullable|string|max:190',
            'guarantee_beneficiary' => 'nullable|string|max:190',
            'guarantee_applicant' => 'nullable|string|max:190',
            'guarantee_type' => 'nullable|in:' . implode(',', ComplianceRecord::GUARANTEE_TYPES),
            'guarantee_amount' => 'nullable|numeric',
            'guarantee_currency' => 'nullable|string|max:8',
            'guarantee_claim_expiry' => 'nullable|date',
            'guarantee_purpose' => 'nullable|string',
            'guarantee_commission' => 'nullable|numeric',
            'guarantee_renewal_terms' => 'nullable|string',

            'loan_lender' => 'nullable|string|max:190',
            'loan_reference' => 'nullable|string|max:190',
            'loan_facility_type' => 'nullable|string|max:190',
            'loan_principal_amount' => 'nullable|numeric',
            'loan_currency' => 'nullable|string|max:8',
            'loan_interest_rate' => 'nullable|numeric',
            'loan_start_date' => 'nullable|date',
            'loan_maturity_date' => 'nullable|date',
            'loan_instalment_frequency' => 'nullable|in:Monthly,Quarterly,Semi-Annual,Annual',
            'loan_instalment_amount' => 'nullable|numeric',
            'loan_outstanding_balance' => 'nullable|numeric',
            'loan_next_payment_date' => 'nullable|date',
            'loan_security' => 'nullable|string',

            'insurance_policy_number' => 'nullable|string|max:190',
            'insurance_provider' => 'nullable|string|max:190',
            'insurance_policy_type' => 'nullable|string|max:190',
            'insurance_insured_party' => 'nullable|string|max:190',
            'insurance_insured_value' => 'nullable|numeric',
            'insurance_premium' => 'nullable|numeric',

            'contract_number' => 'nullable|string|max:190',
            'contract_type' => 'nullable|string|max:190',
            'contract_parties' => 'nullable|string',
            'contract_value' => 'nullable|numeric',
            'contract_notice_period_days' => 'nullable|integer|min:0',
            'contract_renewal_terms' => 'nullable|string',

            'licence_or_certificate_holder' => 'nullable|string|max:190',
            'renewal_fee' => 'nullable|numeric',
            'renewal_requirements' => 'nullable|string',

            'is_recurring' => 'nullable|boolean',
            'recurrence_frequency' => 'nullable|in:Monthly,Quarterly,Semi-Annual,Annual,Custom',
            'recurrence_custom_days' => 'nullable|integer|min:1',

            'reminder_intervals' => 'nullable|array',
            'reminder_intervals.*' => 'integer',
            'escalation_recipient_user_id' => 'nullable|exists:users,id',
            'additional_recipient_user_ids' => 'nullable|array',
            'additional_recipient_user_ids.*' => 'exists:users,id',
            'responsible_role' => 'nullable|string|max:190',

            'notes' => 'nullable|string',
        ]);
    }

    private function nextReference(): string
    {
        $maxSuffix = (int) DB::table('compliance_records')
            ->selectRaw('MAX(CAST(SUBSTRING(compliance_number, 5) AS UNSIGNED)) as max_suffix')
            ->where('compliance_number', 'like', 'CMP-%')
            ->value('max_suffix');

        return 'CMP-' . str_pad((string) ($maxSuffix + 1), 6, '0', STR_PAD_LEFT);
    }

    /** Same growable-bank pattern as PaymentVoucherController's Voucher Type list. */
    private function rememberType(string $type): void
    {
        if ($type === '') {
            return;
        }

        $record = ComplianceType::firstOrCreate(['text' => $type]);
        if (! $record->wasRecentlyCreated) {
            $record->increment('use_count');
        }
    }

    private function typesBank(): array
    {
        return ComplianceType::orderByDesc('use_count')->limit(200)->pluck('text')->values()->all();
    }

    private function rememberAuthority(string $authority): void
    {
        if ($authority === '') {
            return;
        }

        $record = ComplianceIssuingAuthority::firstOrCreate(['text' => $authority]);
        if (! $record->wasRecentlyCreated) {
            $record->increment('use_count');
        }
    }

    private function authoritiesBank(): array
    {
        return ComplianceIssuingAuthority::orderByDesc('use_count')->limit(200)->pluck('text')->values()->all();
    }

    private function categoryOptions(): array
    {
        return ComplianceCategory::active()->orderBy('group')->orderBy('name')->get(['id', 'name', 'group'])->values()->all();
    }

    private function warehouseOptions(): array
    {
        return Warehouse::active()->visible()->orderBy('name')->get(['id', 'name'])->values()->all();
    }

    private function supplierOptions(): array
    {
        return Contact::orderBy('name')->get(['id', 'name', 'category'])->values()->all();
    }

    private function assetOptions(): array
    {
        return Asset::active()->orderBy('name')->get(['id', 'name', 'asset_number'])->values()->all();
    }

    private function userOptions(): array
    {
        return User::orderBy('name')->get(['id', 'name'])->values()->all();
    }

    private function presentSummary(ComplianceRecord $record): array
    {
        return [
            'id' => $record->id,
            'compliance_number' => $record->compliance_number,
            'title' => $record->title,
            'photo_url' => $record->photo ? Storage::disk('assets')->url($record->photo) : null,
            'compliance_type' => $record->compliance_type,
            'category' => optional($record->category)->name,
            'warehouse' => optional($record->warehouse)->name,
            'responsible' => optional($record->responsibleUser)->name,
            'issuing_authority' => $record->issuing_authority,
            'expiry_date' => $record->expiry_date,
            'days_remaining' => $record->days_remaining,
            'days_remaining_label' => $record->days_remaining_label,
            'status' => $record->status,
            'priority' => $record->priority,
        ];
    }

    private function presentDetail(ComplianceRecord $record): array
    {
        return array_merge($record->toArray(), [
            'photo_url' => $record->photo ? Storage::disk('assets')->url($record->photo) : null,
            'days_remaining' => $record->days_remaining,
            'days_remaining_label' => $record->days_remaining_label,
            'category' => $record->category,
            'warehouse' => $record->warehouse,
            'supplier' => $record->supplier,
            'contact' => $record->contact,
            'asset' => $record->asset,
            'responsible_user' => $record->responsibleUser,
            'escalation_recipient' => $record->escalationRecipient,
            'renewal_history' => $record->relationLoaded('renewalHistory') ? $record->renewalHistory : [],
            'document_versions' => $record->relationLoaded('documentVersions') ? $record->documentVersions : [],
            'reminder_log' => $record->relationLoaded('reminderLog') ? $record->reminderLog : [],
            'history' => $record->relationLoaded('history') ? $record->history : [],
            'attachments' => $record->relationLoaded('attachments') ? $record->attachments->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'group' => $a->group,
                'filesize' => $a->filesize,
                'is_image' => in_array(strtolower($a->filetype ?? ''), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true),
                'preview_url' => route('compliance-records.attachments.download', [$record->id, $a->id]) . '?disposition=inline',
                'download_url' => route('compliance-records.attachments.download', [$record->id, $a->id]),
                'delete_url' => route('compliance-records.attachments.destroy', [$record->id, $a->id]),
            ]) : [],
        ]);
    }

    private function loadForPrint(ComplianceRecord $record): void
    {
        $record->load(['category', 'warehouse', 'supplier', 'asset', 'responsibleUser', 'renewalHistory', 'documentVersions.attachment', 'history.user']);
    }

    private function printData(ComplianceRecord $record): array
    {
        return [
            'record' => $record,
            'companyName' => optional(auth()->user())->account->name ?? 'RANS Company Limited',
            'qr' => \App\Support\PrintQrCode::dataUri(route('compliance-records.show', $record->id)),
        ];
    }

    private function listPayload(Request $request): array
    {
        $records = ComplianceRecord::query()->with(['category', 'warehouse', 'responsibleUser'])->filter($request->query())->orderBy('compliance_number')->limit(5000)->get();

        return [
            'title' => __('Compliance Register'),
            'companyName' => optional(auth()->user())->account->name ?? 'RANS Company Limited',
            'generatedBy' => optional(auth()->user())->name,
            'generatedAt' => now(),
            'records' => $records,
        ];
    }
}
