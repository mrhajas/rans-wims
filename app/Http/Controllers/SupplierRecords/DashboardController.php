<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupplierRecords\Concerns\SharedLookups;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\PaymentRequest;
use App\Support\SupplierRecords\Ledger;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    use SharedLookups;

    public function index()
    {
        $openPoStatuses = [
            PurchaseOrder::STATUS_SUBMITTED, PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_SENT,
            PurchaseOrder::STATUS_ACKNOWLEDGED, PurchaseOrder::STATUS_PARTIALLY_DELIVERED,
        ];

        $openPos = PurchaseOrder::with(['supplier:id,name', 'warehouse:id,name'])->whereIn('status', $openPoStatuses)->orderByDesc('date_issued')->get();

        $outstandingInvoiceStatuses = [
            SupplierInvoice::STATUS_PENDING_MATCH, SupplierInvoice::STATUS_MATCHED,
            SupplierInvoice::STATUS_APPROVED, SupplierInvoice::STATUS_PARTIALLY_PAID,
        ];
        $totalPayable = SupplierInvoice::whereIn('status', $outstandingInvoiceStatuses)->get()->sum(fn ($i) => $i->total - $i->amount_paid);

        $pendingPaymentRequests = PaymentRequest::where('status', PaymentRequest::STATUS_PENDING_APPROVAL)->count();

        $overdueInvoices = SupplierInvoice::whereDate('due_date', '<', Carbon::today())
            ->whereColumn('amount_paid', '<', 'total')
            ->count();

        $paidThisMonth = Payment::whereMonth('paid_on', now()->month)->whereYear('paid_on', now()->year)->sum('amount');

        $spendBySupplier = SupplierInvoice::selectRaw('supplier_id, SUM(total) as total')
            ->groupBy('supplier_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'supplier_id' => $row->supplier_id,
                'supplier_name' => $this->supplierName($row->supplier_id),
                'total' => (float) $row->total,
            ]);

        $recentActivity = collect()
            ->concat(PurchaseOrder::latest('updated_at')->limit(8)->get()->map(fn ($r) => ['type' => 'Purchase Order', 'reference' => $r->reference, 'status' => $r->status, 'date' => $r->updated_at, 'url_name' => 'supplier-records.purchase-orders.show', 'id' => $r->id]))
            ->concat(SupplierInvoice::latest('updated_at')->limit(8)->get()->map(fn ($r) => ['type' => 'Supplier Invoice', 'reference' => $r->reference, 'status' => $r->status, 'date' => $r->updated_at, 'url_name' => 'supplier-records.supplier-invoices.show', 'id' => $r->id]))
            ->concat(Payment::latest('updated_at')->limit(8)->get()->map(fn ($r) => ['type' => 'Payment', 'reference' => $r->reference, 'status' => 'Recorded', 'date' => $r->updated_at, 'url_name' => 'supplier-records.payments.show', 'id' => $r->id]))
            ->sortByDesc('date')
            ->values()
            ->take(8);

        return Inertia::render('SupplierRecords/Dashboard', [
            'cards' => [
                'active_suppliers' => Contact::where('category', 'Suppliers')->count(),
                'open_purchase_orders' => $openPos->count(),
                'outstanding_payable' => $totalPayable,
                'overdue_invoices' => $overdueInvoices,
                'pending_payment_requests' => $pendingPaymentRequests,
                'paid_this_month' => (float) $paidThisMonth,
                'suppliers_total_balance' => Ledger::totalBalance(),
            ],
            'spendBySupplier' => $spendBySupplier,
            'recentActivity' => $recentActivity,
            'openPurchaseOrders' => $openPos->map(fn ($o) => [
                'id' => $o->id,
                'reference' => $o->reference,
                'supplier_id' => $o->supplier_id,
                'supplier_name' => $o->supplier?->name,
                'warehouse_name' => $o->warehouse_id ? $o->warehouse?->name : PurchaseOrder::COMPANY_NAME,
                'total' => $o->total,
                'status' => $o->status,
            ])->values(),
        ]);
    }
}
