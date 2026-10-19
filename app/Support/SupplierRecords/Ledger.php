<?php

namespace App\Support\SupplierRecords;

use App\Models\Contact;
use App\Models\GoodsReceipt;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;

/**
 * Single point of contact for writing to supplier_ledger_entries — the
 * financial master for Supplier Records (see the architecture note on
 * SupplierLedgerEntry itself). Controllers call these instead of writing
 * ledger rows directly, so the "don't duplicate an obligation when its
 * invoice arrives" rule lives in exactly one place.
 */
class Ledger
{
    /**
     * A Goods Receipt confirms a delivery with no invoice yet — the company
     * now owes the supplier for it. Upserted by purchase_order_id: calling
     * this again for the same PO (e.g. a second partial GRN) UPDATES the
     * one obligation entry to the new total rather than adding a second row.
     */
    public static function recordObligation(PurchaseOrder $po, float $amount, ?GoodsReceipt $grn = null): SupplierLedgerEntry
    {
        $existing = SupplierLedgerEntry::where('source_type', PurchaseOrder::class)
            ->where('source_id', $po->id)
            ->first();

        $attributes = [
            'supplier_id' => $po->supplier_id,
            'warehouse_id' => $po->warehouse_id,
            'entry_date' => $grn?->date_received ?? now()->toDateString(),
            'type' => SupplierLedgerEntry::TYPE_MATERIAL_OBLIGATION,
            'direction' => 'debit',
            'amount' => $amount,
            'reference' => $po->reference,
            'description' => 'Goods received against ' . $po->reference . ', no supplier invoice yet.',
            'source_type' => PurchaseOrder::class,
            'source_id' => $po->id,
            'status' => SupplierLedgerEntry::STATUS_POSTED,
            'created_by' => Auth::id(),
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return SupplierLedgerEntry::create($attributes);
    }

    /**
     * A Supplier Invoice arrives. Keyed on the invoice itself (not the PO),
     * so re-saving the same invoice updates its own row while a second,
     * separate invoice against the same PO (partial invoicing, a goodwill
     * top-up, etc.) correctly adds its own charge rather than overwriting
     * the first. The first invoice recorded against a PO absorbs — deletes
     * — that PO's outstanding material-obligation placeholder, which is
     * what stops "material obligation 10M + invoice 10M = 20M"; a later
     * invoice against the same PO finds nothing left to absorb and simply
     * adds on top, which is correct (it's a genuinely separate charge).
     */
    public static function recordInvoice(SupplierInvoice $invoice): SupplierLedgerEntry
    {
        $existing = SupplierLedgerEntry::where('source_type', SupplierInvoice::class)
            ->where('source_id', $invoice->id)
            ->first();

        $attributes = [
            'supplier_id' => $invoice->supplier_id,
            'warehouse_id' => $invoice->warehouse_id,
            'entry_date' => $invoice->invoice_date,
            'type' => SupplierLedgerEntry::TYPE_SUPPLIER_INVOICE,
            'direction' => 'debit',
            'amount' => $invoice->total,
            'reference' => $invoice->reference,
            'description' => 'Supplier invoice ' . $invoice->invoice_number . ' for ' . ($invoice->purchaseOrder?->reference ?? 'PO #' . $invoice->purchase_order_id),
            'source_type' => SupplierInvoice::class,
            'source_id' => $invoice->id,
            'status' => SupplierLedgerEntry::STATUS_POSTED,
            'created_by' => Auth::id(),
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        SupplierLedgerEntry::where('source_type', PurchaseOrder::class)
            ->where('source_id', $invoice->purchase_order_id)
            ->where('type', SupplierLedgerEntry::TYPE_MATERIAL_OBLIGATION)
            ->delete();

        return SupplierLedgerEntry::create($attributes);
    }

    /**
     * A Payment always gets its own credit entry for the FULL amount paid —
     * regardless of how much of it ended up allocated to specific invoices
     * (PaymentAllocation rows are just the "where did it go" breakdown).
     * This is what stops an overpayment's excess from silently vanishing:
     * the ledger always reflects the true cash movement even when the
     * allocation is partial or empty (advance / unallocated payment).
     */
    public static function recordPayment(Payment $payment): SupplierLedgerEntry
    {
        return SupplierLedgerEntry::create([
            'supplier_id' => $payment->supplier_id,
            'warehouse_id' => null,
            'entry_date' => $payment->paid_on,
            'type' => SupplierLedgerEntry::TYPE_PAYMENT,
            'direction' => 'credit',
            'amount' => $payment->amount,
            'reference' => $payment->reference,
            'description' => 'Payment via ' . $payment->method . ($payment->cheque_number ? ' (' . $payment->cheque_number . ')' : ''),
            'source_type' => Payment::class,
            'source_id' => $payment->id,
            'status' => SupplierLedgerEntry::STATUS_POSTED,
            'created_by' => $payment->paid_by_user_id,
        ]);
    }

    /**
     * A verified/locked opening balance is never edited in place — a
     * correction is a new debit/credit adjustment entry referencing it, so
     * history stays intact (Rule 7 / Section 11).
     */
    public static function recordOpeningBalance(Contact $supplier, array $data): SupplierLedgerEntry
    {
        $amount = (float) $data['amount'];

        $entry = SupplierLedgerEntry::create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'entry_date' => $data['effective_date'],
            'type' => SupplierLedgerEntry::TYPE_OPENING_BALANCE,
            'direction' => $amount >= 0 ? 'debit' : 'credit',
            'amount' => abs($amount),
            // Placeholder — the real, unique reference is only knowable
            // once the row has an id (see below). Opening balance
            // references are always system-generated, never user-typed,
            // so two entries can never collide on an identical value.
            'reference' => 'OB-PENDING',
            'description' => $data['description'] ?? null,
            'source_type' => null,
            'source_id' => null,
            'status' => SupplierLedgerEntry::STATUS_PENDING,
            'created_by' => Auth::id(),
            'extra_attributes' => ['source' => $data['source'] ?? null, 'notes' => $data['notes'] ?? null],
        ]);

        // Suffixed with the entry's own id so two opening-balance entries
        // recorded for the same supplier on the same day (e.g. one per
        // warehouse) never share a reference — every id is unique.
        $entry->update(['reference' => 'OB-' . $supplier->id . '-' . now()->format('Ymd') . '-' . $entry->id]);

        return $entry;
    }

    public static function verifyOpeningBalance(SupplierLedgerEntry $entry, User $user): void
    {
        $entry->update([
            'status' => SupplierLedgerEntry::STATUS_VERIFIED,
            'verified_by' => $user->id,
            'verified_on' => now(),
        ]);

        self::resyncOpeningBalance($entry->supplier);
    }

    /**
     * SupplierDetails::opening_balance (a single scalar, used only as the
     * Statement page's starting point — see StatementController) is a mirror
     * of the Ledger, not a second source of truth. A supplier can
     * legitimately have more than one Verified opening_balance entry (one
     * per project/warehouse, or a later top-up), so the mirror must be the
     * SUM of all of them — never just the one entry that happened to be
     * verified/edited/deleted most recently, which would silently clobber
     * whatever an earlier entry had already contributed.
     */
    public static function resyncOpeningBalance(Contact $supplier): void
    {
        $verified = SupplierLedgerEntry::forSupplier($supplier->id)
            ->where('type', SupplierLedgerEntry::TYPE_OPENING_BALANCE)
            ->where('status', SupplierLedgerEntry::STATUS_VERIFIED)
            ->get();

        $total = $verified->sum(fn (SupplierLedgerEntry $e) => $e->direction === 'debit' ? (float) $e->amount : -(float) $e->amount);

        SupplierDetails::save($supplier, ['opening_balance' => round($total, 2)]);
    }

    /**
     * Other Charge / Debit Adjustment / Credit Note / Discount / Retention /
     * Withholding Tax / Credit Adjustment — every manual, non-workflow-driven
     * ledger entry Finance can add directly against a supplier.
     */
    public static function recordManualEntry(Contact $supplier, array $data): SupplierLedgerEntry
    {
        return SupplierLedgerEntry::create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'entry_date' => $data['entry_date'],
            'type' => $data['type'],
            'direction' => SupplierLedgerEntry::directionFor($data['type']),
            'amount' => abs((float) $data['amount']),
            'reference' => $data['reference'],
            'description' => $data['description'] ?? null,
            'source_type' => null,
            'source_id' => null,
            'status' => SupplierLedgerEntry::STATUS_POSTED,
            'created_by' => Auth::id(),
        ]);
    }

    public static function balance(int $supplierId, ?int $warehouseId = null): float
    {
        $query = SupplierLedgerEntry::forSupplier($supplierId);
        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        $sums = $query->selectRaw("direction, SUM(amount) as total")->groupBy('direction')->pluck('total', 'direction');

        return round((float) ($sums['debit'] ?? 0) - (float) ($sums['credit'] ?? 0), 2);
    }

    /**
     * The net of every supplier's balance() added together — includes
     * opening balances (type=opening_balance is a debit like any other), so
     * a supplier with nothing but an opening balance still counts in full.
     * Summing (debit - credit) per supplier and adding those up is
     * mathematically identical to summing every debit and every credit
     * across the whole ledger directly, so this is one flat query rather
     * than looping balance() per supplier. Deliberately a flat sum across
     * every supplier regardless of currency — same simplification
     * DashboardController::index() already applies to outstanding_payable;
     * Explorer's per-supplier picker is where currency-aware totals matter.
     */
    public static function totalBalance(): float
    {
        $sums = SupplierLedgerEntry::query()
            ->selectRaw('direction, SUM(amount) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        return round((float) ($sums['debit'] ?? 0) - (float) ($sums['credit'] ?? 0), 2);
    }

    /**
     * Balance broken down per project/warehouse, plus a "Legacy /
     * Unallocated" bucket for entries with no warehouse_id (Section 9).
     */
    public static function projectBalances(int $supplierId): array
    {
        $rows = SupplierLedgerEntry::forSupplier($supplierId)
            ->selectRaw('warehouse_id, direction, SUM(amount) as total')
            ->groupBy('warehouse_id', 'direction')
            ->get()
            ->groupBy('warehouse_id');

        return $rows->map(function ($group, $warehouseId) {
            $debit = (float) ($group->firstWhere('direction', 'debit')->total ?? 0);
            $credit = (float) ($group->firstWhere('direction', 'credit')->total ?? 0);

            return [
                'warehouse_id' => $warehouseId ? (int) $warehouseId : null,
                'warehouse_name' => $warehouseId ? Warehouse::find($warehouseId)?->name : 'Legacy / Unallocated',
                'balance' => round($debit - $credit, 2),
            ];
        })->values()->all();
    }
}
