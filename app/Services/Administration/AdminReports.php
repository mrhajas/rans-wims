<?php

namespace App\Services\Administration;

use App\Models\AdminDocument;
use App\Models\AdminItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Administration reports in the WIMS report shape (title, filters, tiles, columns, rows, notes): the register, expenses by item (in total and month by month),
 * every line, by month, by beneficiary and the charity reports. The money reports count authorized documents unless a status is chosen; every report follows the
 * documents the person may see.
 */
class AdminReports
{
    public const LIMIT = 5000;

    private const MONEY = ['by_item', 'item_monthly', 'lines', 'by_month', 'by_beneficiary', 'charity', 'charity_people'];

    public static function fmt($v): string
    {
        return number_format((float) $v, 2, '.', ',');
    }

    public static function catalog(): array
    {
        $common = ['from', 'to', 'status'];

        return [
            ['key' => 'register', 'title' => __('Document register'), 'about' => __('Every weekly document in the period: date, who requested it, the three stages, its lines, the charity section and the total.'), 'filters' => array_merge(['search', 'user_id', 'charity'], $common, ['amount_min', 'amount_max'])],
            ['key' => 'by_item', 'title' => __('Expenses by item'), 'about' => __('Each item with how many times it was paid and how much, in the period. Choose one item to see only that item.'), 'filters' => array_merge(['search', 'item_id', 'beneficiary', 'section'], $common)],
            ['key' => 'item_monthly', 'title' => __('Item expenses month by month'), 'about' => __('Each item as a row and each month as a column, with the total: see what an item cost every month, or between two dates.'), 'filters' => array_merge(['search', 'item_id', 'beneficiary'], $common)],
            ['key' => 'lines', 'title' => __('Every line (detail)'), 'about' => __('Every line of every document, one row each: date, document, item, beneficiary and amount.'), 'filters' => array_merge(['search', 'item_id', 'beneficiary', 'section', 'amount_min', 'amount_max'], $common)],
            ['key' => 'by_month', 'title' => __('Totals by month'), 'about' => __('Each month with the number of documents, the lines, the charity and the total.'), 'filters' => array_merge(['user_id', 'charity'], $common)],
            ['key' => 'by_beneficiary', 'title' => __('By beneficiary'), 'about' => __('Each beneficiary with their number of lines and the total, from the Beneficiary column of the documents.'), 'filters' => array_merge(['search', 'item_id', 'beneficiary'], $common)],
            ['key' => 'charity', 'title' => __('Charity paid (detail)'), 'about' => __('Every charity line of the documents: who received it, for which month, the document and the amount.'), 'filters' => array_merge(['search', 'beneficiary'], $common)],
            ['key' => 'charity_people', 'title' => __('Charity by person'), 'about' => __('Each person or place of the Charity list with the number of times and the total paid in the period.'), 'filters' => array_merge(['search'], $common)],
        ];
    }

    public static function exists(string $type): bool
    {
        return in_array($type, array_column(self::catalog(), 'key'), true);
    }

    public static function build(User $user, string $type, array $f, int $limit = self::LIMIT): array
    {
        $f = self::normalize($f, $type);
        [$cols, $rows, $title, $about, $tiles] = match ($type) {
            'by_item' => self::byItem($f, $user),
            'item_monthly' => self::itemMonthly($f, $user),
            'lines' => self::lines($f, $user),
            'by_month' => self::byMonth($f, $user),
            'by_beneficiary' => self::byBeneficiary($f, $user),
            'charity' => self::charity($f, $user),
            'charity_people' => self::charityPeople($f, $user),
            default => self::register($f, $user),
        };
        $total = count($rows);
        $kept = array_slice($rows, 0, $limit);
        $display = array_map(function ($r) use ($cols) {
            $o = [];
            foreach ($cols as $c) {
                $v = $r[$c['key']] ?? '';
                $o[$c['key']] = ($c['type'] ?? '') === 'money' && $v !== '' && $v !== null ? self::fmt($v) : $v;
            }

            return $o;
        }, $kept);
        foreach ($display as $i => $r) {
            $display[$i] = ['no' => $i + 1] + $r;
        }
        array_unshift($cols, ['key' => 'no', 'label' => __('No'), 'type' => 'num']);
        $cols = array_map(fn ($c) => $c + ['align' => in_array($c['type'] ?? '', ['money', 'num'], true) ? 'right' : 'left'], $cols);

        return ['type' => $type, 'title' => $title, 'reference' => 'ADR-' . now()->format('Ymd-His'), 'subtitle' => $about, 'table_title' => $title, 'filters' => self::filterText($f, $type), 'tiles' => array_values(array_merge([['label' => __('Rows'), 'value' => $total]], $tiles)),
            'columns' => $cols, 'rows' => $display, 'raw' => $kept, 'total_rows' => $total, 'truncated' => $total > $limit, 'sections' => [],
            'notes' => [in_array($type, self::MONEY, true) && empty($f['status_given']) ? __('Only authorized documents are counted; choose a status to see the others.') : __('The documents of the chosen status are counted.'), __('Amounts are in the currency of the documents.'), __('Figures are drawn from the records at the time of generation.')]];
    }

    private static function normalize(array $f, string $type): array
    {
        $date = fn ($v) => ! empty($v) && strtotime($v) ? date('Y-m-d', strtotime($v)) : null;
        $status = in_array($f['status'] ?? '', array_merge(AdminDocument::STATUSES, ['open']), true) ? $f['status'] : null;
        $given = $status !== null;
        if ($status === null && in_array($type, self::MONEY, true)) {
            $status = AdminDocument::AUTHORIZED;
        }
        $num = fn ($v) => isset($v) && $v !== '' && is_numeric($v) ? (float) $v : null;

        return ['search' => mb_substr(trim((string) ($f['search'] ?? '')), 0, 100), 'status' => $status, 'status_given' => $given, 'from' => $date($f['from'] ?? null), 'to' => $date($f['to'] ?? null),
            'item_id' => ctype_digit((string) ($f['item_id'] ?? '')) && ($f['item_id'] ?? '') !== '' ? (int) $f['item_id'] : null, 'user_id' => ctype_digit((string) ($f['user_id'] ?? '')) && ($f['user_id'] ?? '') !== '' ? (int) $f['user_id'] : null,
            'beneficiary' => mb_substr(trim((string) ($f['beneficiary'] ?? '')), 0, 100), 'section' => in_array($f['section'] ?? '', ['items', 'charity'], true) ? $f['section'] : null,
            'charity' => in_array($f['charity'] ?? '', ['1', '0'], true) ? (string) $f['charity'] : null, 'amount_min' => $num($f['amount_min'] ?? null), 'amount_max' => $num($f['amount_max'] ?? null)];
    }

    private static function filterText(array $f, string $type): array
    {
        $o = [];
        if ($f['from'] || $f['to']) {
            $o[__('Period')] = ($f['from'] ? Carbon::parse($f['from'])->format('d M Y') : __('the beginning')) . ' - ' . ($f['to'] ? Carbon::parse($f['to'])->format('d M Y') : __('today'));
        }
        if ($f['status']) {
            $o[__('Status')] = $f['status'] === 'open' ? __('Waiting (requested or approved)') : __($f['status']);
        }
        if ($f['item_id']) {
            $o[__('Item')] = optional(AdminItem::withTrashed()->find($f['item_id']))->name ?? '#' . $f['item_id'];
        }
        if ($f['user_id']) {
            $o[__('Requested by')] = optional(User::withoutGlobalScopes()->find($f['user_id']))->name ?? '#' . $f['user_id'];
        }
        foreach (['search' => __('Search'), 'beneficiary' => __('Beneficiary')] as $k => $l) {
            if (! empty($f[$k])) {
                $o[$l] = $f[$k];
            }
        }
        if ($f['section']) {
            $o[__('Section')] = $f['section'] === 'items' ? __('Lines only') : __('Charity only');
        }
        if ($f['charity'] !== null) {
            $o[__('Charity section')] = $f['charity'] === '1' ? __('With charity') : __('Without charity');
        }
        if ($f['amount_min'] !== null) {
            $o[__('Amount from')] = self::fmt($f['amount_min']);
        }
        if ($f['amount_max'] !== null) {
            $o[__('Amount up to')] = self::fmt($f['amount_max']);
        }

        return $o ?: [__('Filters') => __('None')];
    }

    // ---------------------------------------------------------------------------------------------------- queries

    private static function like(string $v): string
    {
        return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
    }

    /** The documents of the filters that the person may see (the status, period and who requested them). */
    private static function docs(array $f, User $user)
    {
        return AdminDocument::query()->visibleTo($user)
            ->when($f['status'] === 'open', fn ($q) => $q->whereIn('status', [AdminDocument::REQUESTED, AdminDocument::APPROVED]))
            ->when($f['status'] && $f['status'] !== 'open', fn ($q) => $q->where('status', $f['status']))
            ->when($f['from'], fn ($q, $v) => $q->whereDate('doc_date', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('doc_date', '<=', $v))
            ->when($f['user_id'], fn ($q, $v) => $q->where(fn ($w) => $w->where('beneficiary_user_id', $v)->orWhere('user_id', $v)))
            ->when($f['charity'] !== null, fn ($q) => $q->where('include_charity', $f['charity'] === '1'));
    }

    private static function lineQuery(array $f, User $user)
    {
        return DB::table('admin_document_items as l')->join('admin_documents as d', 'd.id', '=', 'l.admin_document_id')->leftJoin('admin_items as i', 'i.id', '=', 'l.admin_item_id')
            ->whereNull('l.deleted_at')->whereIn('d.id', self::docs($f, $user)->select('admin_documents.id'))
            ->when($f['item_id'], fn ($q, $v) => $q->where('l.admin_item_id', $v))
            ->when($f['beneficiary'] !== '', fn ($q) => $q->where('l.beneficiary', 'like', self::like($f['beneficiary'])))
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('l.description', 'like', self::like($f['search']))->orWhere('l.beneficiary', 'like', self::like($f['search']))->orWhere('d.reference', 'like', self::like($f['search']))))
            ->when($f['amount_min'] !== null, fn ($q) => $q->where('l.amount', '>=', $f['amount_min']))->when($f['amount_max'] !== null, fn ($q) => $q->where('l.amount', '<=', $f['amount_max']));
    }

    private static function charityQuery(array $f, User $user)
    {
        return DB::table('admin_document_charities as c')->join('admin_documents as d', 'd.id', '=', 'c.admin_document_id')
            ->whereNull('c.deleted_at')->where('d.include_charity', 1)->whereIn('d.id', self::docs($f, $user)->select('admin_documents.id'))
            ->when($f['beneficiary'] !== '', fn ($q) => $q->where('c.name', 'like', self::like($f['beneficiary'])))
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('c.name', 'like', self::like($f['search']))->orWhere('c.description', 'like', self::like($f['search']))->orWhere('d.reference', 'like', self::like($f['search']))));
    }

    private static function cents($v): int
    {
        return (int) round(((float) $v) * 100);
    }

    private static function tile(string $label, int $cents, string $cur): array
    {
        return ['label' => $label, 'value' => $cur . ' ' . number_format($cents / 100, 2)];
    }

    // ---------------------------------------------------------------------------------------------------- the reports

    private static function register(array $f, User $user): array
    {
        $cur = AdminDocument::currency();
        $ds = self::docs($f, $user)
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', self::like($f['search']))->orWhere('beneficiary_name', 'like', self::like($f['search']))->orWhereHas('lines', fn ($l) => $l->where('description', 'like', self::like($f['search']))->orWhere('beneficiary', 'like', self::like($f['search'])))))
            ->when($f['amount_min'] !== null, fn ($q) => $q->where('total_amount', '>=', $f['amount_min']))->when($f['amount_max'] !== null, fn ($q) => $q->where('total_amount', '<=', $f['amount_max']))
            ->with(['user:id,name', 'approvedBy:id,name', 'authorizedBy:id,name'])->withCount(['lines', 'charityLines'])->orderBy('doc_date')->orderBy('id')->limit(self::LIMIT + 1)->get();
        $rows = $ds->map(fn ($d) => ['reference' => $d->reference, 'date' => $d->doc_date->toDateString(), 'requested_by' => $d->beneficiary_name, 'status' => __($d->status), 'lines' => $d->lines_count, 'charity_lines' => $d->include_charity ? $d->charity_lines_count : 0,
            'items' => (string) $d->items_total, 'charity' => (string) $d->charity_total, 'total' => (string) $d->total_amount, 'approved_by' => optional($d->approvedBy)->name, 'authorized_by' => optional($d->authorizedBy)->name, 'prepared_by' => optional($d->user)->name])->all();
        $cols = [['key' => 'reference', 'label' => __('Reference')], ['key' => 'date', 'label' => __('Date')], ['key' => 'requested_by', 'label' => __('Requested by')], ['key' => 'status', 'label' => __('Status')], ['key' => 'lines', 'label' => __('Lines'), 'type' => 'num'],
            ['key' => 'charity_lines', 'label' => __('Charity lines'), 'type' => 'num'], ['key' => 'items', 'label' => __('Lines total'), 'type' => 'money'], ['key' => 'charity', 'label' => __('Charity'), 'type' => 'money'], ['key' => 'total', 'label' => __('Total'), 'type' => 'money'],
            ['key' => 'approved_by', 'label' => __('Approved by')], ['key' => 'authorized_by', 'label' => __('Authorized by')]];
        $by = [];
        foreach ($ds as $d) {
            $by[$d->status] = ($by[$d->status] ?? 0) + self::cents($d->total_amount);
        }
        $tiles = [];
        foreach ([AdminDocument::AUTHORIZED, AdminDocument::APPROVED, AdminDocument::REQUESTED] as $s) {
            if (isset($by[$s])) {
                $tiles[] = self::tile(__($s), $by[$s], $cur);
            }
        }

        return [$cols, $rows, __('Document register'), __('Every weekly document in the period.'), $tiles];
    }

    private static function byItem(array $f, User $user): array
    {
        $cur = AdminDocument::currency();
        $rows = [];
        if ($f['section'] !== 'charity') {
            $q = self::lineQuery($f, $user)->groupBy('l.admin_item_id', 'i.name')->selectRaw('l.admin_item_id, COALESCE(i.name, MAX(l.description)) name, COUNT(*) n, SUM(l.amount) total, MIN(d.doc_date) first_on, MAX(d.doc_date) last_on, MAX(l.amount) top')->orderByDesc('total')->get();
            foreach ($q as $r) {
                $rows[] = ['item' => $r->name, 'section' => __('Lines'), 'times' => (int) $r->n, 'total' => (string) $r->total, 'average' => (string) round(((float) $r->total) / max(1, (int) $r->n), 2), 'first' => $r->first_on, 'last' => $r->last_on];
            }
        }
        if ($f['section'] !== 'items' && ! $f['item_id']) {
            $q = self::charityQuery($f, $user)->selectRaw('COUNT(*) n, SUM(c.amount) total, MIN(d.doc_date) first_on, MAX(d.doc_date) last_on')->first();
            if ($q && (int) $q->n > 0) {
                $rows[] = ['item' => __('Charity section'), 'section' => __('Charity'), 'times' => (int) $q->n, 'total' => (string) $q->total, 'average' => (string) round(((float) $q->total) / max(1, (int) $q->n), 2), 'first' => $q->first_on, 'last' => $q->last_on];
            }
        }
        usort($rows, fn ($a, $b) => (float) $b['total'] <=> (float) $a['total']);
        $cols = [['key' => 'item', 'label' => __('Item')], ['key' => 'section', 'label' => __('Section')], ['key' => 'times', 'label' => __('Times'), 'type' => 'num'], ['key' => 'total', 'label' => __('Total'), 'type' => 'money'], ['key' => 'average', 'label' => __('Average'), 'type' => 'money'],
            ['key' => 'first', 'label' => __('First paid')], ['key' => 'last', 'label' => __('Last paid')]];

        return [$cols, $rows, __('Expenses by item'), __('What each item cost in the period.'), [self::tile(__('Total'), (int) array_sum(array_map(fn ($r) => self::cents($r['total']), $rows)), $cur)]];
    }

    /** Each item as a row and each month of the period as a column (at most 36 months). */
    private static function itemMonthly(array $f, User $user): array
    {
        $cur = AdminDocument::currency();
        $q = self::lineQuery($f, $user)->groupBy('l.admin_item_id', 'i.name', DB::raw("DATE_FORMAT(d.doc_date, '%Y-%m')"))
            ->selectRaw("l.admin_item_id, COALESCE(i.name, MAX(l.description)) name, DATE_FORMAT(d.doc_date, '%Y-%m') ym, SUM(l.amount) total")->get();
        $months = $q->pluck('ym')->unique()->sort()->values();
        if ($f['from'] || $f['to']) {
            $a = Carbon::parse($f['from'] ?: ($months->first() ? $months->first() . '-01' : now()->toDateString()))->startOfMonth();
            $b = Carbon::parse($f['to'] ?: ($months->last() ? $months->last() . '-01' : now()->toDateString()))->startOfMonth();
            $months = collect();
            for ($m = $a->copy(); $m->lte($b) && $months->count() < 36; $m->addMonth()) {
                $months->push($m->format('Y-m'));
            }
        } else {
            $months = $months->take(-36)->values();
        }
        $by = [];
        foreach ($q as $r) {
            $by[(int) $r->admin_item_id]['name'] = $r->name;
            $by[(int) $r->admin_item_id]['m'][$r->ym] = (int) round(((float) $r->total) * 100);
        }
        $rows = [];
        $col = [];
        foreach ($by as $x) {
            $row = ['item' => $x['name']];
            $tot = 0;
            foreach ($months as $m) {
                $c = $x['m'][$m] ?? 0;
                $row['m_' . $m] = $c ? number_format($c / 100, 2, '.', '') : '';
                $col[$m] = ($col[$m] ?? 0) + $c;
                $tot += $c;
            }
            $row['total'] = number_format($tot / 100, 2, '.', '');
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => (float) $b['total'] <=> (float) $a['total']);
        $cols = [['key' => 'item', 'label' => __('Item')]];
        foreach ($months as $m) {
            $cols[] = ['key' => 'm_' . $m, 'label' => Carbon::parse($m . '-01')->translatedFormat('M Y'), 'type' => 'money'];
        }
        $cols[] = ['key' => 'total', 'label' => __('Total'), 'type' => 'money'];
        if ($rows) {
            $foot = ['item' => __('All items')];
            foreach ($months as $m) {
                $foot['m_' . $m] = number_format(($col[$m] ?? 0) / 100, 2, '.', '');
            }
            $foot['total'] = number_format(array_sum($col) / 100, 2, '.', '');
            $rows[] = $foot;
        }

        return [$cols, $rows, __('Item expenses month by month'), __('Each item by month.'), [self::tile(__('Total'), (int) array_sum($col), $cur), ['label' => __('Months'), 'value' => $months->count()]]];
    }

    private static function lines(array $f, User $user): array
    {
        $cur = AdminDocument::currency();
        $rows = [];
        if ($f['section'] !== 'charity') {
            foreach (self::lineQuery($f, $user)->selectRaw('d.doc_date, d.reference, d.status, d.beneficiary_name req, COALESCE(i.name, l.description) item, l.description, l.beneficiary, l.amount')->orderBy('d.doc_date')->orderBy('d.id')->orderBy('l.sort_order')->limit(self::LIMIT + 1)->get() as $r) {
                $rows[] = ['date' => $r->doc_date, 'reference' => $r->reference, 'status' => __($r->status), 'requested_by' => $r->req, 'section' => __('Lines'), 'item' => $r->description, 'beneficiary' => $r->beneficiary, 'amount' => (string) $r->amount];
            }
        }
        if ($f['section'] !== 'items' && ! $f['item_id'] && $f['amount_min'] === null && $f['amount_max'] === null) {
            foreach (self::charityQuery($f, $user)->selectRaw('d.doc_date, d.reference, d.status, d.beneficiary_name req, c.description, c.name, c.amount')->orderBy('d.doc_date')->orderBy('d.id')->limit(self::LIMIT + 1)->get() as $r) {
                $rows[] = ['date' => $r->doc_date, 'reference' => $r->reference, 'status' => __($r->status), 'requested_by' => $r->req, 'section' => __('Charity'), 'item' => $r->description, 'beneficiary' => $r->name, 'amount' => (string) $r->amount];
            }
        }
        usort($rows, fn ($a, $b) => strcmp($a['date'] . $a['reference'], $b['date'] . $b['reference']));
        $cols = [['key' => 'date', 'label' => __('Date')], ['key' => 'reference', 'label' => __('Document')], ['key' => 'status', 'label' => __('Status')], ['key' => 'section', 'label' => __('Section')], ['key' => 'item', 'label' => __('Description')],
            ['key' => 'beneficiary', 'label' => __('Beneficiary')], ['key' => 'requested_by', 'label' => __('Requested by')], ['key' => 'amount', 'label' => __('Amount'), 'type' => 'money']];

        return [$cols, $rows, __('Every line (detail)'), __('Every line of the documents.'), [self::tile(__('Total'), (int) array_sum(array_map(fn ($r) => self::cents($r['amount']), $rows)), $cur)]];
    }

    private static function byMonth(array $f, User $user): array
    {
        $cur = AdminDocument::currency();
        $q = self::docs($f, $user)->groupBy(DB::raw("DATE_FORMAT(doc_date, '%Y-%m')"))->selectRaw("DATE_FORMAT(doc_date, '%Y-%m') ym, COUNT(*) n, SUM(items_total) i, SUM(charity_total) c, SUM(total_amount) t")->orderBy('ym')->get();
        $docIds = self::docs($f, $user)->pluck('id');
        $lineCounts = DB::table('admin_document_items as l')->join('admin_documents as d', 'd.id', '=', 'l.admin_document_id')->whereNull('l.deleted_at')->whereIn('d.id', $docIds)->groupBy(DB::raw("DATE_FORMAT(d.doc_date, '%Y-%m')"))
            ->selectRaw("DATE_FORMAT(d.doc_date, '%Y-%m') ym, COUNT(*) n")->pluck('n', 'ym');
        $rows = $q->map(fn ($r) => ['month' => Carbon::parse($r->ym . '-01')->translatedFormat('F Y'), 'documents' => (int) $r->n, 'lines' => (int) ($lineCounts[$r->ym] ?? 0), 'items' => (string) $r->i, 'charity' => (string) $r->c, 'total' => (string) $r->t])->all();
        $cols = [['key' => 'month', 'label' => __('Month')], ['key' => 'documents', 'label' => __('Documents'), 'type' => 'num'], ['key' => 'lines', 'label' => __('Lines'), 'type' => 'num'], ['key' => 'items', 'label' => __('Lines total'), 'type' => 'money'],
            ['key' => 'charity', 'label' => __('Charity'), 'type' => 'money'], ['key' => 'total', 'label' => __('Total'), 'type' => 'money']];

        return [$cols, $rows, __('Totals by month'), __('Each month.'), [self::tile(__('Total'), (int) array_sum(array_map(fn ($r) => self::cents($r['total']), $rows)), $cur)]];
    }

    private static function byBeneficiary(array $f, User $user): array
    {
        $cur = AdminDocument::currency();
        $q = self::lineQuery($f, $user)->selectRaw("COALESCE(NULLIF(l.beneficiary, ''), d.beneficiary_name) who, COUNT(*) n, SUM(l.amount) total, MAX(d.doc_date) last_on")->groupBy('who')->orderByDesc('total')->get();
        $rows = $q->map(fn ($r) => ['beneficiary' => $r->who ?: __('Not named'), 'lines' => (int) $r->n, 'total' => (string) $r->total, 'last' => $r->last_on])->all();
        $cols = [['key' => 'beneficiary', 'label' => __('Beneficiary')], ['key' => 'lines', 'label' => __('Lines'), 'type' => 'num'], ['key' => 'total', 'label' => __('Total'), 'type' => 'money'], ['key' => 'last', 'label' => __('Last paid')]];

        return [$cols, $rows, __('By beneficiary'), __('Each beneficiary.'), [self::tile(__('Total'), (int) array_sum(array_map(fn ($r) => self::cents($r['total']), $rows)), $cur)]];
    }

    private static function charity(array $f, User $user): array
    {
        $cur = AdminDocument::currency();
        $rows = self::charityQuery($f, $user)->selectRaw('d.doc_date, d.reference, d.status, c.name, c.description, c.monthly_amount, c.amount')->orderBy('d.doc_date')->orderBy('d.id')->limit(self::LIMIT + 1)->get()
            ->map(fn ($r) => ['date' => $r->doc_date, 'reference' => $r->reference, 'status' => __($r->status), 'name' => $r->name, 'description' => $r->description, 'monthly' => (string) $r->monthly_amount, 'amount' => (string) $r->amount])->all();
        $cols = [['key' => 'date', 'label' => __('Date')], ['key' => 'reference', 'label' => __('Document')], ['key' => 'status', 'label' => __('Status')], ['key' => 'name', 'label' => __('Person / place')], ['key' => 'description', 'label' => __('Description')],
            ['key' => 'monthly', 'label' => __('Monthly amount'), 'type' => 'money'], ['key' => 'amount', 'label' => __('Paid'), 'type' => 'money']];

        return [$cols, $rows, __('Charity paid (detail)'), __('Every charity line.'), [self::tile(__('Total'), (int) array_sum(array_map(fn ($r) => self::cents($r['amount']), $rows)), $cur)]];
    }

    private static function charityPeople(array $f, User $user): array
    {
        $cur = AdminDocument::currency();
        $rows = self::charityQuery($f, $user)->groupBy('c.name')->selectRaw('c.name, COUNT(*) n, SUM(c.amount) total, MAX(c.monthly_amount) monthly, MIN(d.doc_date) first_on, MAX(d.doc_date) last_on')->orderByDesc('total')->get()
            ->map(fn ($r) => ['name' => $r->name, 'times' => (int) $r->n, 'monthly' => (string) $r->monthly, 'total' => (string) $r->total, 'first' => $r->first_on, 'last' => $r->last_on])->all();
        $cols = [['key' => 'name', 'label' => __('Person / place')], ['key' => 'times', 'label' => __('Times'), 'type' => 'num'], ['key' => 'monthly', 'label' => __('Monthly amount'), 'type' => 'money'], ['key' => 'total', 'label' => __('Total paid'), 'type' => 'money'],
            ['key' => 'first', 'label' => __('First paid')], ['key' => 'last', 'label' => __('Last paid')]];

        return [$cols, $rows, __('Charity by person'), __('Each person or place.'), [self::tile(__('Total'), (int) array_sum(array_map(fn ($r) => self::cents($r['total']), $rows)), $cur)]];
    }
}
