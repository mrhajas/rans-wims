<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupplierRecords\Concerns\HasOwnModulePermission;
use App\Http\Controllers\SupplierRecords\Concerns\SharedLookups;
use App\Models\Contact;
use App\Models\SupplierReconciliation;
use App\Support\SupplierRecords\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class SupplierReconciliationController extends Controller
{
    use SharedLookups, HasOwnModulePermission;

    protected function modulePermissionSlug(): string
    {
        return 'supplier-ledger';
    }

    public function index(Request $request, $supplierId)
    {
        $contact = Contact::where('category', 'Suppliers')->findOrFail($supplierId);

        $reconciliations = SupplierReconciliation::where('supplier_id', $contact->id)
            ->with('attachments')
            ->orderByDesc('reconciliation_date')->get()
            ->map(fn (SupplierReconciliation $r) => array_merge($r->toArray(), [
                'attachments' => $r->attachments->map(fn (Attachment $a) => [
                    'id' => $a->id,
                    'name' => $a->title ?: $a->filename,
                    'download_url' => route('supplier-records.suppliers.reconciliations.attachments.download', [$contact->id, $r->id, $a->id]),
                    'delete_url' => route('supplier-records.suppliers.reconciliations.attachments.destroy', [$contact->id, $r->id, $a->id]),
                ])->values(),
            ]));

        return Inertia::render('SupplierRecords/Supplier/Reconciliations', [
            'supplier' => array_merge($contact->only(['id', 'name']), ['currency' => \App\Support\SupplierRecords\SupplierDetails::get($contact)['currency']]),
            'reconciliations' => $reconciliations,
            'reasons' => SupplierReconciliation::REASONS,
            'currentBalance' => Ledger::balance($contact->id),
        ]);
    }

    public function store(Request $request, $supplierId)
    {
        $contact = Contact::where('category', 'Suppliers')->findOrFail($supplierId);

        $data = $request->validate([
            'reconciliation_date' => 'required|date',
            'statement_date' => 'required|date',
            'statement_balance' => 'required|numeric',
            'reason' => 'nullable|string|in:' . implode(',', SupplierReconciliation::REASONS),
            'notes' => 'nullable|string|max:1000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . attachment_exts('jpg,png,pdf,docx,xlsx,zip'),
        ]);

        $wimsBalance = Ledger::balance($contact->id);

        $reconciliation = SupplierReconciliation::create([
            'supplier_id' => $contact->id,
            'reconciliation_date' => $data['reconciliation_date'],
            'statement_date' => $data['statement_date'],
            'statement_balance' => $data['statement_balance'],
            'wims_balance' => $wimsBalance,
            'difference' => round($data['statement_balance'] - $wimsBalance, 2),
            'reason' => $data['reason'] ?? null,
            'status' => SupplierReconciliation::STATUS_OPEN,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        if ($request->hasFile('attachments')) {
            $reconciliation->saveAttachments($request->file('attachments'), 'supplier-statement');
        }

        \App\Services\SupplierRecordsNotifier::record('reconciliation_created', $reconciliation, $request->user(), 'Reconciliation', 'recorded');

        return back()->with('message', __('Reconciliation recorded.'));
    }

    /**
     * Resolving/approving a reconciliation is a director-only action, same
     * gate as verifying a Payment or an Opening Balance.
     */
    public function resolve(Request $request, $supplierId, $reconciliationId)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403, 'Only directors can approve a reconciliation.');

        $data = $request->validate(['status' => 'required|in:Resolved,Disputed', 'notes' => 'nullable|string|max:1000']);

        $reconciliation = SupplierReconciliation::where('supplier_id', $supplierId)->findOrFail($reconciliationId);

        // Without this, calling resolve() again on an already-decided
        // reconciliation silently rewrites status/notes/reviewer/approver in
        // place — the "silently edit history instead of adding a new
        // correcting entry" failure mode this app avoids everywhere else
        // (e.g. Opening Balance corrections always add a new entry).
        abort_if(
            $reconciliation->status !== SupplierReconciliation::STATUS_OPEN,
            422,
            'This reconciliation has already been decided and can not be resolved again.'
        );

        $reconciliation->update([
            'status' => $data['status'],
            'notes' => $data['notes'] ?? $reconciliation->notes,
            'reviewed_by' => $request->user()->id,
            'reviewed_on' => now(),
            'approved_by' => $data['status'] === 'Resolved' ? $request->user()->id : $reconciliation->approved_by,
            'approved_on' => $data['status'] === 'Resolved' ? now() : $reconciliation->approved_on,
        ]);

        \App\Services\SupplierRecordsNotifier::record('reconciliation_resolved', $reconciliation->fresh(), $request->user(), 'Reconciliation', strtolower($data['status']), (string) ($data['notes'] ?? ''));

        return back()->with('message', __('Reconciliation updated.'));
    }

    public function storeAttachment(Request $request, $supplierId, $reconciliationId)
    {
        $this->authorize('create-supplier-ledger');

        $reconciliation = SupplierReconciliation::where('supplier_id', $supplierId)->findOrFail($reconciliationId);

        $data = $request->validate([
            'attachments' => 'required|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . attachment_exts('jpg,png,pdf,docx,xlsx,zip'),
        ]);

        $reconciliation->saveAttachments($request->file('attachments'), 'supplier-statement');

        return back()->with('message', __('Attachment added.'));
    }

    public function downloadAttachment(Request $request, $supplierId, $reconciliationId, Attachment $attachment)
    {
        $this->authorize('read-supplier-ledger');
        abort_unless((int) $attachment->model_id === (int) $reconciliationId && $attachment->model_type === SupplierReconciliation::class, 404);

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }

    public function destroyAttachment(Request $request, $supplierId, $reconciliationId, Attachment $attachment)
    {
        $this->authorize('create-supplier-ledger');

        abort_unless((int) $attachment->model_id === (int) $reconciliationId && $attachment->model_type === SupplierReconciliation::class, 404);

        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        return back()->with('message', __('Attachment removed.'));
    }
}
