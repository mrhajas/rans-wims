<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\SupplierInvoice;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierReconciliation;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\SupplierRecords\Ledger;
use App\Support\SupplierRecords\SupplierDetails;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds 3 real sample records per Supplier Records entity, forming one
 * coherent chain per supplier (Purchase Request -> Quotation -> Purchase
 * Order -> Goods Receipt -> Supplier Invoice -> Payment Request -> Payment)
 * so every screen has something real to review. References real
 * Contacts (category "Suppliers"), Warehouses, Items, and Users already in
 * this install — creates no new Contact/Warehouse/Item/User rows.
 *
 * Safe to re-run: truncates only the tables this seeder owns first.
 */
class SupplierRecordsSeeder extends Seeder
{
    public function run(): void
    {
        $this->wipe();

        $suppliers = Contact::where('category', 'Suppliers')->orderBy('id')->limit(3)->get();
        // Plain query, not the ofAccount()/active() scopes — those resolve
        // the current account via auth()->user(), which doesn't exist in a
        // CLI seeder context.
        $warehouses = Warehouse::whereNull('completed_at')->orderBy('id')->limit(3)->get();
        $items = Item::orderBy('id')->limit(10)->get();
        $user = User::orderBy('id')->first();

        if ($suppliers->isEmpty() || ! $user) {
            $this->command?->warn('SupplierRecordsSeeder: no Suppliers-category Contacts or Users found, skipping.');

            return;
        }

        $sup = fn (int $i) => $suppliers->get($i % $suppliers->count());
        $wh = fn (int $i) => $warehouses->count() ? $warehouses->get($i % $warehouses->count()) : null;
        $pickItem = fn (int $i) => $items->count() ? $items->get($i % $items->count()) : null;

        $line = function (int $seed, float $qty) use ($pickItem) {
            $item = $pickItem($seed);

            return [
                'item_id' => $item?->id,
                'item_name' => $item->name ?? "Sample Material {$seed}",
                'item_code' => $item->code ?? ('MAT-' . str_pad((string) $seed, 3, '0', STR_PAD_LEFT)),
                'unit_label' => $item?->unit?->code ?? $item?->unit?->name ?? 'pcs',
                'quantity' => $qty,
                'unit_price' => $item->price ?? 15000,
            ];
        };

        // purchase_request_items has no unit_price column (a request is
        // quantity-only — pricing only enters the picture at Quotation/PO).
        $prLine = fn (int $seed, float $qty, ?string $remarks = null) => array_merge(
            collect($line($seed, $qty))->except('unit_price')->all(),
            ['remarks' => $remarks]
        );

        $now = Carbon::now();

        // ---------------------------------------------------------------
        // 1) Purchase Requests
        // ---------------------------------------------------------------
        $pr1 = PurchaseRequest::create([
            'reference' => 'PR-0001', 'warehouse_id' => $wh(0)?->id, 'user_id' => $user->id,
            'status' => PurchaseRequest::STATUS_CONVERTED, 'date_requested' => $now->copy()->subDays(20),
            'reason' => 'Cement and aggregate running low ahead of foundation pour.',
            'approval_remarks' => 'Approved, matches phase 2 BOQ allowance.',
        ]);
        $pr1->items()->create($prLine(1, 200));
        $pr1->items()->create($prLine(2, 50));

        $pr2 = PurchaseRequest::create([
            'reference' => 'PR-0002', 'warehouse_id' => $wh(1)?->id, 'user_id' => $user->id,
            'status' => PurchaseRequest::STATUS_APPROVED, 'date_requested' => $now->copy()->subDays(9),
            'reason' => 'Electrical first-fix materials for block B.',
            'approval_remarks' => 'Approved.',
        ]);
        $pr2->items()->create($prLine(4, 80));

        $pr3 = PurchaseRequest::create([
            'reference' => 'PR-0003', 'warehouse_id' => $wh(2)?->id, 'user_id' => $user->id,
            'status' => PurchaseRequest::STATUS_PENDING_APPROVAL, 'date_requested' => $now->copy()->subDays(2),
            'reason' => 'Site office consumables running low.',
        ]);
        $pr3->items()->create($prLine(7, 20));

        // ---------------------------------------------------------------
        // 2) Quotations
        // ---------------------------------------------------------------
        $qt1Items = [$line(1, 200), $line(2, 50)];
        $qt1 = Quotation::create([
            'reference' => 'QT-0001', 'purchase_request_id' => $pr1->id, 'supplier_id' => $sup(0)->id,
            'warehouse_id' => $wh(0)?->id, 'date' => $now->copy()->subDays(18), 'valid_until' => $now->copy()->subDays(4),
            'status' => Quotation::STATUS_SELECTED,
            'total' => collect($qt1Items)->sum(fn ($l) => $l['quantity'] * $l['unit_price']),
            'remarks' => 'Best price on cement; matches lead time requirement.',
        ]);
        foreach ($qt1Items as $l) {
            $qt1->items()->create($l);
        }

        $qt2Items = [$line(4, 80)];
        $qt2 = Quotation::create([
            'reference' => 'QT-0002', 'purchase_request_id' => $pr2->id, 'supplier_id' => $sup(1)->id,
            'warehouse_id' => $wh(1)?->id, 'date' => $now->copy()->subDays(8), 'valid_until' => $now->copy()->addDays(6),
            'status' => Quotation::STATUS_RECEIVED,
            'total' => collect($qt2Items)->sum(fn ($l) => $l['quantity'] * $l['unit_price']),
            'remarks' => 'Awaiting a second competing quote before selection.',
        ]);
        foreach ($qt2Items as $l) {
            $qt2->items()->create($l);
        }

        $qt3Items = [$line(9, 15)];
        $qt3 = Quotation::create([
            'reference' => 'QT-0003', 'purchase_request_id' => null, 'supplier_id' => $sup(2)->id,
            'warehouse_id' => null, 'date' => $now->copy()->subDays(3), 'valid_until' => $now->copy()->addDays(10),
            'status' => Quotation::STATUS_DRAFT,
            'total' => collect($qt3Items)->sum(fn ($l) => $l['quantity'] * $l['unit_price']),
            'remarks' => 'Head-office stationery restock, not tied to a project request.',
        ]);
        foreach ($qt3Items as $l) {
            $qt3->items()->create($l);
        }

        // ---------------------------------------------------------------
        // 3) Purchase Orders
        // ---------------------------------------------------------------
        $po1Items = [$line(1, 200), $line(2, 50)];
        $subtotal1 = collect($po1Items)->sum(fn ($l) => $l['quantity'] * $l['unit_price']);
        $tax1 = round($subtotal1 * 0.18, 2);
        $po1 = PurchaseOrder::create([
            'reference' => 'PO-0001', 'supplier_id' => $sup(0)->id, 'warehouse_id' => $wh(0)?->id,
            'purchase_request_id' => $pr1->id, 'quotation_id' => $qt1->id, 'user_id' => $user->id,
            'status' => PurchaseOrder::STATUS_COMPLETED, 'date_issued' => $now->copy()->subDays(17),
            'expected_delivery_date' => $now->copy()->subDays(10),
            'currency' => 'TZS', 'tax_included' => true, 'tax_percentage' => 18,
            'subtotal' => $subtotal1, 'tax_amount' => $tax1, 'total' => $subtotal1 + $tax1,
            'terms' => '30 days net from invoice date.',
            'approval_remarks' => 'Approved.', 'submitted_on' => $now->copy()->subDays(17),
            'approved_by_user_id' => $user->id, 'approved_on' => $now->copy()->subDays(16),
            'sent_on' => $now->copy()->subDays(16), 'acknowledged_on' => $now->copy()->subDays(15),
        ]);
        foreach ($po1Items as $l) {
            $po1->items()->create(array_merge($l, ['received_quantity' => $l['quantity']]));
        }

        $po2Items = [$line(5, 30), $line(6, 15)];
        $subtotal2 = collect($po2Items)->sum(fn ($l) => $l['quantity'] * $l['unit_price']);
        $tax2 = round($subtotal2 * 0.18, 2);
        $po2 = PurchaseOrder::create([
            'reference' => 'PO-0002', 'supplier_id' => $sup(1)->id, 'warehouse_id' => $wh(1)?->id,
            'purchase_request_id' => null, 'quotation_id' => $qt2->id, 'user_id' => $user->id,
            'status' => PurchaseOrder::STATUS_PARTIALLY_DELIVERED, 'date_issued' => $now->copy()->subDays(9),
            'expected_delivery_date' => $now->copy()->addDays(2),
            'currency' => 'TZS', 'tax_included' => true, 'tax_percentage' => 18,
            'subtotal' => $subtotal2, 'tax_amount' => $tax2, 'total' => $subtotal2 + $tax2,
            'terms' => '14 days net.', 'remarks' => 'Second item delivery delayed by supplier.',
            'approval_remarks' => 'Approved.', 'submitted_on' => $now->copy()->subDays(9),
            'approved_by_user_id' => $user->id, 'approved_on' => $now->copy()->subDays(8),
            'sent_on' => $now->copy()->subDays(8), 'acknowledged_on' => $now->copy()->subDays(7),
        ]);
        $po2->items()->create(array_merge($po2Items[0], ['received_quantity' => $po2Items[0]['quantity']]));
        $po2->items()->create(array_merge($po2Items[1], ['received_quantity' => 0]));

        $po3Items = [$line(4, 80)];
        $subtotal3 = collect($po3Items)->sum(fn ($l) => $l['quantity'] * $l['unit_price']);
        $po3 = PurchaseOrder::create([
            'reference' => 'PO-0003', 'supplier_id' => $sup(2)->id, 'warehouse_id' => null,
            'purchase_request_id' => $pr2->id, 'quotation_id' => null, 'user_id' => $user->id,
            'status' => PurchaseOrder::STATUS_SUBMITTED, 'date_issued' => $now->copy()->subDays(2),
            'expected_delivery_date' => $now->copy()->addDays(9),
            'currency' => 'TZS', 'tax_included' => false, 'tax_percentage' => 0,
            'subtotal' => $subtotal3, 'tax_amount' => 0, 'total' => $subtotal3,
            'terms' => '30 days net.', 'remarks' => 'Head-office stock replenishment, not tied to a specific project.',
            'submitted_on' => $now->copy()->subDays(2),
        ]);
        foreach ($po3Items as $l) {
            $po3->items()->create(array_merge($l, ['received_quantity' => 0]));
        }

        // ---------------------------------------------------------------
        // 4) Goods Receipts
        // ---------------------------------------------------------------
        $grn1 = GoodsReceipt::create([
            'reference' => 'GRN-0001', 'purchase_order_id' => $po1->id, 'warehouse_id' => $wh(0)?->id,
            'received_by_user_id' => $user->id, 'date_received' => $now->copy()->subDays(14),
            'status' => GoodsReceipt::STATUS_CONFIRMED, 'remarks' => 'Full delivery received in good condition.',
        ]);
        foreach ($po1->items as $poItem) {
            $grn1->items()->create([
                'item_id' => $poItem->item_id, 'item_name' => $poItem->item_name, 'item_code' => $poItem->item_code,
                'unit_label' => $poItem->unit_label, 'ordered_quantity' => $poItem->quantity, 'received_quantity' => $poItem->quantity,
            ]);
        }
        Ledger::recordObligation($po1, $subtotal1 + $tax1, $grn1);

        $grn2 = GoodsReceipt::create([
            'reference' => 'GRN-0002', 'purchase_order_id' => $po2->id, 'warehouse_id' => $wh(1)?->id,
            'received_by_user_id' => $user->id, 'date_received' => $now->copy()->subDays(4),
            'status' => GoodsReceipt::STATUS_CONFIRMED, 'remarks' => 'Second line item outstanding, first line received complete.',
        ]);
        $grn2->items()->create([
            'item_id' => $po2Items[0]['item_id'], 'item_name' => $po2Items[0]['item_name'], 'item_code' => $po2Items[0]['item_code'],
            'unit_label' => $po2Items[0]['unit_label'], 'ordered_quantity' => $po2Items[0]['quantity'], 'received_quantity' => $po2Items[0]['quantity'],
        ]);
        $grn2->items()->create([
            'item_id' => $po2Items[1]['item_id'], 'item_name' => $po2Items[1]['item_name'], 'item_code' => $po2Items[1]['item_code'],
            'unit_label' => $po2Items[1]['unit_label'], 'ordered_quantity' => $po2Items[1]['quantity'], 'received_quantity' => 0,
        ]);
        $grn2Value = round($po2Items[0]['quantity'] * $po2Items[0]['unit_price'] * 1.18, 2);
        Ledger::recordObligation($po2, $grn2Value, $grn2);

        $grn3 = GoodsReceipt::create([
            'reference' => 'GRN-0003', 'purchase_order_id' => $po1->id, 'warehouse_id' => $wh(0)?->id,
            'received_by_user_id' => $user->id, 'date_received' => $now->copy()->subDays(13),
            'status' => GoodsReceipt::STATUS_CONFIRMED, 'remarks' => 'Supplier included 5 extra bags of cement as goodwill, accepted.',
        ]);
        $extraItem = $pickItem(1);
        $grn3->items()->create([
            'item_id' => $extraItem?->id, 'item_name' => $extraItem->name ?? 'Extra Material', 'item_code' => $extraItem->code ?? 'MAT-EXTRA',
            'unit_label' => $extraItem?->unit?->code ?? 'pcs', 'ordered_quantity' => 0, 'received_quantity' => 5,
        ]);
        // Extra goodwill delivery, no PO line behind it. recordObligation
        // upserts a single running total per PO (mirroring how
        // GoodsReceiptController re-sums every GRN against a PO on each
        // receipt) — so this passes po1's full cumulative received value
        // (main delivery + the goodwill top-up), not just this GRN's slice.
        Ledger::recordObligation($po1, $subtotal1 + $tax1 + 88500, $grn3);

        // ---------------------------------------------------------------
        // 5) Supplier Invoices
        // ---------------------------------------------------------------
        $inv1 = SupplierInvoice::create([
            'reference' => 'INV-0001', 'invoice_number' => 'NBM-2201', 'purchase_order_id' => $po1->id,
            'goods_receipt_id' => $grn1->id, 'supplier_id' => $sup(0)->id, 'warehouse_id' => $wh(0)?->id,
            'invoice_date' => $now->copy()->subDays(13), 'due_date' => $now->copy()->addDays(17),
            'status' => SupplierInvoice::STATUS_MATCHED, 'match_status' => 'Matched',
            'subtotal' => $subtotal1, 'tax_amount' => $tax1, 'total' => $subtotal1 + $tax1, 'amount_paid' => 0,
            'remarks' => 'Matches PO-0001 and GRN-0001 exactly.',
        ]);
        foreach ($po1Items as $l) {
            $inv1->items()->create($l);
        }
        Ledger::recordInvoice($inv1);

        $inv2 = SupplierInvoice::create([
            'reference' => 'INV-0002', 'invoice_number' => 'KER-0587', 'purchase_order_id' => $po2->id,
            'goods_receipt_id' => $grn2->id, 'supplier_id' => $sup(1)->id, 'warehouse_id' => $wh(1)?->id,
            'invoice_date' => $now->copy()->subDays(3), 'due_date' => $now->copy()->addDays(11),
            'status' => SupplierInvoice::STATUS_MATCHED, 'match_status' => 'Quantity Variance',
            'subtotal' => $subtotal2, 'tax_amount' => $tax2, 'total' => $subtotal2 + $tax2,
            'amount_paid' => 0,
            'remarks' => 'Invoiced full quantity but only the first line has been received so far.',
        ]);
        foreach ($po2Items as $l) {
            $inv2->items()->create($l);
        }
        Ledger::recordInvoice($inv2);

        $inv3 = SupplierInvoice::create([
            'reference' => 'INV-0003', 'invoice_number' => 'ABM-0912', 'purchase_order_id' => $po1->id,
            'goods_receipt_id' => $grn3->id, 'supplier_id' => $sup(0)->id, 'warehouse_id' => $wh(0)?->id,
            'invoice_date' => $now->copy()->subDays(12), 'due_date' => $now->copy()->addDays(18),
            'status' => SupplierInvoice::STATUS_PENDING_MATCH, 'match_status' => 'Unmatched',
            'subtotal' => 75000, 'tax_amount' => 13500, 'total' => 88500, 'amount_paid' => 0,
            'remarks' => 'Goodwill top-up delivery, awaiting Procurement review before matching.',
        ]);
        $inv3->items()->create(array_merge($line(1, 5), ['unit_price' => 15000]));
        Ledger::recordInvoice($inv3);

        // ---------------------------------------------------------------
        // 6) Payment Requests (2 invoice-based + 1 account-balance)
        // ---------------------------------------------------------------
        $pyr1 = PaymentRequest::create([
            'reference' => 'PYR-0001', 'type' => PaymentRequest::TYPE_INVOICE, 'supplier_id' => $sup(0)->id,
            'warehouse_id' => $wh(0)?->id, 'requested_by_user_id' => $user->id,
            'date_requested' => $now->copy()->subDays(12), 'amount' => $subtotal1 + $tax1,
            'status' => PaymentRequest::STATUS_PAID, 'approval_remarks' => 'Approved, matched invoice.',
        ]);
        $pyr1->invoices()->attach($inv1->id);

        $pyr2 = PaymentRequest::create([
            'reference' => 'PYR-0002', 'type' => PaymentRequest::TYPE_INVOICE, 'supplier_id' => $sup(1)->id,
            'warehouse_id' => $wh(1)?->id, 'requested_by_user_id' => $user->id,
            'date_requested' => $now->copy()->subDays(2), 'amount' => round(($subtotal2 + $tax2) * 0.6, 2),
            'status' => PaymentRequest::STATUS_PAID, 'approval_remarks' => 'Partial payment approved pending full delivery.',
        ]);
        $pyr2->invoices()->attach($inv2->id);

        $pyr3 = PaymentRequest::create([
            'reference' => 'PYR-0003', 'type' => PaymentRequest::TYPE_BALANCE, 'supplier_id' => $sup(0)->id,
            'warehouse_id' => null, 'requested_by_user_id' => $user->id,
            'date_requested' => $now->copy()->subDays(1), 'amount' => 88500,
            'status' => PaymentRequest::STATUS_APPROVED, 'approval_remarks' => 'Settles the goodwill top-up invoice from the supplier balance.',
        ]);

        // ---------------------------------------------------------------
        // 7) Payments (drives invoice amount_paid/status via the model's
        // own allocation helpers, exactly like the real store() flow will).
        // ---------------------------------------------------------------
        $pmt1 = Payment::create([
            'reference' => 'PMT-0001', 'payment_request_id' => $pyr1->id, 'supplier_id' => $sup(0)->id,
            'amount' => $subtotal1 + $tax1, 'method' => 'Bank Transfer', 'currency' => 'TZS',
            'reference_number' => 'CRDB-TT-88213', 'paid_on' => $now->copy()->subDays(11),
            'paid_by_user_id' => $user->id, 'remarks' => 'Paid in full.',
            'verification_status' => Payment::VERIFICATION_VERIFIED,
            'verified_by_user_id' => $user->id, 'verified_on' => $now->copy()->subDays(10),
        ]);
        $pmt1->allocateAgainstInvoice($inv1, $subtotal1 + $tax1);
        Ledger::recordPayment($pmt1);

        $pmt2Amount = round(($subtotal2 + $tax2) * 0.6, 2);
        $pmt2 = Payment::create([
            'reference' => 'PMT-0002', 'payment_request_id' => $pyr2->id, 'supplier_id' => $sup(1)->id,
            'amount' => $pmt2Amount, 'method' => 'Cheque', 'currency' => 'TZS', 'cheque_number' => 'CHQ-004821',
            'reference_number' => 'EQTY-TT-40217', 'paid_on' => $now->copy()->subDays(1),
            'paid_by_user_id' => $user->id, 'remarks' => 'Partial/short payment, balance held until full delivery confirmed.',
            'verification_status' => Payment::VERIFICATION_PENDING,
        ]);
        $pmt2->allocateAgainstInvoice($inv2, $pmt2Amount);
        Ledger::recordPayment($pmt2);

        // Balance payment: no specific invoice on the request — FIFO across
        // the supplier's outstanding invoices (here, settles INV-0003).
        $pmt3 = Payment::create([
            'reference' => 'PMT-0003', 'payment_request_id' => $pyr3->id, 'supplier_id' => $sup(0)->id,
            'amount' => 88500, 'method' => 'Mobile Money', 'currency' => 'TZS',
            'reference_number' => 'MPESA-772104', 'paid_on' => $now,
            'paid_by_user_id' => $user->id, 'remarks' => 'Account-balance settlement, not tied to a single invoice at request time.',
            'verification_status' => Payment::VERIFICATION_PENDING,
        ]);
        $pmt3->allocateAgainstBalance($sup(0)->id, 88500);
        Ledger::recordPayment($pmt3);

        // ---------------------------------------------------------------
        // 8) Supplier Ledger — Opening Balance (verified/locked) and a
        // Reconciliation, so both new screens have something to review.
        // ---------------------------------------------------------------
        $obEntry = Ledger::recordOpeningBalance($sup(2), [
            'amount' => 420000,
            'effective_date' => $now->copy()->subDays(30)->toDateString(),
            'warehouse_id' => null,
            'reference' => 'OB-' . $sup(2)->id,
            'description' => 'Balance carried over from the legacy spreadsheet at go-live.',
            'source' => 'Legacy spreadsheet handover',
            'notes' => 'Confirmed against the supplier\'s last statement before go-live.',
        ]);
        Ledger::verifyOpeningBalance($obEntry, $user);

        SupplierReconciliation::create([
            'supplier_id' => $sup(1)->id,
            'reconciliation_date' => $now->copy()->subDays(1)->toDateString(),
            'statement_date' => $now->copy()->subDays(2)->toDateString(),
            'statement_balance' => Ledger::balance($sup(1)->id) + 15000,
            'wims_balance' => Ledger::balance($sup(1)->id),
            'difference' => 15000,
            'reason' => 'Timing Difference',
            'status' => SupplierReconciliation::STATUS_OPEN,
            'notes' => 'Supplier statement includes a cheque still in transit, expected to clear next week.',
            'created_by' => $user->id,
        ]);

        $this->command?->info('SupplierRecordsSeeder: seeded 3 sample records per entity.');
    }

    private function wipe(): void
    {
        \App\Models\PaymentAllocation::query()->delete();
        SupplierLedgerEntry::withTrashed()->forceDelete();
        SupplierReconciliation::withTrashed()->forceDelete();
        Payment::withTrashed()->forceDelete();
        \DB::table('payment_request_supplier_invoice')->delete();
        PaymentRequest::withTrashed()->forceDelete();
        SupplierInvoice::withTrashed()->forceDelete();
        GoodsReceipt::withTrashed()->forceDelete();
        PurchaseOrder::withTrashed()->forceDelete();
        Quotation::withTrashed()->forceDelete();
        PurchaseRequest::withTrashed()->forceDelete();

        // Reset the opening-balance snapshot on the 3 seeded suppliers so a
        // re-run doesn't inherit a previous run's verified value.
        foreach (Contact::where('category', 'Suppliers')->orderBy('id')->limit(3)->get() as $supplier) {
            SupplierDetails::save($supplier, ['opening_balance' => 0]);
        }
    }
}
