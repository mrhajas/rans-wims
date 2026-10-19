<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupplierRecords\Concerns\SharedLookups;
use App\Models\Contact;
use App\Models\GoodsReceipt;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\SupplierInvoice;
use App\Models\SupplierReconciliation;
use App\Support\SupplierRecords\Ledger;
use App\Support\SupplierRecords\SupplierDetails;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Suppliers are real Contact records (category "Suppliers") managed in the
 * Contacts module — there is no Supplier list/create/delete here anymore.
 * This controller only adds what Contacts doesn't have: a supplier profile
 * view (balance + transaction timeline) and the financial/bank detail
 * fields, stored in Contact::extra_attributes (see SupplierDetails).
 */
class SupplierController extends Controller
{
    use SharedLookups;

    public function show($id)
    {
        $contact = Contact::where('category', 'Suppliers')->findOrFail($id);

        $quotations = Quotation::where('supplier_id', $contact->id)->orderByDesc('date')->get();
        $purchaseOrders = PurchaseOrder::where('supplier_id', $contact->id)->orderByDesc('date_issued')->get();
        $goodsReceipts = GoodsReceipt::whereIn('purchase_order_id', $purchaseOrders->pluck('id'))->orderByDesc('date_received')->get();
        $invoices = SupplierInvoice::where('supplier_id', $contact->id)->orderByDesc('invoice_date')->get();
        $paymentRequests = PaymentRequest::where('supplier_id', $contact->id)->orderByDesc('date_requested')->get();
        $payments = Payment::where('supplier_id', $contact->id)->orderByDesc('paid_on')->get();
        $openReconciliations = SupplierReconciliation::where('supplier_id', $contact->id)->where('status', SupplierReconciliation::STATUS_OPEN)->count();

        $timeline = collect()
            ->concat($quotations->map(fn ($r) => ['type' => 'Quotation', 'label' => $r->reference, 'status' => $r->status, 'date' => (string) $r->date, 'amount' => (float) $r->total, 'route' => 'supplier-records.quotations.show', 'id' => $r->id]))
            ->concat($purchaseOrders->map(fn ($r) => ['type' => 'Purchase Order', 'label' => $r->reference, 'status' => $r->status, 'date' => (string) $r->date_issued, 'amount' => (float) $r->total, 'route' => 'supplier-records.purchase-orders.show', 'id' => $r->id]))
            ->concat($goodsReceipts->map(fn ($r) => ['type' => 'Goods Receipt', 'label' => $r->reference, 'status' => $r->status, 'date' => (string) $r->date_received, 'amount' => null, 'route' => 'supplier-records.goods-receipts.show', 'id' => $r->id]))
            ->concat($invoices->map(fn ($r) => ['type' => 'Supplier Invoice', 'label' => $r->reference, 'status' => $r->status, 'date' => (string) $r->invoice_date, 'amount' => (float) $r->total, 'route' => 'supplier-records.supplier-invoices.show', 'id' => $r->id]))
            ->concat($paymentRequests->map(fn ($r) => ['type' => 'Payment Request', 'label' => $r->reference, 'status' => $r->status, 'date' => (string) $r->date_requested, 'amount' => (float) $r->amount, 'route' => 'supplier-records.payment-requests.show', 'id' => $r->id]))
            ->concat($payments->map(fn ($r) => ['type' => 'Payment', 'label' => $r->reference, 'status' => 'Recorded', 'date' => (string) $r->paid_on, 'amount' => (float) $r->amount, 'route' => 'supplier-records.payments.show', 'id' => $r->id]))
            ->sortByDesc('date')
            ->values();

        return Inertia::render('SupplierRecords/Supplier/Show', [
            'supplier' => array_merge($contact->only(['id', 'name', 'email', 'phone', 'details']), SupplierDetails::get($contact), [
                'balance' => $this->balanceFor($contact->id),
                // The balance sums every ledger entry regardless of date (even
                // one dated in the future), so "as of" is the latest entry
                // date actually included — not just "today" — falling back to
                // today when there are no entries yet at all.
                'balance_as_of' => \App\Models\SupplierLedgerEntry::forSupplier($contact->id)->max('entry_date') ?? now()->format('Y-m-d'),
            ]),
            'stats' => [
                'quotations' => $quotations->count(),
                'purchase_orders' => $purchaseOrders->count(),
                'open_purchase_orders' => $purchaseOrders->whereNotIn('status', ['Completed', 'Cancelled'])->count(),
                'invoices' => $invoices->count(),
                'unpaid_invoices' => $invoices->whereNotIn('status', ['Paid', 'Cancelled'])->count(),
                'payments' => $payments->count(),
                'total_paid' => (float) $payments->sum('amount'),
                'open_reconciliations' => $openReconciliations,
            ],
            'timeline' => $timeline,
        ]);
    }

    public function editDetails($id)
    {
        $this->authorize('update-supplier-records');

        $contact = Contact::where('category', 'Suppliers')->findOrFail($id);

        return Inertia::render('SupplierRecords/Supplier/DetailsForm', [
            'supplier' => array_merge($contact->only(['id', 'name']), SupplierDetails::get($contact)),
        ]);
    }

    public function updateDetails(Request $request, $id)
    {
        $this->authorize('update-supplier-records');

        $contact = Contact::where('category', 'Suppliers')->findOrFail($id);

        $data = $request->validate([
            'tax_id' => 'nullable|string|max:60',
            'tax_percentage' => 'nullable|numeric|min:0|max:100',
            'withholding_tax_percentage' => 'nullable|numeric|min:0|max:100',
            'retention_percentage' => 'nullable|numeric|min:0|max:100',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'currency' => 'nullable|string|max:10',
            'credit_limit' => 'nullable|numeric|min:0',
            // opening_balance is deliberately NOT accepted here — it can only
            // change via a verified Ledger entry (Ledger::verifyOpeningBalance()),
            // so this display-only figure can never drift from the ledger's
            // own Current Balance. See SupplierRecords/Supplier/DetailsForm.vue.
            'bank_name' => 'nullable|string|max:190',
            'bank_account' => 'nullable|string|max:60',
        ]);

        SupplierDetails::save($contact, $data);

        \App\Services\SupplierRecordsNotifier::record('supplier_details_updated', $contact->fresh(), $request->user(), 'Supplier', 'financial details updated');

        return redirect()->route('supplier-records.suppliers.show', $contact->id)->with('message', __('Supplier financial details updated.'));
    }

    private function balanceFor(int $supplierId): float
    {
        return Ledger::balance($supplierId);
    }
}
