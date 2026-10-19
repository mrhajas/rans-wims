<?php

namespace App\Services\Transport;

use App\Models\Contact;
use App\Models\TransportLoadingAttendance;
use App\Models\TransportLoadingPayment;
use Illuminate\Http\Request;

/**
 * The Loading & Unloading report: one place that turns the chosen filters into rows, totals and cards, so the page, the
 * printed copy, the PDF and the Excel file always show exactly the same thing.
 *
 * report_type: summary (one line per person), attendance (one line per day), payments (one line per payment) or
 * sheet (the attendance sheet of ONE day: every person, marked Present / Absent / not marked, ready to print and sign).
 */
class LoadingReport
{
    public const TYPES = ['summary', 'attendance', 'payments', 'sheet'];

    /** Rows kept for print / PDF / Excel; the page itself shows 15 at a time with View More. */
    public const ROW_CAP = 5000;

    public static function filters(Request $request): array
    {
        $type = $request->input('report_type');
        $f = [
            'report_type' => in_array($type, self::TYPES, true) ? $type : 'summary',
            'date_from' => self::date($request->input('date_from')) ?: now()->startOfMonth()->toDateString(),
            'date_to' => self::date($request->input('date_to')) ?: now()->toDateString(),
            'contact_id' => $request->filled('contact_id') ? (int) $request->input('contact_id') : null,
            'warehouse_id' => $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null,
            'place' => $request->filled('place') && is_string($request->input('place')) ? mb_substr(trim($request->input('place')), 0, 80) : null,
            'status' => is_string($request->input('status')) ? $request->input('status') : null,
            'custom_rate' => $request->boolean('custom_rate'),
            'only_owing' => $request->boolean('only_owing'),
        ];
        if ($f['report_type'] === 'sheet') {
            $f['date_from'] = $f['date_to'];
        }
        $allowed = match ($f['report_type']) {
            'attendance' => TransportLoadingAttendance::STATUSES,
            'payments' => [TransportLoadingPayment::PAID, TransportLoadingPayment::PENDING_VOUCHER],
            default => [],
        };
        if (! in_array($f['status'], $allowed, true)) {
            $f['status'] = null;
        }

        return $f;
    }

    /** @return array{filters: array, rows: array, totals: array, cards: array, columns: array, rate: float} */
    public static function build(array $f): array
    {
        $workerIds = LoadingLedger::workerIds();
        if ($f['contact_id'] && ! in_array($f['contact_id'], $workerIds, true)) {
            $workerIds = [];
        } elseif ($f['contact_id']) {
            $workerIds = [$f['contact_id']];
        }

        [$rows, $totals] = match ($f['report_type']) {
            'attendance' => self::attendance($f, $workerIds),
            'payments' => self::payments($f, $workerIds),
            'sheet' => self::sheet($f, $workerIds),
            default => self::summary($f, $workerIds),
        };

        return [
            'filters' => $f,
            'rows' => $rows,
            'totals' => $totals,
            'cards' => self::cards($f['report_type'], $totals),
            'rate' => LoadingLedger::rate(),
        ];
    }

    private static function summary(array $f, array $ids): array
    {
        $rows = collect(LoadingLedger::summary($f['date_from'], $f['date_to'], $f['warehouse_id']))
            ->filter(fn ($r) => in_array($r['contact_id'], $ids, true))
            // A person with nothing in the period and nothing owed is left out; anyone with activity or a balance stays.
            ->filter(fn ($r) => $r['days'] > 0 || $r['paid'] != 0 || $r['balance'] != 0 || $r['pending'] > 0 || $r['opening'] != 0)
            ->when($f['only_owing'], fn ($c) => $c->filter(fn ($r) => $r['balance'] > 0))
            ->values()->all();

        return [$rows, LoadingLedger::totals($rows)];
    }

    /** One day (the "to" date): every person, with what was saved for that day, or "not marked". */
    private static function sheet(array $f, array $ids): array
    {
        $day = $f['date_to'];
        $saved = TransportLoadingAttendance::query()->whereIn('contact_id', $ids ?: [0])->whereDate('work_date', $day)->with('warehouse:id,name')->get()->keyBy('contact_id');
        $standard = LoadingLedger::rate();

        $rows = Contact::query()->whereIn('id', $ids ?: [0])->orderBy('name')->get(['id', 'name', 'phone'])->map(function (Contact $c) use ($saved, $standard) {
            $a = $saved->get($c->id);

            return [
                'contact_id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'status' => $a?->status,
                'project' => $a?->warehouse?->name ?? $a?->other_place,
                'rate' => (float) ($a?->daily_rate ?? $standard),
                'standard' => (float) ($a?->standard_rate ?? $a?->daily_rate ?? $standard),
                'custom' => $a ? abs((float) $a->daily_rate - (float) ($a->standard_rate ?? $a->daily_rate)) > 0.001 : false,
                'amount' => (float) ($a?->amount ?? 0),
            ];
        })->values()->all();

        return [$rows, [
            'workers' => count($rows),
            'present' => collect($rows)->where('status', 'Present')->count(),
            'absent' => collect($rows)->where('status', 'Absent')->count(),
            'unmarked' => collect($rows)->whereNull('status')->count(),
            'earned' => round((float) collect($rows)->sum('amount'), 2),
        ]];
    }

    private static function attendance(array $f, array $ids): array
    {
        $q = TransportLoadingAttendance::query()->with(['contact:id,name,phone', 'warehouse:id,name'])->whereIn('contact_id', $ids ?: [0])
            ->whereDate('work_date', '>=', $f['date_from'])->whereDate('work_date', '<=', $f['date_to'])
            ->when($f['warehouse_id'], fn ($x) => $x->where('warehouse_id', $f['warehouse_id']))
            ->when($f['place'], fn ($x) => $x->where('other_place', $f['place']))
            ->when($f['status'], fn ($x) => $x->where('status', $f['status']))
            ->when($f['custom_rate'], fn ($x) => $x->whereColumn('daily_rate', '!=', 'standard_rate'))
            ->orderBy('work_date')->orderBy('contact_id');

        $all = $q->limit(self::ROW_CAP)->get();
        $rows = $all->map(fn ($a) => [
            'id' => $a->id,
            'date' => $a->work_date->toDateString(),
            'contact_id' => $a->contact_id,
            'name' => $a->contact?->name,
            'status' => $a->status,
            'project' => $a->warehouse?->name ?? $a->other_place,
            'rate' => (float) $a->daily_rate,
            'standard' => (float) ($a->standard_rate ?? $a->daily_rate),
            'custom' => abs((float) $a->daily_rate - (float) ($a->standard_rate ?? $a->daily_rate)) > 0.001,
            'amount' => (float) $a->amount,
        ])->values()->all();

        return [$rows, [
            'records' => count($rows),
            'present' => collect($rows)->where('status', 'Present')->count(),
            'absent' => collect($rows)->where('status', 'Absent')->count(),
            'earned' => round((float) collect($rows)->sum('amount'), 2),
            'custom' => collect($rows)->where('custom', true)->count(),
        ]];
    }

    private static function payments(array $f, array $ids): array
    {
        $all = TransportLoadingPayment::query()->with(['contact:id,name,phone', 'paidBy:id,name', 'voucher:id,pv_number,status'])->whereIn('contact_id', $ids ?: [0])
            ->whereDate('payment_date', '>=', $f['date_from'])->whereDate('payment_date', '<=', $f['date_to'])
            ->when($f['status'], fn ($x) => $x->where('status', $f['status']))
            ->orderBy('payment_date')->orderBy('id')->limit(self::ROW_CAP)->get();

        $rows = $all->map(fn ($p) => [
            'id' => $p->id,
            'date' => $p->payment_date->toDateString(),
            'contact_id' => $p->contact_id,
            'name' => $p->contact?->name,
            'amount' => (float) $p->amount,
            'status' => $p->status,
            'method' => $p->method,
            'reference' => $p->reference,
            'voucher' => $p->voucher?->pv_number,
            'voucher_id' => $p->payment_voucher_id,
            'by' => $p->paidBy?->name,
        ])->values()->all();

        return [$rows, [
            'records' => count($rows),
            'paid' => round((float) collect($rows)->where('status', TransportLoadingPayment::PAID)->sum('amount'), 2),
            'pending' => round((float) collect($rows)->where('status', TransportLoadingPayment::PENDING_VOUCHER)->sum('amount'), 2),
            'vouchers' => collect($rows)->pluck('voucher_id')->filter()->unique()->count(),
        ]];
    }

    private static function cards(string $type, array $t): array
    {
        return match ($type) {
            'attendance' => [
                ['key' => 'records', 'label' => 'Days recorded', 'value' => $t['records'], 'money' => false, 'icon' => 'calendar', 'tone' => 'indigo'],
                ['key' => 'present', 'label' => 'Days present', 'value' => $t['present'], 'money' => false, 'icon' => 'tick', 'tone' => 'emerald'],
                ['key' => 'absent', 'label' => 'Days absent', 'value' => $t['absent'], 'money' => false, 'icon' => 'cross', 'tone' => 'rose'],
                ['key' => 'earned', 'label' => 'Earned', 'value' => $t['earned'], 'money' => true, 'icon' => 'cash', 'tone' => 'sky'],
                ['key' => 'custom', 'label' => 'Days at a custom rate', 'value' => $t['custom'], 'money' => false, 'icon' => 'exclamation', 'tone' => 'amber'],
            ],
            'sheet' => [
                ['key' => 'workers', 'label' => 'Personnel', 'value' => $t['workers'], 'money' => false, 'icon' => 'users', 'tone' => 'indigo'],
                ['key' => 'present', 'label' => 'Present', 'value' => $t['present'], 'money' => false, 'icon' => 'tick', 'tone' => 'emerald'],
                ['key' => 'absent', 'label' => 'Absent', 'value' => $t['absent'], 'money' => false, 'icon' => 'cross', 'tone' => 'rose'],
                ['key' => 'unmarked', 'label' => 'Not marked', 'value' => $t['unmarked'], 'money' => false, 'icon' => 'clock', 'tone' => 'amber'],
                ['key' => 'earned', 'label' => 'Earned that day', 'value' => $t['earned'], 'money' => true, 'icon' => 'cash', 'tone' => 'sky'],
            ],
            'payments' => [
                ['key' => 'records', 'label' => 'Payments', 'value' => $t['records'], 'money' => false, 'icon' => 'doc-text', 'tone' => 'indigo'],
                ['key' => 'paid', 'label' => 'Paid', 'value' => $t['paid'], 'money' => true, 'icon' => 'tick', 'tone' => 'emerald'],
                ['key' => 'pending', 'label' => 'Waiting on a voucher', 'value' => $t['pending'], 'money' => true, 'icon' => 'clock', 'tone' => 'amber'],
                ['key' => 'vouchers', 'label' => 'Vouchers used', 'value' => $t['vouchers'], 'money' => false, 'icon' => 'doc', 'tone' => 'sky'],
            ],
            default => [
                ['key' => 'workers', 'label' => 'Personnel', 'value' => $t['workers'], 'money' => false, 'icon' => 'users', 'tone' => 'indigo'],
                ['key' => 'opening', 'label' => 'Previous balance', 'value' => $t['opening'], 'money' => true, 'icon' => 'clock', 'tone' => 'amber'],
                ['key' => 'days', 'label' => 'Days present', 'value' => $t['days'], 'money' => false, 'icon' => 'calendar', 'tone' => 'sky'],
                ['key' => 'earned', 'label' => 'Earned', 'value' => $t['earned'], 'money' => true, 'icon' => 'cash', 'tone' => 'emerald'],
                ['key' => 'paid', 'label' => 'Paid', 'value' => $t['paid'], 'money' => true, 'icon' => 'tick', 'tone' => 'violet'],
                ['key' => 'balance', 'label' => 'Balance owed', 'value' => $t['balance'], 'money' => true, 'icon' => 'exclamation', 'tone' => 'amber'],
            ],
        };
    }

    /** Column titles + a row mapper used by the Excel file (keys follow the report type). */
    public static function excelRows(array $report): array
    {
        $rows = collect($report['rows']);

        return match ($report['filters']['report_type']) {
            'attendance' => $rows->map(fn ($r) => ['Date' => $r['date'], 'Personnel' => $r['name'], 'Status' => $r['status'], 'Project / site' => $r['project'], 'Daily rate' => $r['rate'], 'Standard rate' => $r['standard'], 'Custom rate' => $r['custom'] ? 'Yes' : 'No', 'Earned' => $r['amount']])->all(),
            'sheet' => $rows->map(fn ($r) => ['Date' => $report['filters']['date_to'], 'Personnel' => $r['name'], 'Phone' => $r['phone'], 'Status' => $r['status'] ?? 'Not marked', 'Daily rate' => $r['rate'], 'Earned' => $r['amount']])->all(),
            'payments' => $rows->map(fn ($r) => ['Date' => $r['date'], 'Personnel' => $r['name'], 'Amount' => $r['amount'], 'Status' => $r['status'], 'Method' => $r['method'], 'Reference' => $r['reference'], 'Payment Voucher' => $r['voucher'], 'Recorded by' => $r['by']])->all(),
            default => $rows->map(fn ($r) => ['Personnel' => $r['name'], 'Phone' => $r['phone'], 'Previous balance' => $r['opening'], 'Days present' => $r['days'], 'Earned' => $r['earned'], 'Paid' => $r['paid'], 'On voucher' => $r['pending'], 'Balance' => $r['balance']])->all(),
        };
    }

    private static function date($v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : null;
    }
}
