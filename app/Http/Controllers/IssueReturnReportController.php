<?php

namespace App\Http\Controllers;

use App\Models\IssueReturn;
use App\Models\IssueTransaction;
use App\Models\IssueTransactionItem;
use App\Models\Contact;
use App\Models\Item;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

class IssueReturnReportController extends Controller
{
    /**
     * Authorizable::callAction() would auto-derive read-issue-return-reports
     * (registry's real key is the bare issue-return-reports, no read-
     * prefix), 403ing Admin before it ever reaches this class's own
     * isAdmin() check below (Admin gets no Gate::before bypass the way
     * Super Admin does).
     */
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($this->isAdmin(), 403, 'Reports are only available to Admin.');

        $filters = [
            'report_type' => $request->input('report_type', 'issue'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'warehouse_id' => $request->input('warehouse_id'),
            'status' => $request->input('status'),
            'issued_to' => $request->input('issued_to'),
            'item_id' => $request->input('item_id'),
        ];

        $rows = $this->buildRows($filters);

        if ($request->query('output') === 'excel') {
            return (new FastExcel(collect($rows['rows'])))->download('issue-return-' . $filters['report_type'] . '-' . now()->format('Ymd-His') . '.xlsx');
        }

        if ($request->query('output') === 'print' || $request->query('output') === 'pdf') {
            $viewData = [
                'title' => $rows['title'],
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'columns' => $rows['columns'],
                'rows' => collect($rows['rows'])->map(fn ($r) => array_values($r))->values()->all(),
                'meta' => array_filter([
                    'From' => $filters['start_date'],
                    'To' => $filters['end_date'],
                    'Type' => $filters['report_type'],
                ]),
                'autoPrint' => $request->query('output') === 'print',
            ];

            if ($request->query('output') === 'pdf') {
                return Pdf::loadView('reports.pdf', $viewData)->download('issue-return-' . $filters['report_type'] . '-' . now()->format('Ymd-His') . '.pdf');
            }

            return view('reports.pdf', $viewData);
        }

        return Inertia::render('IssueReturn/Reports', [
            'filters' => $filters,
            'report' => $rows,
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status', 'completed_at']),
            'contacts' => Contact::ofAccount()->orderBy('name')->get(['id', 'name']),
            'items' => Item::ofAccount()
                ->when($filters['warehouse_id'], function ($q, $warehouseId) {
                    $q->whereHas('stock', fn ($sq) => $sq->where('warehouse_id', $warehouseId));
                })
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }

    private function buildRows(array $filters): array
    {
        return match ($filters['report_type']) {
            'return' => $this->returnReport($filters),
            'fully_returned' => $this->statusReport($filters, 'fully_returned', 'Fully Returned Report'),
            'partially_returned' => $this->statusReport($filters, 'partially_returned', 'Partially Returned Report'),
            'by_employee' => $this->byEmployeeReport($filters),
            'by_warehouse' => $this->byWarehouseReport($filters),
            'item_history' => $this->itemHistoryReport($filters),
            default => $this->issueReport($filters),
        };
    }

    private function issueReport(array $filters): array
    {
        $query = IssueTransaction::query()->with(['warehouse:id,name'])
            ->when($filters['start_date'], fn ($q, $v) => $q->whereDate('issue_date', '>=', $v))
            ->when($filters['end_date'], fn ($q, $v) => $q->whereDate('issue_date', '<=', $v))
            ->when($filters['warehouse_id'], fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($filters['issued_to'], fn ($q, $v) => $q->where('issued_to', 'like', "%{$v}%"));

        $rows = $query->latest('id')->limit(3000)->get()->map(fn ($issue) => [
            'Issue Number' => $issue->issue_number,
            'Date' => optional($issue->issue_date)->format('Y-m-d'),
            'Warehouse' => optional($issue->warehouse)->name,
            'Issued To' => $issue->issued_to,
            'Total Qty' => (float) $issue->total_quantity,
            'Returned Qty' => (float) $issue->returned_quantity,
            'Balance Qty' => (float) $issue->balance_quantity,
            'Status' => $issue->status,
        ])->all();

        return [
            'title' => 'Issue Report',
            'columns' => ['Issue Number', 'Date', 'Warehouse', 'Issued To', 'Total Qty', 'Returned Qty', 'Balance Qty', 'Status'],
            'rows' => $rows,
        ];
    }

    private function returnReport(array $filters): array
    {
        $query = IssueReturn::query()->with(['issueTransaction.warehouse:id,name'])
            ->when($filters['start_date'], fn ($q, $v) => $q->whereDate('return_date', '>=', $v))
            ->when($filters['end_date'], fn ($q, $v) => $q->whereDate('return_date', '<=', $v))
            ->when($filters['warehouse_id'], fn ($q, $v) => $q->whereHas('issueTransaction', fn ($iq) => $iq->where('warehouse_id', $v)));

        $rows = $query->withSum('items', 'returning_qty')->latest('id')->limit(3000)->get()->map(function ($return) {
            $qty = (float) ($return->items_sum_returning_qty ?? 0);

            return [
                'Issue Number' => optional($return->issueTransaction)->issue_number,
                'Return Date' => optional($return->return_date)->format('Y-m-d'),
                'Warehouse' => optional(optional($return->issueTransaction)->warehouse)->name,
                'Returned By' => $return->returned_by,
                'Received By' => $return->received_by,
                'Quantity' => $qty,
            ];
        })->all();

        return [
            'title' => 'Return Report',
            'columns' => ['Issue Number', 'Return Date', 'Warehouse', 'Returned By', 'Received By', 'Quantity'],
            'rows' => $rows,
        ];
    }

    private function statusReport(array $filters, string $status, string $title): array
    {
        $filters['status'] = $status;
        $base = $this->issueReport($filters);
        $base['title'] = $title;

        return $base;
    }

    private function byEmployeeReport(array $filters): array
    {
        $rows = IssueTransaction::query()
            ->selectRaw('issued_to, COUNT(*) as transactions, SUM(total_quantity) as total_qty, SUM(balance_quantity) as balance_qty')
            ->when($filters['start_date'], fn ($q, $v) => $q->whereDate('issue_date', '>=', $v))
            ->when($filters['end_date'], fn ($q, $v) => $q->whereDate('issue_date', '<=', $v))
            ->groupBy('issued_to')
            ->orderByDesc('transactions')
            ->get()
            ->map(fn ($row) => [
                'Issued To' => $row->issued_to,
                'Transactions' => (int) $row->transactions,
                'Total Qty' => (float) $row->total_qty,
                'Balance Qty' => (float) $row->balance_qty,
            ])->all();

        return [
            'title' => 'Issued Items by Employee',
            'columns' => ['Issued To', 'Transactions', 'Total Qty', 'Balance Qty'],
            'rows' => $rows,
        ];
    }

    private function byWarehouseReport(array $filters): array
    {
        $rows = IssueTransaction::query()
            ->with('warehouse:id,name')
            ->selectRaw('warehouse_id, COUNT(*) as transactions, SUM(total_quantity) as total_qty, SUM(balance_quantity) as balance_qty')
            ->when($filters['start_date'], fn ($q, $v) => $q->whereDate('issue_date', '>=', $v))
            ->when($filters['end_date'], fn ($q, $v) => $q->whereDate('issue_date', '<=', $v))
            ->groupBy('warehouse_id')
            ->orderByDesc('transactions')
            ->get()
            ->map(fn ($row) => [
                'Warehouse' => optional($row->warehouse)->name,
                'Transactions' => (int) $row->transactions,
                'Total Qty' => (float) $row->total_qty,
                'Balance Qty' => (float) $row->balance_qty,
            ])->all();

        return [
            'title' => 'Issued Items by Warehouse',
            'columns' => ['Warehouse', 'Transactions', 'Total Qty', 'Balance Qty'],
            'rows' => $rows,
        ];
    }

    private function itemHistoryReport(array $filters): array
    {
        $query = IssueTransactionItem::query()->with(['issueTransaction.warehouse:id,name', 'item:id,name,code'])
            ->when($filters['item_id'], fn ($q, $v) => $q->where('item_id', $v))
            ->when($filters['warehouse_id'], fn ($q, $v) => $q->whereHas('issueTransaction', fn ($iq) => $iq->where('warehouse_id', $v)))
            ->when($filters['start_date'], fn ($q, $v) => $q->whereHas('issueTransaction', fn ($iq) => $iq->whereDate('issue_date', '>=', $v)))
            ->when($filters['end_date'], fn ($q, $v) => $q->whereHas('issueTransaction', fn ($iq) => $iq->whereDate('issue_date', '<=', $v)));

        $rows = $query->latest('id')->limit(5000)->get()->map(function ($line) {
            $issue = $line->issueTransaction;

            return [
                'Issue Number' => optional($issue)->issue_number,
                'Date' => optional(optional($issue)->issue_date)->format('Y-m-d'),
                'Item' => optional($line->item)->name,
                'Code' => optional($line->item)->code,
                'Warehouse' => optional(optional($issue)->warehouse)->name,
                'Issued Qty' => (float) $line->issued_quantity,
                'Returned Qty' => (float) $line->returned_quantity,
                'Damaged Qty' => (float) $line->damaged_quantity,
                'Lost Qty' => (float) $line->lost_quantity,
                'Balance Qty' => (float) $line->balance_quantity,
            ];
        })->all();

        return [
            'title' => 'Item History Report',
            'columns' => ['Issue Number', 'Date', 'Item', 'Code', 'Warehouse', 'Issued Qty', 'Returned Qty', 'Damaged Qty', 'Lost Qty', 'Balance Qty'],
            'rows' => $rows,
        ];
    }

    private function isAdmin(): bool
    {
        $user = auth()->user();

        return (bool) $user && $user->hasRole(['Super Admin', 'Admin']);
    }
}
