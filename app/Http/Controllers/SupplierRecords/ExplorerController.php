<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupplierRecords\Concerns\HasOwnModulePermission;
use App\Http\Controllers\SupplierRecords\Concerns\SharedLookups;
use App\Http\Resources\SupplierRecords\SupplierRecordsCollection;
use App\Models\GoodsReceipt;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\SupplierInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

/**
 * Transaction Explorer — a single filterable view across every Supplier
 * Records entity type, so a project/warehouse or date-range question can be
 * answered without hopping between eight separate index pages.
 */
class ExplorerController extends Controller
{
    use SharedLookups, HasOwnModulePermission;

    protected function modulePermissionSlug(): string
    {
        return 'supplier-reports';
    }

    public function index(Request $request)
    {
        $filters = $request->all('type', 'warehouse_id', 'supplier_id', 'date_from', 'date_to', 'search');

        // allRows() unions 7 separate Eloquent queries in PHP (Purchase
        // Requests through Payments have no common table to run one real SQL
        // query against), so Eloquent's own ->paginate() isn't available
        // here — this builds the same data/meta/links shape by hand,
        // slicing the already-filtered, already-sorted collection instead of
        // shipping every matching row to the browser on every visit.
        $all = $this->filteredRows($request)->values();
        $perPage = (int) (get_settings('per_page') ?? 15);
        $page = (int) $request->query('page', 1);

        $paginator = new LengthAwarePaginator(
            $all->forPage($page, $perPage)->values(),
            $all->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return Inertia::render('SupplierRecords/Explorer', [
            'filters' => $filters,
            'types' => ['Purchase Request', 'Quotation', 'Purchase Order', 'Goods Receipt', 'Supplier Invoice', 'Payment Request', 'Payment'],
            'warehouses' => $this->warehouseOptions(),
            'suppliers' => $this->supplierOptions(),
            'rows' => new SupplierRecordsCollection($paginator),
            'supplierSummary' => ($filters['supplier_id'] ?? null) ? $this->supplierSummary((int) $filters['supplier_id']) : null,
            'supplierBalances' => $this->allSupplierBalances(),
        ]);
    }

    /**
     * Every supplier's current Ledger balance, for the "select suppliers,
     * see the total" panel. Grouped by currency on the frontend (not summed
     * here) since — even though every supplier is TZS today — a raw sum
     * across differing currencies would silently be meaningless.
     */
    private function allSupplierBalances(): array
    {
        return \App\Models\Contact::where('category', 'Suppliers')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'currency' => \App\Support\SupplierRecords\SupplierDetails::get($c)['currency'] ?? 'TZS',
                'balance' => \App\Support\SupplierRecords\Ledger::balance($c->id),
            ])
            ->values()
            ->all();
    }

    /**
     * "Amount Paid" is actual cash out (Payment-type ledger entries).
     * "Amount Credited" is everything else that reduced the balance without
     * a cash payment — credit notes, discounts, retention, withholding tax,
     * and manual credit adjustments — kept separate so a large credit note
     * doesn't get mistaken for money actually paid.
     */
    private function supplierSummary(int $supplierId): array
    {
        $contact = \App\Models\Contact::find($supplierId);
        $sums = \App\Models\SupplierLedgerEntry::forSupplier($supplierId)
            ->selectRaw('type, SUM(amount) as total')->groupBy('type')->pluck('total', 'type');

        $creditTypes = ['credit_note', 'discount', 'retention', 'withholding_tax', 'credit_adjustment'];

        return [
            'supplier_name' => $contact?->name,
            'currency' => $contact ? \App\Support\SupplierRecords\SupplierDetails::get($contact)['currency'] : null,
            'paid' => (float) ($sums['payment'] ?? 0),
            'credited' => round(collect($creditTypes)->sum(fn ($t) => (float) ($sums[$t] ?? 0)), 2),
            'balance' => \App\Support\SupplierRecords\Ledger::balance($supplierId),
        ];
    }

    public function print(Request $request)
    {
        return view('reports.pdf', $this->reportData($request, false));
    }

    public function pdf(Request $request)
    {
        return Pdf::loadView('reports.pdf', $this->reportData($request, true))
            ->download('supplier-records-explorer-' . now()->format('Ymd-His') . '.pdf');
    }

    /**
     * Print view for the "All Supplier Balances" panel — only the ticked
     * suppliers (comma-separated ids in ?ids=), matching whatever subset the
     * user had selected on screen. No ids param means every supplier.
     */
    public function printBalances(Request $request)
    {
        $all = collect($this->allSupplierBalances());

        // Distinguishes "no ids param at all" (nothing was ever ticked out —
        // print everyone) from "ids param present but empty" (everything was
        // explicitly deselected — print nothing selected), rather than
        // treating both the same.
        $selected = $all;
        if ($request->has('ids')) {
            $ids = array_map('intval', array_filter(explode(',', (string) $request->query('ids'))));
            $selected = $all->whereIn('id', $ids)->values();
        }

        $totalsFor = fn ($rows) => $rows->groupBy('currency')
            ->map(fn ($group) => round($group->sum('balance'), 2))
            ->map(fn ($v, $c) => $c . ' ' . number_format($v, 2))
            ->values()->implode(', ');

        return view('reports.supplier-balances-pdf', [
            'title' => 'All Supplier Balances',
            'companyName' => \App\Models\PurchaseOrder::COMPANY_NAME,
            'suppliers' => $selected,
            'selectedTotal' => $totalsFor($selected),
            'allTotal' => $totalsFor($all),
            'selectedCount' => $selected->count(),
            'allCount' => $all->count(),
            'isPartialSelection' => $selected->count() !== $all->count(),
            'generatedAt' => now()->format('Y-m-d H:i'),
            'generatedBy' => optional($request->user())->name ?? 'N/A',
        ]);
    }

    private function reportData(Request $request, bool $forDownload): array
    {
        $rows = $this->filteredRows($request);

        return [
            'title' => 'Supplier Records - Transaction Explorer',
            'companyName' => \App\Models\PurchaseOrder::COMPANY_NAME,
            'columns' => ['Type', 'Reference', 'Supplier', 'Project', 'Date', 'Amount', 'Status'],
            'rows' => $rows->map(fn ($r) => [
                $r['type'], $r['reference'], $r['supplier_name'] ?? '-', $r['warehouse_name'] ?? '-',
                $r['date'], $r['amount'] !== null ? number_format((float) $r['amount'], 2) : '-', $r['status'],
            ])->all(),
            'meta' => array_filter([
                'Type' => $request->query('type'),
                'Supplier' => $request->query('supplier_id') ? $this->supplierName((int) $request->query('supplier_id')) : null,
                'Date From' => $request->query('date_from'),
                'Date To' => $request->query('date_to'),
                'Records' => $rows->count(),
            ]),
            'autoPrint' => ! $forDownload,
        ];
    }

    private function filteredRows(Request $request)
    {
        $filters = $request->all('type', 'warehouse_id', 'supplier_id', 'date_from', 'date_to', 'search');

        return collect($this->allRows())
            ->when($filters['type'] ?? null, fn ($c, $t) => $c->where('type', $t))
            ->when($filters['warehouse_id'] ?? null, fn ($c, $id) => $c->where('warehouse_id', (int) $id))
            ->when($filters['supplier_id'] ?? null, fn ($c, $id) => $c->where('supplier_id', (int) $id))
            ->when($filters['date_from'] ?? null, fn ($c, $d) => $c->filter(fn ($r) => $r['date'] >= $d))
            ->when($filters['date_to'] ?? null, fn ($c, $d) => $c->filter(fn ($r) => $r['date'] <= $d))
            ->when($filters['search'] ?? null, fn ($c, $s) => $c->filter(fn ($r) => str_contains(strtolower($r['reference']), strtolower($s))))
            ->sortByDesc('date');
    }

    private function allRows(): array
    {
        $sn = fn ($id) => $this->supplierName($id);

        $purchaseRequests = PurchaseRequest::with('warehouse:id,name')->get()->map(fn ($r) => [
            'type' => 'Purchase Request', 'id' => $r->id, 'reference' => $r->reference,
            'supplier_id' => null, 'supplier_name' => null,
            'warehouse_id' => $r->warehouse_id, 'warehouse_name' => $r->warehouse?->name,
            'date' => (string) $r->date_requested, 'amount' => null, 'currency' => null, 'status' => $r->status,
            'route' => 'supplier-records.purchase-requests.show',
        ]);

        $quotations = Quotation::with('warehouse:id,name')->get()->map(fn ($r) => [
            'type' => 'Quotation', 'id' => $r->id, 'reference' => $r->reference,
            'supplier_id' => $r->supplier_id, 'supplier_name' => $sn($r->supplier_id),
            'warehouse_id' => $r->warehouse_id, 'warehouse_name' => $r->warehouse?->name,
            'date' => (string) $r->date, 'amount' => $r->total, 'currency' => null, 'status' => $r->status,
            'route' => 'supplier-records.quotations.show',
        ]);

        $purchaseOrders = PurchaseOrder::with('warehouse:id,name')->get()->map(fn ($r) => [
            'type' => 'Purchase Order', 'id' => $r->id, 'reference' => $r->reference,
            'supplier_id' => $r->supplier_id, 'supplier_name' => $sn($r->supplier_id),
            'warehouse_id' => $r->warehouse_id, 'warehouse_name' => $r->warehouse_id ? $r->warehouse?->name : PurchaseOrder::COMPANY_NAME,
            'date' => (string) $r->date_issued, 'amount' => $r->total, 'currency' => $r->currency, 'status' => $r->status,
            'route' => 'supplier-records.purchase-orders.show',
        ]);

        $goodsReceipts = GoodsReceipt::with(['warehouse:id,name', 'purchaseOrder:id,supplier_id'])->get()->map(fn ($r) => [
            'type' => 'Goods Receipt', 'id' => $r->id, 'reference' => $r->reference,
            'supplier_id' => $r->purchaseOrder?->supplier_id, 'supplier_name' => $sn($r->purchaseOrder?->supplier_id),
            'warehouse_id' => $r->warehouse_id, 'warehouse_name' => $r->warehouse?->name,
            'date' => (string) $r->date_received, 'amount' => null, 'currency' => null, 'status' => $r->status,
            'route' => 'supplier-records.goods-receipts.show',
        ]);

        $invoices = SupplierInvoice::with(['warehouse:id,name', 'purchaseOrder:id,currency'])->get()->map(fn ($r) => [
            'type' => 'Supplier Invoice', 'id' => $r->id, 'reference' => $r->reference,
            'supplier_id' => $r->supplier_id, 'supplier_name' => $sn($r->supplier_id),
            'warehouse_id' => $r->warehouse_id, 'warehouse_name' => $r->warehouse?->name,
            'date' => (string) $r->invoice_date, 'amount' => $r->total, 'currency' => $r->purchaseOrder?->currency, 'status' => $r->status,
            'route' => 'supplier-records.supplier-invoices.show',
        ]);

        $paymentRequests = PaymentRequest::with('warehouse:id,name')->get()->map(fn ($r) => [
            'type' => 'Payment Request', 'id' => $r->id, 'reference' => $r->reference,
            'supplier_id' => $r->supplier_id, 'supplier_name' => $sn($r->supplier_id),
            'warehouse_id' => $r->warehouse_id, 'warehouse_name' => $r->warehouse?->name,
            'date' => (string) $r->date_requested, 'amount' => $r->amount, 'currency' => null, 'status' => $r->status,
            'route' => 'supplier-records.payment-requests.show',
        ]);

        $payments = Payment::all()->map(fn ($r) => [
            'type' => 'Payment', 'id' => $r->id, 'reference' => $r->reference,
            'supplier_id' => $r->supplier_id, 'supplier_name' => $sn($r->supplier_id),
            'warehouse_id' => null, 'warehouse_name' => null,
            'date' => (string) $r->paid_on, 'amount' => $r->amount, 'currency' => $r->currency, 'status' => 'Recorded',
            'route' => 'supplier-records.payments.show',
        ]);

        return $purchaseRequests->concat($quotations)->concat($purchaseOrders)->concat($goodsReceipts)
            ->concat($invoices)->concat($paymentRequests)->concat($payments)->all();
    }
}
