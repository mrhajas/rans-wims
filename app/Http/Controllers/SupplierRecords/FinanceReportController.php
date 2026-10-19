<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupplierRecords\Concerns\HasOwnModulePermission;
use App\Http\Controllers\SupplierRecords\Concerns\SharedLookups;
use App\Models\Payment;
use App\Models\SupplierInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Supplier Finance & Reconciliation reports — payable aging per supplier,
 * and an invoice-vs-payment reconciliation view, both filterable by
 * warehouse/supplier/date range.
 */
class FinanceReportController extends Controller
{
    use SharedLookups, HasOwnModulePermission;

    protected function modulePermissionSlug(): string
    {
        return 'supplier-reports';
    }

    public function index(Request $request)
    {
        $filters = $request->all('warehouse_id', 'supplier_id', 'date_from', 'date_to');

        $invoices = SupplierInvoice::query()
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', (int) $id))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', (int) $id))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('invoice_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('invoice_date', '<=', $d))
            ->get();

        $payments = Payment::query()
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', (int) $id))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('paid_on', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('paid_on', '<=', $d))
            ->with('allocations')
            ->get();

        return Inertia::render('SupplierRecords/Reports/Index', [
            'filters' => $filters,
            'warehouses' => $this->warehouseOptions(),
            'suppliers' => $this->supplierOptions(),
            'summary' => [
                'total_invoiced' => (float) $invoices->sum('total'),
                'total_paid' => (float) $invoices->sum('amount_paid'),
                'total_outstanding' => (float) $invoices->sum(fn ($i) => $i->total - $i->amount_paid),
                'invoice_count' => $invoices->count(),
                'payment_count' => $payments->count(),
            ],
            'aging' => $this->agingBySupplier($invoices),
            'reconciliation' => $this->reconciliation($invoices, $payments),
        ]);
    }

    private function agingBySupplier($invoices): array
    {
        $today = Carbon::today();

        return $invoices
            ->filter(fn ($i) => $i->total - $i->amount_paid > 0.01)
            ->groupBy('supplier_id')
            ->map(function ($rows, $supplierId) use ($today) {
                $buckets = ['current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0];

                foreach ($rows as $i) {
                    $balance = $i->total - $i->amount_paid;
                    $daysOverdue = $today->diffInDays(Carbon::parse($i->due_date), false) * -1;

                    $bucket = match (true) {
                        $daysOverdue <= 0 => 'current',
                        $daysOverdue <= 30 => 'd1_30',
                        $daysOverdue <= 60 => 'd31_60',
                        $daysOverdue <= 90 => 'd61_90',
                        default => 'd90_plus',
                    };
                    $buckets[$bucket] += $balance;
                }

                return array_merge(['supplier_id' => (int) $supplierId, 'supplier_name' => $this->supplierName((int) $supplierId)], $buckets, [
                    'total' => array_sum($buckets),
                ]);
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    private function reconciliation($invoices, $payments): array
    {
        return $invoices->map(function ($invoice) use ($payments) {
            $allocatedFromPayments = $payments
                ->flatMap(fn ($p) => $p->allocations)
                ->where('supplier_invoice_id', $invoice->id)
                ->sum('amount');

            $variance = round($invoice->amount_paid - $allocatedFromPayments, 2);

            return [
                'id' => $invoice->id,
                'reference' => $invoice->reference,
                'supplier_id' => $invoice->supplier_id,
                'supplier_name' => $this->supplierName($invoice->supplier_id),
                'invoice_date' => $invoice->invoice_date,
                'total' => $invoice->total,
                'amount_paid' => $invoice->amount_paid,
                'allocated_from_payments' => $allocatedFromPayments,
                'variance' => $variance,
                'status' => $invoice->status,
                'reconciled' => abs($variance) < 0.01,
            ];
        })->values()->all();
    }
}
