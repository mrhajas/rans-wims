<?php

namespace App\Http\Controllers\FundAllocation;

use App\Http\Controllers\Controller;
use App\Models\FundAllocation;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Funds Allocation reports: every number comes from SQL aggregates over the item rows (never by loading whole
 * sheets), honouring the same filters on the screen, the CSV export and the print view.
 *
 * Money rules: soft-deleted sheets/items are always ignored. Cancelled and Rejected sheets are left out of every
 * money figure unless the status filter explicitly asks for them (the "by status" table still lists them so the
 * owner can see what was excluded).
 *
 * "Paid" is what the payer has ticked on the payment lines. With a payment-method filter the paid / unpaid
 * figures and the paid-state filter refer to the lines of that method only (allocated stays the item amount).
 */
class FundAllocationReportController extends Controller
{
    private const DEAD_STATUSES = [FundAllocation::STATUS_CANCELLED, FundAllocation::STATUS_REJECTED];

    private const GROUPS = ['date', 'category', 'method', 'beneficiary', 'payer', 'status', 'project'];

    private const BENEFICIARY_SQL = "COALESCE(NULLIF(TRIM(i.beneficiary), ''), ct.name, '')";

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('read-fund-allocation-reports');

        $f = $this->filters($request);
        $user = $request->user();

        $page = $this->detailRows($f)->paginate(25)->withQueryString();
        $lines = $this->linesFor($page->pluck('item_id')->all(), $f);
        $page->through(fn ($r) => $this->presentRow($r, $lines[$r->item_id] ?? []));

        return Inertia::render('FundAllocation/Reports', array_merge($this->aggregate($f), [
            'filters' => $this->filtersForClient($f),
            'rows' => paginated_response($page),
            'lookups' => $this->lookups(),
            'can' => [
                'export' => $user->can('export-fund-allocations'),
                'print' => $user->can('print-fund-allocations'),
                'sheets' => $user->can('read-fund-allocations'),
            ],
        ]));
    }

    public function export(Request $request)
    {
        $this->authorize('read-fund-allocation-reports');
        $this->authorize('export-fund-allocations');

        $f = $this->filters($request);
        $methods = DB::table('fund_payment_methods')->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'short_label']);
        $filename = 'funds-allocation-report-'.now()->format('Ymd-His').'.xlsx';

        activity('Funds Allocation')->withProperties(['filters' => $this->filtersForClient($f)])->log(__('Funds allocation report exported.'));

        $rows = function () use ($f, $methods) {
            $offset = 0;
            while (true) {
                $chunk = $this->detailRows($f)->offset($offset)->limit(500)->get();
                if ($chunk->isEmpty() || $offset >= 50000) {
                    break;
                }
                $lines = $this->linesFor($chunk->pluck('item_id')->all(), $f);
                foreach ($chunk as $r) {
                    $row = $this->presentRow($r, $lines[$r->item_id] ?? []);
                    $perMethod = [];
                    foreach ($lines[$r->item_id] ?? [] as $l) {
                        $perMethod[$l['method_id']] = ($perMethod[$l['method_id']] ?? 0) + $l['amount'];
                    }
                    $out = [
                        'Reference' => $row['reference'], 'Allocation Date' => $row['allocation_date'], 'Sheet Status' => $row['status'],
                        'Category' => $row['category'], 'Description' => $row['description'], 'Beneficiary' => $row['beneficiary'],
                        'Project' => $row['project'], 'Payer' => $row['payer'], 'Prepared By' => $row['prepared_by'],
                        'Requested' => round($row['request_amount'], 2), 'Allocated' => round($row['allocated_amount'], 2),
                        'Paid' => round($row['paid'], 2), 'Unpaid' => round($row['unpaid'], 2),
                        'Paid State' => $row['paid_state'], 'Payment Voucher' => $row['voucher']['pv_number'] ?? '',
                    ];
                    foreach ($methods as $m) {
                        $out[$m->short_label.' (planned)'] = isset($perMethod[$m->id]) ? round($perMethod[$m->id], 2) : '';
                    }
                    yield $out;
                }
                $offset += 500;
                if ($chunk->count() < 500) {
                    break;
                }
            }
        };

        // Bold, centred header row (same look as the printed report).
        $header = (new \OpenSpout\Common\Entity\Style\Style())
            ->setFontBold()
            ->setCellAlignment(\OpenSpout\Common\Entity\Style\CellAlignment::CENTER)
            ->setCellVerticalAlignment(\OpenSpout\Common\Entity\Style\CellVerticalAlignment::CENTER);

        return (new \Rap2hpoutre\FastExcel\FastExcel($rows()))->headerStyle($header)->download($filename);
    }

    public function print(Request $request)
    {
        $this->authorize('read-fund-allocation-reports');
        $this->authorize('print-fund-allocations');

        $f = $this->filters($request);
        $limit = 2000;
        $rows = $this->detailRows($f)->limit($limit + 1)->get();
        $truncated = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $lines = $this->linesFor($rows->pluck('item_id')->all(), $f);

        $lookups = $this->lookups();

        return view('fund-allocations.report-print', array_merge($this->aggregate($f), [
            'filters' => $this->filtersForClient($f),
            'filterLabels' => $this->filterLabels($f, $lookups),
            'rows' => $rows->map(fn ($r) => $this->presentRow($r, $lines[$r->item_id] ?? []))->values()->all(),
            'truncated' => $truncated,
            'limit' => $limit,
            'autoPrint' => true,
            'isPdf' => false,
            'companyName' => preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) optional($request->user()->account ?? null)->name),
            'printedBy' => optional($request->user())->name,
            'printedAt' => now()->format('Y-m-d H:i'),
        ]));
    }

    /* ------------------------------------------------------------------ filters */

    protected function filters(Request $request): array
    {
        $int = fn ($k) => ($v = $request->query($k)) !== null && $v !== '' && ctype_digit((string) $v) ? (int) $v : null;
        $date = function ($k) use ($request) {
            $v = $request->query($k);
            try {
                return $v ? \Carbon\Carbon::parse($v)->format('Y-m-d') : null;
            } catch (\Throwable $e) {
                return null;
            }
        };
        $in = fn ($k, array $allowed) => in_array($request->query($k), $allowed, true) ? $request->query($k) : null;

        return [
            'from' => $date('from'),
            'to' => $date('to'),
            'category_id' => $int('category_id'),
            'method_id' => $int('method_id'),
            'status' => $in('status', FundAllocation::statuses()),
            'payer' => $int('payer'),
            'prepared_by' => $int('prepared_by'),
            'warehouse_id' => $int('warehouse_id'),
            'contact_id' => $int('contact_id'),
            'beneficiary_user_id' => $int('beneficiary_user_id'),
            'beneficiary_warehouse_id' => $int('beneficiary_warehouse_id'),
            'staff_id' => $int('staff_id'),
            'beneficiary' => trim((string) $request->query('beneficiary', '')) ?: null,
            'voucher' => $in('voucher', ['with', 'without']),
            'paid' => $in('paid', ['paid', 'unpaid', 'partial']),
            'group_by' => $in('group_by', self::GROUPS),
        ];
    }

    protected function filtersForClient(array $f): array
    {
        return array_map(fn ($v) => $v === null ? '' : $v, $f);
    }

    /**
     * Item rows (alias i) joined to their sheet (a), category (c), contact (ct) and a per-item payment-lines
     * aggregate (ls). $moneyScope=true leaves Cancelled/Rejected sheets out unless the status filter asks for them.
     */
    protected function base(array $f, bool $moneyScope = true): Builder
    {
        $lines = DB::table('fund_allocation_item_lines as l')
            ->whereNull('l.deleted_at')
            ->when($f['method_id'], fn ($q, $m) => $q->where('l.fund_payment_method_id', $m))
            ->groupBy('l.fund_allocation_item_id')
            ->selectRaw('l.fund_allocation_item_id as item_id, COUNT(*) as n, SUM(l.is_paid) as paid_n, SUM(l.amount) as planned, SUM(CASE WHEN l.is_paid = 1 THEN l.amount ELSE 0 END) as paid');

        $q = DB::table('fund_allocation_items as i')
            ->join('fund_allocations as a', function ($j) {
                $j->on('a.id', '=', 'i.fund_allocation_id')->whereNull('a.deleted_at');
            })
            ->join('fund_categories as c', 'c.id', '=', 'i.fund_category_id')
            ->leftJoin('contacts as ct', 'ct.id', '=', 'i.contact_id')
            ->leftJoinSub($lines, 'ls', 'ls.item_id', '=', 'i.id')
            ->whereNull('i.deleted_at');

        if ($f['status']) {
            $q->where('a.status', $f['status']);
        } elseif ($moneyScope) {
            $q->whereNotIn('a.status', self::DEAD_STATUSES);
        }
        if ($f['from']) {
            $q->whereDate('a.allocation_date', '>=', $f['from']);
        }
        if ($f['to']) {
            $q->whereDate('a.allocation_date', '<=', $f['to']);
        }
        $q->when($f['category_id'], fn ($w, $v) => $w->where('i.fund_category_id', $v))
            ->when($f['method_id'], fn ($w) => $w->whereNotNull('ls.item_id'))
            ->when($f['payer'], fn ($w, $v) => $w->where('a.assigned_to_user_id', $v))
            ->when($f['prepared_by'], fn ($w, $v) => $w->where('a.prepared_by_user_id', $v))
            ->when($f['warehouse_id'], fn ($w, $v) => $w->where('i.warehouse_id', $v))
            ->when($f['contact_id'], fn ($w, $v) => $w->where('i.contact_id', $v))
            ->when($f['beneficiary_user_id'], fn ($w, $v) => $w->where('i.beneficiary_user_id', $v))
            ->when($f['beneficiary_warehouse_id'], fn ($w, $v) => $w->where('i.beneficiary_warehouse_id', $v))
            ->when($f['staff_id'], fn ($w, $v) => $w->where('i.staff_id', $v))
            ->when($f['beneficiary'], function ($w, $v) {
                $like = '%'.addcslashes($v, '%_\\').'%';
                $w->where(fn ($x) => $x->where('i.beneficiary', 'like', $like)->orWhere('ct.name', 'like', $like));
            });

        if ($f['voucher'] === 'with') {
            $q->whereNotNull('i.payment_voucher_id');
        } elseif ($f['voucher'] === 'without') {
            $q->whereNull('i.payment_voucher_id');
        }

        if ($f['paid'] === 'paid') {
            $q->whereRaw('ls.n > 0 AND ls.paid_n = ls.n');
        } elseif ($f['paid'] === 'partial') {
            $q->whereRaw('ls.paid_n > 0 AND ls.paid_n < ls.n');
        } elseif ($f['paid'] === 'unpaid') {
            $q->whereRaw('COALESCE(ls.paid_n, 0) = 0');
        }

        return $q;
    }

    /* ---------------------------------------------------------------- aggregates */

    protected function aggregate(array $f): array
    {
        $sum = 'COUNT(*) as items, COALESCE(SUM(i.request_amount),0) as requested, COALESCE(SUM(i.allocated_amount),0) as allocated, COALESCE(SUM(ls.planned),0) as planned, COALESCE(SUM(ls.paid),0) as paid';
        $unpaidBase = $f['method_id'] ? 'planned' : 'allocated';

        $t = $this->base($f)->selectRaw('COUNT(DISTINCT a.id) as sheets, '.$sum)->first();
        $totals = $this->money($t, $unpaidBase) + ['sheets' => (int) $t->sheets, 'items' => (int) $t->items];
        $totals['progress'] = $totals[$unpaidBase] > 0 ? round($totals['paid'] / $totals[$unpaidBase] * 100, 1) : 0;

        $byCategory = $this->base($f)->groupBy('c.id', 'c.name', 'c.sort_order')
            ->selectRaw('c.id as id, c.name as name, c.sort_order as sort_order, COUNT(DISTINCT a.id) as sheets, '.$sum)
            ->orderBy('c.sort_order')->orderBy('c.id')->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'sheets' => (int) $r->sheets, 'items' => (int) $r->items] + $this->money($r, $unpaidBase))->all();
        $byCategory = $this->withShare($byCategory, $totals['allocated']);

        // Payment methods are line-level: planned vs ticked amounts per method.
        $byMethod = $this->base($f)->join('fund_allocation_item_lines as ml', function ($j) {
                $j->on('ml.fund_allocation_item_id', '=', 'i.id')->whereNull('ml.deleted_at');
            })
            ->join('fund_payment_methods as m', 'm.id', '=', 'ml.fund_payment_method_id')
            ->when($f['method_id'], fn ($q, $v) => $q->where('ml.fund_payment_method_id', $v))
            ->groupBy('m.id', 'm.name', 'm.short_label', 'm.currency', 'm.sort_order')
            ->selectRaw('m.id as id, m.name as name, m.short_label as short_label, m.currency as currency, COALESCE(SUM(ml.usd_amount),0) as usd_planned, COALESCE(SUM(CASE WHEN ml.is_paid = 1 THEN ml.usd_amount ELSE 0 END),0) as usd_paid, COUNT(*) as line_total, COALESCE(SUM(ml.amount),0) as planned, COALESCE(SUM(CASE WHEN ml.is_paid = 1 THEN ml.amount ELSE 0 END),0) as paid')
            ->orderBy('m.sort_order')->orderBy('m.id')->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'short_label' => $r->short_label, 'currency' => $r->currency ?: 'TZS', 'usd_planned' => round((float) $r->usd_planned, 2), 'usd_paid' => round((float) $r->usd_paid, 2), 'lines' => (int) $r->line_total,
                'planned' => round((float) $r->planned, 2), 'paid' => round((float) $r->paid, 2), 'unpaid' => round((float) $r->planned - (float) $r->paid, 2)])->all();
        $plannedAll = array_sum(array_column($byMethod, 'planned'));
        foreach ($byMethod as &$m) {
            $m['share'] = $plannedAll > 0 ? round($m['planned'] / $plannedAll * 100, 1) : 0;
        }
        unset($m);

        // By status ignores the Cancelled/Rejected exclusion so the owner can see what is left out of the totals.
        $byStatus = $this->base($f, false)->groupBy('a.status')
            ->selectRaw('a.status as status, COUNT(DISTINCT a.id) as sheets, '.$sum)->get()
            ->map(fn ($r) => ['status' => $r->status, 'sheets' => (int) $r->sheets, 'items' => (int) $r->items, 'excluded' => ! $f['status'] && in_array($r->status, self::DEAD_STATUSES, true)] + $this->money($r, $unpaidBase))->all();
        $order = array_flip(FundAllocation::statuses());
        usort($byStatus, fn ($a, $b) => ($order[$a['status']] ?? 99) <=> ($order[$b['status']] ?? 99));
        $liveAllocated = array_sum(array_map(fn ($s) => $s['excluded'] ? 0 : $s['allocated'], $byStatus));
        $byStatus = array_map(fn ($s) => $s + ['share' => (! $s['excluded'] && $liveAllocated > 0) ? round($s['allocated'] / $liveAllocated * 100, 1) : 0], $byStatus);

        $byDate = $this->base($f)->groupBy('a.allocation_date')
            ->selectRaw('a.allocation_date as day, COUNT(DISTINCT a.id) as sheets, '.$sum)->orderBy('a.allocation_date')->get()
            ->map(fn ($r) => ['date' => substr((string) $r->day, 0, 10), 'sheets' => (int) $r->sheets] + $this->money($r, $unpaidBase))->all();

        $top = $this->base($f)->groupByRaw('beneficiary_name')
            ->selectRaw(self::BENEFICIARY_SQL.' as beneficiary_name, '.$sum)
            ->orderByDesc('allocated')->limit(10)->get()
            ->map(fn ($r) => ['name' => $r->beneficiary_name !== '' ? $r->beneficiary_name : null, 'items' => (int) $r->items] + $this->money($r, $unpaidBase))->all();
        $top = $this->withShare($top, $totals['allocated']);

        return [
            'totals' => $totals,
            'byCategory' => $byCategory,
            'byMethod' => $byMethod,
            'byStatus' => $byStatus,
            'byDate' => $byDate,
            'topBeneficiaries' => $top,
            'groups' => $f['group_by'] ? ['by' => $f['group_by'], 'rows' => $this->grouped($f, $sum, $unpaidBase, $totals['allocated'])] : null,
            'excludesInactive' => ! $f['status'],
        ];
    }

    protected function grouped(array $f, string $sum, string $unpaidBase, float $totalAllocated): array
    {
        $q = $this->base($f);
        $by = $f['group_by'];

        if ($by === 'method') {
            $rows = $q->join('fund_allocation_item_lines as ml', function ($j) {
                $j->on('ml.fund_allocation_item_id', '=', 'i.id')->whereNull('ml.deleted_at');
            })
                ->join('fund_payment_methods as m', 'm.id', '=', 'ml.fund_payment_method_id')
                ->when($f['method_id'], fn ($x, $v) => $x->where('ml.fund_payment_method_id', $v))
                ->groupBy('m.id', 'm.name', 'm.sort_order')
                ->selectRaw('m.id as k, m.name as label, COUNT(DISTINCT a.id) as sheets, COUNT(DISTINCT i.id) as items, COALESCE(SUM(ml.amount),0) as allocated, COALESCE(SUM(CASE WHEN ml.is_paid = 1 THEN ml.amount ELSE 0 END),0) as paid')
                ->orderBy('m.sort_order')->get();
            $out = $rows->map(fn ($r) => ['key' => (string) $r->k, 'label' => $r->label, 'sheets' => (int) $r->sheets, 'items' => (int) $r->items, 'requested' => null,
                'allocated' => round((float) $r->allocated, 2), 'paid' => round((float) $r->paid, 2), 'unpaid' => round((float) $r->allocated - (float) $r->paid, 2)])->all();

            return $this->withShare($out, array_sum(array_column($out, 'allocated')));
        }

        // [key expression, label expression, optional join, order]
        [$key, $label, $join, $order] = [
            'date' => ['a.allocation_date', 'a.allocation_date', null, 'k'],
            'category' => ['c.id', 'c.name', null, 'c.sort_order'],
            'beneficiary' => [self::BENEFICIARY_SQL, self::BENEFICIARY_SQL, null, null],
            'status' => ['a.status', 'a.status', null, null],
            'payer' => ['COALESCE(a.assigned_to_user_id, 0)', "COALESCE(u.name, '')", ['users as u', 'u.id', '=', 'a.assigned_to_user_id'], null],
            'project' => ['COALESCE(i.warehouse_id, 0)', "COALESCE(w.name, '')", ['warehouses as w', 'w.id', '=', 'i.warehouse_id'], null],
        ][$by];

        if ($join) {
            $q->leftJoin(...$join);
        }
        $q->selectRaw("$key as k, $label as label, COUNT(DISTINCT a.id) as sheets, $sum")->groupByRaw($by === 'beneficiary' ? 'k, label' : "$key, $label");
        if ($order === 'c.sort_order') {
            $q->groupBy('c.sort_order')->orderBy('c.sort_order');
        } elseif ($order === 'k') {
            $q->orderBy('k');
        } else {
            $q->orderByDesc('allocated');
        }

        $out = $q->get()->map(fn ($r) => [
            'key' => (string) $r->k,
            'label' => (string) $r->label,
            'sheets' => (int) $r->sheets,
            'items' => (int) $r->items,
        ] + $this->money($r, $unpaidBase))->all();

        return $this->withShare($out, $totalAllocated);
    }

    protected function money($r, string $unpaidBase): array
    {
        $allocated = round((float) $r->allocated, 2);
        $planned = round((float) $r->planned, 2);
        $paid = round((float) $r->paid, 2);

        return [
            'requested' => round((float) $r->requested, 2),
            'allocated' => $allocated,
            'planned' => $planned,
            'paid' => $paid,
            'unpaid' => round(max(($unpaidBase === 'planned' ? $planned : $allocated) - $paid, 0), 2),
        ];
    }

    protected function withShare(array $rows, float $total): array
    {
        return array_map(fn ($r) => $r + ['share' => $total > 0 ? round($r['allocated'] / $total * 100, 1) : 0], $rows);
    }

    /* ------------------------------------------------------------------- detail */

    /** Item rows, newest sheet first; paginate() / limit() is applied by the caller. */
    protected function detailRows(array $f): Builder
    {
        return $this->base($f)
            ->leftJoin('users as pu', 'pu.id', '=', 'a.assigned_to_user_id')
            ->leftJoin('users as bu', 'bu.id', '=', 'a.prepared_by_user_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'i.warehouse_id')
            ->leftJoin('payment_vouchers as pv', 'pv.id', '=', 'i.payment_voucher_id')
            ->selectRaw('i.id as item_id, a.id as sheet_id, a.reference, a.allocation_date, a.status, a.title, c.name as category, i.description, '
                .self::BENEFICIARY_SQL.' as beneficiary, i.request_amount, i.allocated_amount, i.payment_voucher_id, pv.pv_number, pv.status as pv_status, '
                .'pu.name as payer, bu.name as prepared_by, w.name as project, '
                .'COALESCE(ls.n, 0) as line_count, COALESCE(ls.paid_n, 0) as paid_count, COALESCE(ls.planned, 0) as planned, COALESCE(ls.paid, 0) as paid')
            ->orderByDesc('a.allocation_date')->orderByDesc('a.id')->orderBy('i.sort_order')->orderBy('i.id');
    }

    /** Payment lines (method split) for a page of items, keyed by item id. Honours the method filter. */
    protected function linesFor(array $itemIds, array $f): array
    {
        if (! $itemIds) {
            return [];
        }

        return DB::table('fund_allocation_item_lines as l')
            ->join('fund_payment_methods as m', 'm.id', '=', 'l.fund_payment_method_id')
            ->whereNull('l.deleted_at')
            ->whereIn('l.fund_allocation_item_id', $itemIds)
            ->when($f['method_id'], fn ($q, $v) => $q->where('l.fund_payment_method_id', $v))
            ->orderBy('m.sort_order')->orderBy('l.id')
            ->get(['l.fund_allocation_item_id as item_id', 'l.fund_payment_method_id as method_id', 'm.name', 'm.short_label', 'l.amount', 'l.is_paid'])
            ->groupBy('item_id')
            ->map(fn ($g) => $g->map(fn ($l) => ['method_id' => $l->method_id, 'name' => $l->name, 'short_label' => $l->short_label, 'amount' => round((float) $l->amount, 2), 'is_paid' => (bool) $l->is_paid])->all())
            ->all();
    }

    protected function presentRow($r, array $lines): array
    {
        $n = (int) $r->line_count;
        $paidN = (int) $r->paid_count;
        $state = $n === 0 ? 'none' : ($paidN === $n ? 'paid' : ($paidN > 0 ? 'partial' : 'unpaid'));
        $base = $n > 0 ? (float) $r->planned : (float) $r->allocated_amount;

        return [
            'item_id' => (int) $r->item_id,
            'sheet_id' => (int) $r->sheet_id,
            'reference' => $r->reference,
            'allocation_date' => substr((string) $r->allocation_date, 0, 10),
            'status' => $r->status,
            'category' => $r->category,
            'description' => $r->description,
            'beneficiary' => $r->beneficiary !== '' ? $r->beneficiary : null,
            'project' => $r->project,
            'payer' => $r->payer,
            'prepared_by' => $r->prepared_by,
            'request_amount' => round((float) $r->request_amount, 2),
            'allocated_amount' => round((float) $r->allocated_amount, 2),
            'paid' => round((float) $r->paid, 2),
            'unpaid' => round(max($base - (float) $r->paid, 0), 2),
            'paid_state' => $state,
            'lines' => $lines,
            'voucher' => $r->payment_voucher_id ? ['id' => (int) $r->payment_voucher_id, 'pv_number' => $r->pv_number, 'status' => $r->pv_status] : null,
        ];
    }

    /* ------------------------------------------------------------------ lookups */

    protected function lookups(): array
    {
        $names = fn ($table, $col, $idCol) => DB::table($table)->whereIn('id', DB::table('fund_allocation_items')->whereNull('deleted_at')->whereNotNull($col)->select($col))
            ->orderBy('name')->get(['id', 'name'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->all();
        $users = fn ($col) => DB::table('users')->whereNull('deleted_at')->whereIn('id', DB::table('fund_allocations')->whereNull('deleted_at')->whereNotNull($col)->select($col))
            ->orderBy('name')->get(['id', 'name'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->all();

        return [
            'categories' => DB::table('fund_categories')->whereNull('deleted_at')->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->all(),
            'methods' => DB::table('fund_payment_methods')->whereNull('deleted_at')->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'short_label'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'short_label' => $r->short_label])->all(),
            'payers' => $users('assigned_to_user_id'),
            'preparers' => $users('prepared_by_user_id'),
            'warehouses' => $names('warehouses', 'warehouse_id', 'id'),
            'contacts' => $names('contacts', 'contact_id', 'id'),
            // Beneficiary picker: every contact, active user and active project, like the allocation sheet form.
            'beneficiary_contacts' => DB::table('contacts')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'category'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'category' => $r->category])->all(),
            'beneficiary_users' => DB::table('users')->whereNull('deleted_at')->where('is_active', 1)->orderBy('name')->get(['id', 'name', 'username'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'username' => $r->username])->all(),
            'beneficiary_projects' => DB::table('warehouses')->whereNull('deleted_at')->where('active', 1)->orderBy('name')->get(['id', 'name', 'status'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'status' => $r->status])->all(),
            'staffs' => $names('staffs', 'staff_id', 'id'),
            'statuses' => FundAllocation::statuses(),
            'groupings' => self::GROUPS,
        ];
    }

    /** Human readable "Filter: value" pairs for the print header. */
    protected function filterLabels(array $f, array $lookups): array
    {
        $find = fn (array $list, $id) => collect($list)->firstWhere('id', $id)['name'] ?? ('#'.$id);
        $out = [];
        if ($f['from'] || $f['to']) {
            $out['Period'] = ($f['from'] ?: 'beginning').' to '.($f['to'] ?: 'today');
        }
        $f['category_id'] && $out['Category'] = $find($lookups['categories'], $f['category_id']);
        $f['method_id'] && $out['Payment Method'] = $find($lookups['methods'], $f['method_id']);
        $f['status'] && $out['Status'] = $f['status'];
        $f['payer'] && $out['Payer'] = $find($lookups['payers'], $f['payer']);
        $f['prepared_by'] && $out['Prepared By'] = $find($lookups['preparers'], $f['prepared_by']);
        $f['warehouse_id'] && $out['Project'] = $find($lookups['warehouses'], $f['warehouse_id']);
        $f['contact_id'] && $out['Contact'] = $find($lookups['contacts'], $f['contact_id']);
        $f['beneficiary_user_id'] && $out['Beneficiary (User)'] = $find($lookups['beneficiary_users'], $f['beneficiary_user_id']);
        $f['beneficiary_warehouse_id'] && $out['Beneficiary (Project)'] = $find($lookups['beneficiary_projects'], $f['beneficiary_warehouse_id']);
        $f['staff_id'] && $out['Staff'] = $find($lookups['staffs'], $f['staff_id']);
        $f['beneficiary'] && $out['Beneficiary'] = $f['beneficiary'];
        $f['voucher'] && $out['Payment Voucher'] = $f['voucher'] === 'with' ? 'With voucher' : 'Without voucher';
        $f['paid'] && $out['Paid State'] = ucfirst($f['paid']);
        $f['group_by'] && $out['Grouped By'] = ucfirst($f['group_by']);

        return $out;
    }
}
