<?php

namespace App\Services\Signatories\Documents;

use App\Models\SupplierInvoice;

/**
 * Supplier Invoice (Supplier Records).
 *
 * Real storage (SupplierRecords\SupplierInvoiceController):
 *  - created   created_by + created_at                                     (GOOD)
 *  - approved  approved_by_user_id + approved_on, written by the Approve quick action (ability approve-supplier-invoices). These two columns exist
 *              from the 2026-11-03 change on and are NEVER backfilled: an invoice approved before it has the status "Approved" but no stamp, and then
 *              the approval is simply absent (no person is guessed). Disputing an invoice clears the approval stamp (and writes disputed_by/_on), so
 *              a disputed invoice carries no approval; approving it again stamps the new approver.
 *
 * Status order: Pending Match < Approved < Partially Paid < Paid, and Disputed (a side branch that clears the approval). Payments move an invoice to
 * Partially Paid / Paid without any approval step (FIFO allocation of a balance payment), so an approval is reported only when the stamp exists AND
 * the status is Approved, Partially Paid or Paid. "Matched" is never written as a status by the controller (the match result lives in match_status).
 *
 * State: Pending Match, Matched and Disputed = in progress; Approved, Partially Paid and Paid = final (the approval is the last signing step).
 */
final class SupplierInvoiceDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'supplier_invoice';
    }

    public static function label(): string
    {
        return 'Supplier Invoice';
    }

    public static function actions(): array
    {
        return ['created' => 'Prepared (invoice recorded)', 'approved' => 'Approved'];
    }

    public function find(int $id): ?object
    {
        return SupplierInvoice::query()->find($id);
    }

    public function state(object $record): string
    {
        return in_array($record->status, [SupplierInvoice::STATUS_APPROVED, SupplierInvoice::STATUS_PARTIALLY_PAID, SupplierInvoice::STATUS_PAID], true) ? 'final' : 'in_progress';
    }

    public function history(object $record): array
    {
        $out = ['created' => $this->entry($record->created_by, $record->created_at, 'The person who recorded the invoice is not recorded.')];

        $standing = in_array($record->status, [SupplierInvoice::STATUS_APPROVED, SupplierInvoice::STATUS_PARTIALLY_PAID, SupplierInvoice::STATUS_PAID], true);
        if ($standing && ($record->approved_by_user_id || $record->approved_on)) {
            $out['approved'] = $this->entry($record->approved_by_user_id, $record->approved_on, 'The approval is not fully recorded on this invoice.');
        }

        return $out;
    }

    private function entry($userId, $at, string $note): array
    {
        $at = StockSigningGuard::at($at);
        $ok = $userId && $at;

        return ['user_id' => $userId ? (int) $userId : null, 'at' => $at, 'name' => null, 'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : $note];
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'prepared_by', 'action' => 'created', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
