<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ProjectCertificateContract;
use App\Models\ProjectCertificateContractAddendum;
use App\Models\ProjectCertificateContractAddendumItem;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

/**
 * Contract Addendums — itemized variations recorded against a project's
 * Contract Setup (see ProjectCertificateContractController), each with its
 * own approval trail and total. Deliberately additive: nothing here touches
 * the existing original_contract_sum/contract_variation_amount/VAT fields —
 * "Total Addendum Sum" and "Total Project Amount" are a parallel summary,
 * not a replacement for the Revised Contract Sum block already on the page.
 */
class ProjectCertificateContractAddendumController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function create(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user()->can('manage-contract-project-certificates'), 403);

        $contract = ProjectCertificateContract::where('warehouse_id', $warehouse->id)->firstOrFail();

        return Inertia::render('ProjectCertificates/AddendumForm', [
            'warehouse' => $warehouse->only(['id', 'name', 'code']),
            'contract' => $this->contractSummary($contract),
            'addendum' => null,
            'canEdit' => true,
            'canApprove' => $request->user()->can('approve-addendums-project-certificates'),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'code']),
            'clientContacts' => Contact::where('category', 'Clients')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Warehouse $warehouse, ProjectCertificateContractAddendum $addendum)
    {
        abort_unless($request->user()->can('view-project-certificates'), 403);
        $this->authorizeOwnership($warehouse, $addendum);

        $addendum->load(['items', 'attachments', 'createdBy:id,name', 'approvedBy:id,name', 'contract:id,currency,original_contract_sum,revised_contract_sum,warehouse_id']);

        return Inertia::render('ProjectCertificates/AddendumForm', [
            'warehouse' => $warehouse->only(['id', 'name', 'code']),
            'contract' => $this->contractSummary($addendum->contract),
            'addendum' => $this->addendumPayload($addendum, $warehouse),
            'canEdit' => $request->user()->can('manage-contract-project-certificates'),
            'canApprove' => $request->user()->can('approve-addendums-project-certificates'),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'code']),
            'clientContacts' => Contact::where('category', 'Clients')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user()->can('manage-contract-project-certificates'), 403);

        $contract = ProjectCertificateContract::where('warehouse_id', $warehouse->id)->firstOrFail();
        $data = $this->validateAddendum($request);

        $addendum = DB::transaction(function () use ($contract, $data, $request) {
            // Sequential per contract, matching CertificateNumbering's own
            // MAX+1-inside-a-transaction pattern — the unique(contract_id,
            // addendum_number) constraint is the real guard against a race,
            // this just makes a collision unlikely in the first place.
            // withTrashed() — the unique(contract_id, addendum_number)
            // index (see the migration) applies to soft-deleted rows too,
            // so excluding them from max() (the default query scope) would
            // let a fresh addendum collide with a deleted one's number and
            // 500 on the INSERT the moment any addendum is ever deleted.
            $nextNumber = (int) ProjectCertificateContractAddendum::withTrashed()->where('contract_id', $contract->id)->lockForUpdate()->max('addendum_number') + 1;

            $addendum = ProjectCertificateContractAddendum::create(array_merge($data['addendum'], [
                'contract_id' => $contract->id,
                'addendum_number' => $nextNumber,
                'created_by' => $request->user()->id,
            ]));

            $this->syncItems($addendum, $data['items']);

            return $addendum;
        });

        if ($request->hasFile('attachments')) {
            $addendum->saveAttachments($request->file('attachments'), 'addendum');
        }

        log_activity(
            'Project Contract Addendum Created',
            ['warehouse' => $warehouse->name, 'addendum_number' => $addendum->addendum_number, 'title' => $addendum->title, 'total_amount' => $addendum->total_amount],
            $addendum,
            'Project Certificate'
        );

        \App\Services\CertificateNotifier::contract('addendum_created', $warehouse, $addendum, $request->user(), __('Contract Addendum Created'), $warehouse->name . ': addendum ' . $addendum->addendum_number . ' was created by ' . $request->user()->name . '.');

        return redirect()->route('project-certificates.contract.addendums.show', [$warehouse->id, $addendum->id])->with('message', __('Addendum created.'));
    }

    public function update(Request $request, Warehouse $warehouse, ProjectCertificateContractAddendum $addendum)
    {
        abort_unless($request->user()->can('manage-contract-project-certificates'), 403);
        $this->authorizeOwnership($warehouse, $addendum);
        abort_unless($addendum->status === ProjectCertificateContractAddendum::STATUS_DRAFT, 422, 'Only a draft addendum can be edited, an approved or rejected addendum is locked.');

        $data = $this->validateAddendum($request);

        DB::transaction(function () use ($addendum, $data) {
            $addendum->update($data['addendum']);
            $this->syncItems($addendum, $data['items']);
        });

        if ($request->hasFile('attachments')) {
            $addendum->saveAttachments($request->file('attachments'), 'addendum');
        }

        log_activity(
            'Project Contract Addendum Updated',
            ['warehouse' => $warehouse->name, 'addendum_number' => $addendum->addendum_number, 'total_amount' => $addendum->fresh()->total_amount],
            $addendum,
            'Project Certificate'
        );

        \App\Services\CertificateNotifier::contract('addendum_updated', $warehouse, $addendum->fresh(), $request->user(), __('Contract Addendum Edited'), $warehouse->name . ': addendum ' . $addendum->addendum_number . ' was edited by ' . $request->user()->name . '.');

        return back()->with('message', __('Addendum updated.'));
    }

    public function updateStatus(Request $request, Warehouse $warehouse, ProjectCertificateContractAddendum $addendum)
    {
        abort_unless($request->user()->can('approve-addendums-project-certificates'), 403);
        $this->authorizeOwnership($warehouse, $addendum);

        $data = $request->validate([
            'status' => 'required|in:draft,pending_approval,approved,rejected',
        ]);

        // A forward-only(-ish) workflow: Draft moves to Pending Approval;
        // Pending Approval resolves to Approved/Rejected (or back to Draft
        // for correction); Approved is a hard lock — matches update()/
        // destroy()'s own "approved is locked" rule just above. Rejected can
        // be reopened for a corrected resubmission.
        $allowedTransitions = [
            ProjectCertificateContractAddendum::STATUS_DRAFT => [ProjectCertificateContractAddendum::STATUS_PENDING_APPROVAL],
            ProjectCertificateContractAddendum::STATUS_PENDING_APPROVAL => [
                ProjectCertificateContractAddendum::STATUS_APPROVED,
                ProjectCertificateContractAddendum::STATUS_REJECTED,
                ProjectCertificateContractAddendum::STATUS_DRAFT,
            ],
            ProjectCertificateContractAddendum::STATUS_REJECTED => [
                ProjectCertificateContractAddendum::STATUS_DRAFT,
                ProjectCertificateContractAddendum::STATUS_PENDING_APPROVAL,
            ],
            ProjectCertificateContractAddendum::STATUS_APPROVED => [],
        ];
        abort_unless(
            in_array($data['status'], $allowedTransitions[$addendum->status] ?? [], true),
            422,
            "This addendum cannot move from \"{$addendum->status}\" to \"{$data['status']}\"."
        );

        // Signatories: when the document setup requires a signature and blocks finalising, refuse to approve without it (nothing changes with the default setup).
        if ($data['status'] === ProjectCertificateContractAddendum::STATUS_APPROVED
            && ($blocked = \App\Services\Signatories\Documents\Support\RefusesWithoutSignatures::message('contract_addendum', app(\App\Services\Signatories\Documents\ContractAddendumDocument::class)->asIfApproved($addendum, (int) $request->user()->id), __('approved')))) {
            return back()->with('error', $blocked);
        }

        $addendum->update([
            'status' => $data['status'],
            'approved_by' => $data['status'] === ProjectCertificateContractAddendum::STATUS_APPROVED ? $request->user()->id : $addendum->approved_by,
            'approved_at' => $data['status'] === ProjectCertificateContractAddendum::STATUS_APPROVED ? now() : $addendum->approved_at,
        ]);

        log_activity(
            'Project Contract Addendum Status Changed',
            ['warehouse' => $warehouse->name, 'addendum_number' => $addendum->addendum_number, 'new_status' => $data['status']],
            $addendum,
            'Project Certificate'
        );

        \App\Services\CertificateNotifier::contract('addendum_status_changed', $warehouse, $addendum->fresh(), $request->user(), __('Contract Addendum Status Changed'), $warehouse->name . ': addendum ' . $addendum->addendum_number . ' is now ' . str_replace('_', ' ', $data['status']) . ' (by ' . $request->user()->name . ').');

        return back()->with('message', __('Addendum status updated.'));
    }

    public function destroy(Request $request, Warehouse $warehouse, ProjectCertificateContractAddendum $addendum)
    {
        // create OR edit, matching every other write method here (and the
        // `canEdit` prop AddendumForm.vue gates its Delete button on) — this
        // used to require edit alone, so a create-only user could see and
        // click the button and get a 403.
        abort_unless($request->user()->can('manage-contract-project-certificates'), 403);
        $this->authorizeOwnership($warehouse, $addendum);
        abort_unless($addendum->status === ProjectCertificateContractAddendum::STATUS_DRAFT, 422, 'Only a draft addendum can be deleted, an approved or rejected addendum is part of the contract record.');

        $addendum->delete();

        log_activity(
            'Project Contract Addendum Deleted',
            ['warehouse' => $warehouse->name, 'addendum_number' => $addendum->addendum_number, 'title' => $addendum->title],
            $addendum,
            'Project Certificate'
        );

        \App\Services\CertificateNotifier::contract('addendum_deleted', $warehouse, $addendum, $request->user(), __('Contract Addendum Deleted'), $warehouse->name . ': addendum ' . $addendum->addendum_number . ' was deleted by ' . $request->user()->name . '.');

        return redirect()->route('project-certificates.project.index', $warehouse->id)->with('message', __('Addendum deleted.'));
    }

    public function print(Request $request, Warehouse $warehouse, ProjectCertificateContractAddendum $addendum)
    {
        abort_unless($request->user()->can('export-project-certificates'), 403);
        $this->authorizeOwnership($warehouse, $addendum);

        $data = $this->pdfPayload($request, $warehouse, $addendum);
        log_activity('Project Contract Addendum Printed', ['warehouse' => $warehouse->name, 'addendum_number' => $addendum->addendum_number, 'title' => $addendum->title], $addendum, 'Project Certificate');

        return view('reports.project-certificate-addendum', $data + ['autoPrint' => true]);
    }

    public function download(Request $request, Warehouse $warehouse, ProjectCertificateContractAddendum $addendum)
    {
        abort_unless($request->user()->can('export-project-certificates'), 403);
        $this->authorizeOwnership($warehouse, $addendum);

        $data = $this->pdfPayload($request, $warehouse, $addendum);
        log_activity('Project Contract Addendum Downloaded', ['warehouse' => $warehouse->name, 'addendum_number' => $addendum->addendum_number, 'title' => $addendum->title], $addendum, 'Project Certificate');

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.project-certificate-addendum', $data)
            ->setPaper('a4', 'portrait')
            ->download('addendum-' . $warehouse->code . '-' . $addendum->addendum_number . '.pdf');
    }

    protected function pdfPayload(Request $request, Warehouse $warehouse, ProjectCertificateContractAddendum $addendum): array
    {
        $addendum->load(['items', 'contract.client:id,name', 'contract.consultant:id,name', 'createdBy:id,name', 'approvedBy:id,name']);

        return [
            'warehouse' => $warehouse->only(['id', 'name', 'code']),
            'contract' => $addendum->contract,
            'addendum' => $addendum,
            'items' => $addendum->items,
            'companyName' => optional($request->user()->account)->name,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('contract_addendum', $addendum),
        ];
    }

    public function downloadAttachment(Request $request, Warehouse $warehouse, ProjectCertificateContractAddendum $addendum, Attachment $attachment)
    {
        abort_unless($request->user()->can('view-project-certificates'), 403);
        $this->authorizeOwnership($warehouse, $addendum);
        abort_unless((int) $attachment->model_id === $addendum->id && $attachment->model_type === ProjectCertificateContractAddendum::class, 404);

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }

    public function destroyAttachment(Request $request, Warehouse $warehouse, ProjectCertificateContractAddendum $addendum, Attachment $attachment)
    {
        abort_unless($request->user()->can('manage-contract-project-certificates'), 403);
        $this->authorizeOwnership($warehouse, $addendum);
        abort_unless((int) $attachment->model_id === $addendum->id && $attachment->model_type === ProjectCertificateContractAddendum::class, 404);

        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        log_activity('Addendum attachment removed.', ['warehouse' => $warehouse->name, 'filename' => $attachment->filename], $addendum, 'Project Certificate');

        return back()->with('message', __('Attachment removed.'));
    }

    /**
     * The QS adding/reviewing an addendum should see the CURRENT contract
     * value (revised sum + already-approved addendums), not just the
     * pre-variation original — mirrors ProjectCertificateContractController
     * ::edit()'s own addendumStats computation exactly.
     */
    private function contractSummary(ProjectCertificateContract $contract): array
    {
        $approvedSum = round((float) $contract->addendums()->where('status', ProjectCertificateContractAddendum::STATUS_APPROVED)->sum('total_amount'), 2);
        $revisedSum = (float) $contract->revised_contract_sum;

        return array_merge($contract->only(['id', 'currency', 'original_contract_sum', 'revised_contract_sum']), [
            'total_addendum_sum' => $approvedSum,
            'total_project_amount' => round($revisedSum + $approvedSum, 2),
        ]);
    }

    private function authorizeOwnership(Warehouse $warehouse, ProjectCertificateContractAddendum $addendum): void
    {
        abort_unless($addendum->contract->warehouse_id === $warehouse->id, 404);
    }

    private function validateAddendum(Request $request): array
    {
        $validated = $request->validate([
            'title' => 'required|string|max:190',
            'reason' => 'nullable|string|max:2000',
            'addendum_date' => 'nullable|date',
            'reference_number' => 'nullable|string|max:190',
            'effective_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'signed_date' => 'nullable|date',
            'signed_by' => 'nullable|string|max:190',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.length' => 'nullable|numeric|min:0',
            'items.*.width' => 'nullable|numeric|min:0',
            'items.*.height' => 'nullable|numeric|min:0',
            'items.*.unit' => 'nullable|string|max:30',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit_rate' => 'required|numeric|min:0',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:10240|mimes:' . attachment_exts('jpg,jpeg,png,pdf,doc,docx,xlsx'),
        ]);

        return [
            'addendum' => collect($validated)->only([
                'title', 'reason', 'addendum_date', 'reference_number', 'effective_date', 'notes', 'signed_date', 'signed_by',
            ])->all(),
            'items' => $validated['items'],
        ];
    }

    /**
     * Item amounts are never trusted from the client — recomputed here from
     * quantity * unit_rate (quantity itself may have been derived from
     * dimensions client-side, but the number that lands is what's used),
     * same "recompute server-side" rule VAT/payments already follow. Full
     * replace-all-items-per-save rather than diffing, since a whole
     * addendum's item list is small and always submitted in full anyway.
     */
    private function syncItems(ProjectCertificateContractAddendum $addendum, array $items): void
    {
        $addendum->items()->delete();

        foreach (array_values($items) as $index => $item) {
            $quantity = round((float) $item['quantity'], 3);
            $unitRate = round((float) $item['unit_rate'], 2);

            ProjectCertificateContractAddendumItem::create([
                'addendum_id' => $addendum->id,
                'description' => $item['description'],
                'length' => $item['length'] ?? null,
                'width' => $item['width'] ?? null,
                'height' => $item['height'] ?? null,
                'unit' => $item['unit'] ?? null,
                'quantity' => $quantity,
                'unit_rate' => $unitRate,
                'amount' => round($quantity * $unitRate, 2),
                'sort_order' => $index,
            ]);
        }

        $addendum->recalculateTotal();
    }

    private function addendumPayload(ProjectCertificateContractAddendum $addendum, Warehouse $warehouse): array
    {
        $data = $addendum->toArray();

        // toArray()'s default date-cast serialization is a full UTC ISO
        // datetime, which an <input type="date"> silently rejects (leaving
        // the stale UTC-shifted string sitting in the form's JS state) and
        // can then resave a day early — see ProjectCertificateContractController
        // ::edit()'s own comment for the full mechanism. Reformatted here
        // so the frontend only ever sees a plain local Y-m-d.
        foreach (['addendum_date', 'effective_date', 'signed_date'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = $addendum->$field->toDateString();
            }
        }

        return array_merge($data, [
            'items' => $addendum->items->values(),
            'created_by_name' => $addendum->createdBy?->name,
            'approved_by_name' => $addendum->approvedBy?->name,
            'attachments' => $addendum->attachments->map(fn (Attachment $a) => [
                'id' => $a->id,
                'name' => $a->title ?: $a->filename,
                'download_url' => route('project-certificates.contract.addendums.attachments.download', [$warehouse->id, $addendum->id, $a->id]),
                'delete_url' => route('project-certificates.contract.addendums.attachments.destroy', [$warehouse->id, $addendum->id, $a->id]),
            ])->values(),
        ]);
    }
}
