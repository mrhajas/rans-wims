<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupplierRecords\Concerns\HasOwnModulePermission;
use App\Http\Controllers\SupplierRecords\Concerns\SharedLookups;
use App\Models\Contact;
use App\Models\SupplierLedgerEntry;
use App\Support\SupplierRecords\Ledger;
use App\Support\SupplierRecords\SupplierDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

/**
 * The Supplier Ledger tab — reads/writes supplier_ledger_entries, the
 * financial master for Supplier Records (see App\Support\SupplierRecords\
 * Ledger). Purchase Orders/Invoices/Payments still live in their own
 * controllers; this only covers what has no other home: viewing the running
 * ledger, recording an Opening Balance, and manual entries (Credit Note,
 * Discount, Retention, Withholding Tax, Other Charge, Adjustments).
 */
class SupplierLedgerController extends Controller
{
    use SharedLookups, HasOwnModulePermission;

    protected function modulePermissionSlug(): string
    {
        return 'supplier-ledger';
    }

    public function show(Request $request, $supplierId)
    {
        $contact = Contact::where('category', 'Suppliers')->findOrFail($supplierId);

        $chronological = SupplierLedgerEntry::forSupplier($contact->id)
            ->filter($request->only(['warehouse_id', 'type', 'date_from', 'date_to']))
            ->with(['warehouse:id,name', 'attachments'])
            ->orderBy('entry_date')->orderBy('id')
            ->get();

        $running = 0;
        $rows = $chronological->map(function (SupplierLedgerEntry $e) use (&$running, $contact) {
            $running += $e->direction === 'debit' ? (float) $e->amount : -(float) $e->amount;

            return [
                'id' => $e->id,
                'entry_date' => (string) $e->entry_date,
                'reference' => $e->reference,
                'type' => $e->type,
                'type_label' => SupplierLedgerEntry::typeLabels()[$e->type] ?? $e->type,
                'warehouse_id' => $e->warehouse_id,
                'warehouse_name' => $e->warehouse->name ?? 'Legacy / Unallocated',
                'description' => $e->description,
                'notes' => $e->extra_attributes['notes'] ?? null,
                'debit' => $e->direction === 'debit' ? (float) $e->amount : null,
                'credit' => $e->direction === 'credit' ? (float) $e->amount : null,
                'signed_amount' => $e->direction === 'debit' ? (float) $e->amount : -(float) $e->amount,
                'status' => $e->status,
                'running_balance' => round($running, 2),
                'attachments' => $e->attachments->map(fn (Attachment $a) => [
                    'id' => $a->id,
                    'name' => $a->title ?: $a->filename,
                    'download_url' => route('supplier-records.suppliers.ledger.entries.attachments.download', [$contact->id, $e->id, $a->id]),
                    'delete_url' => route('supplier-records.suppliers.ledger.entries.attachments.destroy', [$contact->id, $e->id, $a->id]),
                ])->values(),
            ];
        })->reverse()->values();

        return Inertia::render('SupplierRecords/Supplier/Ledger', [
            'supplier' => array_merge($contact->only(['id', 'name']), SupplierDetails::get($contact)),
            'entries' => $rows,
            'projectBalances' => Ledger::projectBalances($contact->id),
            'summary' => [
                'balance' => Ledger::balance($contact->id),
                'total_debits' => round((float) $chronological->where('direction', 'debit')->sum('amount'), 2),
                'total_credits' => round((float) $chronological->where('direction', 'credit')->sum('amount'), 2),
                'pending_opening_balances' => SupplierLedgerEntry::forSupplier($contact->id)
                    ->where('type', SupplierLedgerEntry::TYPE_OPENING_BALANCE)
                    ->where('status', SupplierLedgerEntry::STATUS_PENDING)->count(),
            ],
            'warehouses' => $this->warehouseOptions(),
            'manualTypes' => collect(SupplierLedgerEntry::MANUAL_TYPES)
                ->map(fn ($t) => ['value' => $t, 'label' => SupplierLedgerEntry::typeLabels()[$t]])->values(),
            'filters' => $request->only(['warehouse_id', 'type', 'date_from', 'date_to']),
        ]);
    }

    public function storeOpeningBalance(Request $request, $supplierId)
    {
        $this->authorize('create-supplier-ledger');

        $contact = Contact::where('category', 'Suppliers')->findOrFail($supplierId);

        // reference is deliberately not accepted here — it's always
        // system-generated (see Ledger::recordOpeningBalance()) so two
        // opening balance entries can never collide on a user-typed value.
        $data = $request->validate([
            'effective_date' => 'required|date',
            'amount' => 'required|numeric',
            'warehouse_id' => 'nullable|integer',
            'description' => 'nullable|string|max:1000',
            'source' => 'nullable|string|max:190',
            'notes' => 'nullable|string|max:1000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . env('ATTACHMENT_EXTS', 'jpg,png,pdf,docx,xlsx,zip'),
        ]);

        $entry = Ledger::recordOpeningBalance($contact, $data);

        if ($request->hasFile('attachments')) {
            $entry->saveAttachments($request->file('attachments'), 'opening-balance');
        }

        \App\Services\SupplierRecordsNotifier::record('opening_balance_recorded', $entry, $request->user(), 'Opening balance', 'recorded');

        return back()->with('message', __('Opening balance recorded, pending verification.'));
    }

    /**
     * Locking an Opening Balance is a director-only action, same gate as
     * verifying a Payment (Payment::VERIFICATION_VERIFIED) — once locked, it
     * normally stays fixed; updateOpeningBalance() below is the sole,
     * permission + password-gated exception for correcting a mistake.
     */
    public function verifyOpeningBalance(Request $request, $supplierId, $entryId)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403, 'Only directors can verify an opening balance.');

        $entry = SupplierLedgerEntry::where('supplier_id', $supplierId)->where('id', $entryId)
            ->where('type', SupplierLedgerEntry::TYPE_OPENING_BALANCE)->firstOrFail();
        abort_if($entry->status === SupplierLedgerEntry::STATUS_VERIFIED, 422, 'This opening balance is already verified and locked.');

        Ledger::verifyOpeningBalance($entry, $request->user());

        \App\Services\SupplierRecordsNotifier::record('opening_balance_verified', $entry->fresh(), $request->user(), 'Opening balance', 'verified');

        return back()->with('message', __('Opening balance verified and locked.'));
    }

    /**
     * Corrects an Opening Balance entry after the fact — Pending or already
     * Verified/locked. Deliberately separate from create-supplier-ledger:
     * gated on edit-supplier-ledger (Super Admin only by default, see the
     * seed migration, but reassignable from the Role/Permission matrix) and
     * the route additionally requires a fresh password confirmation
     * (Fortify's password.confirm middleware) since this can retroactively
     * change a supplier's balance.
     */
    public function updateOpeningBalance(Request $request, $supplierId, $entryId)
    {
        $this->authorize('edit-supplier-ledger');

        $entry = SupplierLedgerEntry::where('supplier_id', $supplierId)->where('id', $entryId)
            ->where('type', SupplierLedgerEntry::TYPE_OPENING_BALANCE)->firstOrFail();

        $data = $request->validate([
            'effective_date' => 'required|date',
            'amount' => 'required|numeric',
            'description' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
        ]);

        $amount = (float) $data['amount'];
        $before = ['amount' => (float) $entry->amount, 'direction' => $entry->direction];

        // reference is deliberately not accepted here — it's system-assigned
        // at creation (see Ledger::recordOpeningBalance()) and read-only on
        // the correction form; a supplier could otherwise end up with two
        // opening balance entries sharing an edited-to-match reference.
        $entry->update([
            'entry_date' => $data['effective_date'],
            'direction' => $amount >= 0 ? 'debit' : 'credit',
            'amount' => abs($amount),
            'description' => $data['description'] ?? null,
            'extra_attributes' => array_merge((array) $entry->extra_attributes, ['notes' => $data['notes'] ?? null]),
        ]);

        if ($entry->status === SupplierLedgerEntry::STATUS_VERIFIED) {
            // Resyncs from the SUM of every Verified opening_balance entry —
            // never just this one — since a supplier can have more than one
            // (see Ledger::resyncOpeningBalance()).
            Ledger::resyncOpeningBalance($entry->supplier);
        }

        log_activity(
            __('Opening balance corrected.'),
            ['before' => $before, 'after' => ['amount' => abs($amount), 'direction' => $entry->direction]],
            $entry,
            'Supplier Ledger Entry'
        );

        \App\Services\SupplierRecordsNotifier::record('opening_balance_updated', $entry->fresh(), $request->user(), 'Opening balance', 'corrected');

        return back()->with('message', __('Opening balance updated.'));
    }

    /**
     * Deletes an Opening Balance entry entirely — Super Admin only, same
     * edit-supplier-ledger permission gate + fresh password confirmation as
     * updateOpeningBalance() above ("delete... as edit button"). Soft-deletes
     * (SupplierLedgerEntry uses SoftDeletes) so it silently drops out of
     * every future Ledger::balance()/forSupplier() sum without losing the
     * audit trail.
     */
    public function destroyOpeningBalance(Request $request, $supplierId, $entryId)
    {
        $this->authorize('edit-supplier-ledger');
        abort_unless($request->user()->hasRole('Super Admin'), 403, 'Only Super Admin can delete an opening balance entry.');

        $entry = SupplierLedgerEntry::where('supplier_id', $supplierId)->where('id', $entryId)
            ->where('type', SupplierLedgerEntry::TYPE_OPENING_BALANCE)->firstOrFail();

        $wasVerified = $entry->status === SupplierLedgerEntry::STATUS_VERIFIED;
        $snapshot = $entry->only(['entry_date', 'amount', 'direction', 'reference', 'warehouse_id', 'status']);

        $entry->delete();

        if ($wasVerified) {
            // Recomputes from whatever Verified opening_balance entries
            // remain (soft-deleted rows are excluded automatically) — not a
            // flat reset to 0, since another verified entry may still exist.
            Ledger::resyncOpeningBalance($entry->supplier);
        }

        log_activity(__('Opening balance deleted.'), ['deleted' => $snapshot], $entry, 'Supplier Ledger Entry');

        \App\Services\SupplierRecordsNotifier::record('opening_balance_deleted', $entry, $request->user(), 'Opening balance', 'deleted');

        return back()->with('message', __('Opening balance entry deleted.'));
    }

    public function storeManualEntry(Request $request, $supplierId)
    {
        $this->authorize('create-supplier-ledger');

        $contact = Contact::where('category', 'Suppliers')->findOrFail($supplierId);

        $data = $request->validate([
            'type' => 'required|string|in:' . implode(',', SupplierLedgerEntry::MANUAL_TYPES),
            'entry_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'required|string|max:100',
            'warehouse_id' => 'nullable|integer',
            'description' => 'nullable|string|max:1000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . env('ATTACHMENT_EXTS', 'jpg,png,pdf,docx,xlsx,zip'),
        ]);

        $entry = Ledger::recordManualEntry($contact, $data);

        if ($request->hasFile('attachments')) {
            $entry->saveAttachments($request->file('attachments'), 'ledger-entry');
        }

        \App\Services\SupplierRecordsNotifier::record('ledger_entry_added', $entry, $request->user(), 'Ledger entry', 'recorded');

        return back()->with('message', __('Ledger entry recorded.'));
    }

    public function storeAttachment(Request $request, $supplierId, $entryId)
    {
        $this->authorize('create-supplier-ledger');

        $entry = SupplierLedgerEntry::where('supplier_id', $supplierId)->findOrFail($entryId);

        $data = $request->validate([
            'attachments' => 'required|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . env('ATTACHMENT_EXTS', 'jpg,png,pdf,docx,xlsx,zip'),
        ]);

        $entry->saveAttachments($request->file('attachments'), 'ledger-entry');

        return back()->with('message', __('Attachment added.'));
    }

    public function downloadAttachment(Request $request, $supplierId, $entryId, Attachment $attachment)
    {
        $this->authorize('read-supplier-ledger');
        abort_unless((int) $attachment->model_id === (int) $entryId && $attachment->model_type === SupplierLedgerEntry::class, 404);

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }

    public function destroyAttachment(Request $request, $supplierId, $entryId, Attachment $attachment)
    {
        $this->authorize('create-supplier-ledger');

        abort_unless((int) $attachment->model_id === (int) $entryId && $attachment->model_type === SupplierLedgerEntry::class, 404);

        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        return back()->with('message', __('Attachment removed.'));
    }
}
