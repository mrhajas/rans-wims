<?php

namespace App\Services\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctAuditEvent;
use App\Models\Accounts\AcctExchangeRate;
use App\Models\Accounts\AcctReconciliation;
use App\Models\Accounts\AcctReconItem;
use App\Models\Accounts\AcctTransaction;
use App\Models\Accounts\AcctTransfer;
use App\Models\User;
use App\Support\Accounts\Money;
use Illuminate\Support\Facades\DB;

/**
 * The Accounts Tracking reports. Each report is a definition (title, group, which filters it uses, columns) plus a closure that returns plain rows.
 * Amounts in different currencies are never added together: totals are always given per currency.
 */
class AcctReports
{
    public const LIMIT_VIEW = 300;

    /** Rows in a printed page / PDF. A PDF of thousands of rows is slow and hard to read; the full list is a CSV or Excel download (LIMIT_EXPORT). */
    public const LIMIT_PRINT = 500;

    public const LIMIT_EXPORT = 50000;

    /** Report groups shown on the Reports page. */
    public const GROUPS = ['balances' => 'Balances', 'ledger' => 'Transactions', 'transfers' => 'Transfers and currency', 'control' => 'Approvals and control', 'reconciliation' => 'Reconciliation', 'accounts' => 'Accounts and activity'];

    public static function catalog(User $u): array
    {
        $out = [];
        foreach (self::defs() as $k => $d) {
            if (($d['ability'] ?? null) && ! $u->can($d['ability'])) {
                continue;
            }
            if (! empty($d['hidden'])) {
                continue;
            }
            $out[] = ['key' => $k, 'group' => $d['group'], 'group_label' => __(self::GROUPS[$d['group']]), 'title' => __($d['title']), 'about' => __($d['about']), 'filters' => $d['filters']];
        }

        return $out;
    }

    public static function exists(string $key): bool
    {
        return isset(self::defs()[$key]);
    }

    /**
     * @param array<string,mixed> $f  filters (account_id, from, to, currency, status, category_id, as_of, days, direction, user_id)
     * @param ?array<int> $ids        account ids the viewer may see (null = all)
     */
    public static function build(User $u, string $key, array $f, ?array $ids, int $limit = self::LIMIT_VIEW): array
    {
        $d = self::defs()[$key] ?? abort(404);
        abort_if(($d['ability'] ?? null) && ! $u->can($d['ability']), 403);
        $f = self::normalize($f);
        $ctx = (object) ['f' => $f, 'ids' => $ids, 'accts' => AcctAccount::query()->pluck('name', 'id'), 'prec' => fn (?string $c) => AcctRates::precision((string) $c ?: 'TZS')];
        $rows = collect($d['run']($ctx))->values();
        $total = $rows->count();
        $kept = $rows->take($limit);

        $cols = array_map(fn ($c) => $c + ['align' => in_array($c['type'] ?? 'text', ['money', 'number'], true) ? 'right' : 'left'], $d['columns']);
        $money = array_values(array_filter($cols, fn ($c) => ($c['type'] ?? '') === 'money' && ! empty($c['sum'])));
        $totals = [];
        foreach ($rows as $r) {
            foreach ($money as $m) {
                $cur = (string) ($r[$m['cur'] ?? 'currency'] ?? '');
                if ($cur !== '' && isset($r[$m['key']]) && $r[$m['key']] !== '' && $r[$m['key']] !== null) {
                    $totals[$cur][$m['key']] = Money::add($totals[$cur][$m['key']] ?? '0', (string) $r[$m['key']]);
                }
            }
        }
        $tiles = [['label' => __('Rows'), 'value' => $total]];
        foreach ($totals as $cur => $byKey) {
            foreach ($byKey as $k => $v) {
                $label = collect($cols)->firstWhere('key', $k)['label'] ?? $k;
                $tiles[] = ['label' => $label . ' (' . $cur . ')', 'value' => Money::format($v, AcctRates::precision($cur))];
            }
        }
        $display = $kept->map(function ($r) use ($cols) {
            $o = [];
            foreach ($cols as $c) {
                $v = $r[$c['key']] ?? '';
                if (($c['type'] ?? '') === 'money' && $v !== '' && $v !== null) {
                    $v = Money::format((string) $v, AcctRates::precision((string) ($r[$c['cur'] ?? 'currency'] ?? 'TZS')));
                }
                $o[$c['key']] = is_bool($v) ? ($v ? __('Yes') : __('No')) : $v;
            }

            return $o;
        })->all();

        return [
            'signing' => ['type' => 'acct_report'], 'type' => $key, 'title' => __($d['title']), 'reference' => 'ACR-' . now()->format('Ymd-His'), 'subtitle' => __($d['about']), 'table_title' => __($d['title']),
            'filters' => self::filterText($f, $d['filters'], $ctx->accts), 'tiles' => array_slice($tiles, 0, 12), 'columns' => $cols, 'rows' => $display, 'raw' => $kept->all(),
            'total_rows' => $total, 'truncated' => $total > $limit, 'sections' => [],
            'notes' => [__('Only posted transactions change balances. Draft, submitted and rejected items are shown only where a report says so.'), __('Amounts in different currencies are never added together: totals are shown for each currency.'),
                __('Exchange rates shown are the approved rates kept on each transaction when it was recorded.'), __('Figures are drawn from the ledger at the time of generation; later postings will change them.')]
                + ($total > $limit && $limit === self::LIMIT_PRINT ? [4 => __('Only the first :n of :t rows are on this page. Download the CSV or Excel file to get every row.', ['n' => number_format($limit), 't' => number_format($total)])] : []),
        ];
    }

    /**
     * A cell for a spreadsheet: text that starts with = + - @ is a formula when opened, so it gets a leading apostrophe. A plain number such as -400.0000
     * (a negative balance or difference) is not text and is left alone, otherwise it would reach Excel as text and could not be added up.
     */
    public static function sheetSafe($v)
    {
        if (! is_string($v) || $v === '' || preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return $v;
        }

        return strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
    }

    public static function landscape(array $r): bool
    {
        return count($r['columns']) > 6;
    }

    private static function normalize(array $f): array
    {
        $f['from'] = ! empty($f['from']) && strtotime($f['from']) ? date('Y-m-d', strtotime($f['from'])) : now()->startOfMonth()->toDateString();
        $f['to'] = ! empty($f['to']) && strtotime($f['to']) ? date('Y-m-d', strtotime($f['to'])) : now()->toDateString();
        $f['as_of'] = ! empty($f['as_of']) && strtotime($f['as_of']) ? date('Y-m-d', strtotime($f['as_of'])) : now()->toDateString();
        $f['days'] = max(1, min(3650, (int) ($f['days'] ?? 60)));
        $f['currency'] = preg_match('/^[A-Z]{3}$/', (string) ($f['currency'] ?? '')) ? $f['currency'] : null;
        $f['account_id'] = ctype_digit((string) ($f['account_id'] ?? '')) && $f['account_id'] !== '' ? (int) $f['account_id'] : null;
        $f['user_id'] = ctype_digit((string) ($f['user_id'] ?? '')) && $f['user_id'] !== '' ? (int) $f['user_id'] : null;
        $f['category_id'] = ctype_digit((string) ($f['category_id'] ?? '')) && $f['category_id'] !== '' ? (int) $f['category_id'] : null;
        $f['status'] = (string) ($f['status'] ?? '');
        $f['group'] = in_array($f['group'] ?? '', ['bank', 'cash', 'other'], true) ? $f['group'] : null;
        $f['q'] = mb_substr(trim((string) ($f['q'] ?? '')), 0, 100);
        $f['record_id'] = ctype_digit((string) ($f['record_id'] ?? '')) && $f['record_id'] !== '' ? (int) $f['record_id'] : null;
        $f['direction'] = in_array($f['direction'] ?? '', ['in', 'out'], true) ? $f['direction'] : null;

        return $f;
    }

    private static function filterText(array $f, array $used, $accts): array
    {
        $o = [];
        foreach ($used as $k) {
            $o[match ($k) { 'account_id' => __('Account'), 'from' => __('From'), 'to' => __('To'), 'currency' => __('Currency'), 'status' => __('Status'), 'as_of' => __('As of'), 'days' => __('Days'), 'direction' => __('Direction'), 'user_id' => __('User'), 'category_id' => __('Category'), 'group' => __('Accounts'), 'q' => __('Search'), default => $k }] =
                match ($k) { 'account_id' => $f['account_id'] ? ($accts[$f['account_id']] ?? '#' . $f['account_id']) : __('All'), 'user_id' => $f['user_id'] ? (User::find($f['user_id'])->name ?? '#' . $f['user_id']) : __('All'), 'category_id' => $f['category_id'] ? (\App\Models\Accounts\AcctCategory::find($f['category_id'])->name ?? '-') : __('All'),
                    'direction' => $f['direction'] ? ($f['direction'] === 'in' ? __('Money in') : __('Money out')) : __('All'), 'currency' => $f['currency'] ?: __('All'), 'status' => $f['status'] ?: __('All'), 'group' => $f['group'] ? ['bank' => __('Bank accounts'), 'cash' => __('Cash in hand'), 'other' => __('Other')][$f['group']] : __('All'), 'q' => $f['q'] ?: '-', default => (string) ($f[$k] ?? '') };
        }

        return $o;
    }

    // ---------------------------------------------------------------------------------------------

    private static function acctQ($ctx, $q, string $col = 'account_id')
    {
        return $ctx->ids === null ? $q : $q->whereIn($col, $ctx->ids ?: [0]);
    }

    private static function txns($ctx, bool $ledgerOnly = true)
    {
        $f = $ctx->f;

        return self::acctQ($ctx, AcctTransaction::query())
            ->when($ledgerOnly, fn ($q) => $q->whereIn('status', AcctTransaction::LEDGER))
            ->when($f['account_id'], fn ($q, $v) => $q->where('account_id', $v))->when($f['currency'], fn ($q, $v) => $q->where('currency', $v))->when($f['direction'], fn ($q, $v) => $q->where('direction', $v))
            ->when($f['category_id'], fn ($q, $v) => $q->where('category_id', $v))->when($f['user_id'], fn ($q, $v) => $q->where('created_by', $v))
            ->whereRaw(self::dateSql() . ' between ? and ?', [$f['from'], $f['to']]);
    }

    /** The effective date of a ledger row: the same rule (transaction date or value date) the balances use, so a report and a balance never disagree. */
    private static function dateSql(): string
    {
        return app(AcctBalance::class)->dateColumn();
    }

    private static function accounts($ctx, bool $all = false)
    {
        $f = $ctx->f;

        $like = fn (string $v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';

        return self::acctQ($ctx, AcctAccount::query(), 'id')->when(! $all && ! in_array($f['status'], ['active', 'inactive', 'suspended', 'closed'], true), fn ($q) => $q->where('status', '<>', 'closed'))
            ->when(in_array($f['status'], ['active', 'inactive', 'suspended', 'closed'], true), fn ($q) => $q->where('status', $f['status']))
            ->when($f['group'], fn ($q, $g) => $q->whereIn('kind', AcctAccount::GROUPS[$g]))
            ->when($f['q'] !== '', fn ($q) => $q->where(fn ($x) => $x->where('name', 'like', $like($f['q']))->orWhere('code', 'like', $like($f['q']))->orWhere('institution', 'like', $like($f['q']))))->when($f['account_id'], fn ($q, $v) => $q->where('id', $v))->when($f['currency'], fn ($q, $v) => $q->where('currency', $v))->orderBy('kind')->orderBy('name');
    }

    private static function txRow(AcctTransaction $t, $ctx): array
    {
        return ['date' => $t->txn_date->toDateString(), 'reference' => $t->reference, 'account' => $ctx->accts[$t->account_id] ?? '-', 'kind' => ucfirst($t->kind), 'direction' => $t->direction === 'in' ? __('In') : __('Out'), 'description' => $t->description, 'counterparty' => $t->counterparty_name,
            'amount' => (string) $t->amount, 'currency' => $t->currency, 'status' => __(AcctTransaction::STATUS[$t->status] ?? $t->status), 'reconciled' => $t->reconciled_at ? __('Yes') : __('No')];
    }

    private static function txCols(): array
    {
        return [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'account', 'label' => 'Account'], ['key' => 'direction', 'label' => 'In/Out'], ['key' => 'description', 'label' => 'Description'], ['key' => 'counterparty', 'label' => 'Counterparty'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'status', 'label' => 'Status']];
    }

    private static function grouped($ctx, string $dir)
    {
        $names = \App\Models\Accounts\AcctCategory::query()->pluck('name', 'id');

        return AcctTransaction::operating(self::txns($ctx)->where('direction', $dir))
            ->selectRaw('category_id, currency, COUNT(*) as n, SUM(amount) as total')->groupBy('category_id', 'currency')->orderByDesc('total')->get()
            ->map(fn ($r) => ['category' => $r->category_id ? ($names[$r->category_id] ?? '#' . $r->category_id) : __('Uncategorised'), 'currency' => $r->currency, 'count' => (int) $r->n, 'amount' => (string) $r->total]);
    }

    public static function defs(): array
    {
        static $defs = null;

        return $defs ??= [
            'account_balances' => ['group' => 'balances', 'title' => 'Account balances', 'about' => 'Current balance of every account, in its own currency.', 'filters' => ['account_id', 'currency', 'group', 'status', 'q'],
                'columns' => [['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Account'], ['key' => 'type', 'label' => 'Type'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money', 'sum' => true], ['key' => 'min', 'label' => 'Minimum', 'type' => 'money'], ['key' => 'status', 'label' => 'Status']],
                'run' => fn ($c) => self::accounts($c)->get()->map(fn ($a) => ['code' => $a->code, 'name' => $a->name, 'type' => AcctAccount::KINDS[$a->kind] ?? $a->kind, 'currency' => $a->currency, 'balance' => (string) $a->balance, 'min' => $a->min_balance !== null ? (string) $a->min_balance : null, 'status' => ucfirst($a->status)])],

            'balances_by_currency' => ['group' => 'balances', 'title' => 'Totals by currency', 'about' => 'Total held in each currency. Currencies are never added together.', 'filters' => [],
                'columns' => [['key' => 'currency', 'label' => 'Currency'], ['key' => 'accounts', 'label' => 'Accounts', 'type' => 'number'], ['key' => 'total', 'label' => 'Total', 'type' => 'money']],
                'run' => fn ($c) => self::accounts($c)->get()->groupBy('currency')->map(fn ($g, $cur) => ['currency' => $cur, 'accounts' => $g->count(), 'total' => $g->reduce(fn ($s, $a) => Money::add($s, (string) $a->balance), '0')])->values()],

            'balances_by_type' => ['group' => 'balances', 'title' => 'Balances by account type', 'about' => 'Bank, cash and other accounts, each currency separately.', 'filters' => ['currency'],
                'columns' => [['key' => 'group', 'label' => 'Type'], ['key' => 'currency', 'label' => 'Currency'], ['key' => 'accounts', 'label' => 'Accounts', 'type' => 'number'], ['key' => 'total', 'label' => 'Total', 'type' => 'money', 'sum' => true]],
                'run' => fn ($c) => self::accounts($c)->get()->groupBy(fn ($a) => AcctAccount::groupOf($a->kind) . '|' . $a->currency)->map(function ($g, $k) {
                    [$grp, $cur] = explode('|', $k);

                    return ['group' => ['bank' => 'Bank accounts', 'cash' => 'Cash accounts', 'other' => 'Other accounts'][$grp] ?? $grp, 'currency' => $cur, 'accounts' => $g->count(), 'total' => $g->reduce(fn ($s, $a) => Money::add($s, (string) $a->balance), '0')];
                })->values()],

            'balances_as_of' => ['group' => 'balances', 'title' => 'Balance on a past date', 'about' => 'What each account held at the end of the chosen day, rebuilt from the ledger.', 'filters' => ['as_of', 'account_id', 'currency'],
                'columns' => [['key' => 'name', 'label' => 'Account'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money', 'sum' => true], ['key' => 'now', 'label' => 'Balance now', 'type' => 'money']],
                'run' => function ($c) {
                    $accts = self::accounts($c, true)->get();
                    $asOf = app(AcctBalance::class)->asOfMany($accts, $c->f['as_of']); // one query for all the accounts

                    return $accts->map(fn ($a) => ['name' => $a->name, 'currency' => $a->currency, 'balance' => $asOf[$a->id], 'now' => (string) $a->balance]);
                }],

            'account_statement' => ['group' => 'balances', 'title' => 'Account statement', 'about' => 'Every posted movement on one account with a running balance. Choose the account.', 'filters' => ['account_id', 'from', 'to'],
                'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'description', 'label' => 'Description'], ['key' => 'in', 'label' => 'In', 'type' => 'money'], ['key' => 'out', 'label' => 'Out', 'type' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'], ['key' => 'currency', 'label' => 'Cur']],
                'run' => function ($c) {
                    if (! $c->f['account_id']) {
                        return [];
                    }
                    $a = self::accounts($c, true)->first();
                    if (! $a) {
                        return [];
                    }
                    $bal = app(AcctBalance::class);
                    $run = $bal->asOf($a, \Carbon\Carbon::parse($c->f['from'])->subDay()->toDateString());
                    $rows = [['date' => $c->f['from'], 'reference' => '', 'description' => __('Opening balance'), 'in' => null, 'out' => null, 'balance' => $run, 'currency' => $a->currency]];
                    // the account's own opening balance counts from its opening date (as in AcctBalance::asOf): when that day falls inside the statement it is a line of it
                    $openOn = $a->opening_date?->toDateString();
                    $openLine = $openOn !== null && $openOn >= $c->f['from'] && $openOn <= $c->f['to'] && Money::cmp((string) $a->opening_balance, '0') !== 0;
                    $entries = AcctTransaction::query()->selectRaw('acct_transactions.*, ' . self::dateSql() . ' as eff_date')->where('account_id', $a->id)->whereIn('status', AcctTransaction::LEDGER)->whereRaw(self::dateSql() . ' between ? and ?', [$c->f['from'], $c->f['to']])->orderByRaw(self::dateSql())->orderBy('id')->get();
                    $line = function (string $date, string $ref, ?string $desc, string $dir, string $amt) use (&$run, $a) {
                        $run = $dir === 'in' ? Money::add($run, $amt) : Money::sub($run, $amt);

                        return ['date' => $date, 'reference' => $ref, 'description' => $desc, 'in' => $dir === 'in' ? $amt : null, 'out' => $dir === 'out' ? $amt : null, 'balance' => $run, 'currency' => $a->currency];
                    };
                    foreach ($entries as $t) {
                        if ($openLine && (string) $t->eff_date >= $openOn) {
                            $neg = Money::cmp((string) $a->opening_balance, '0') < 0;
                            $rows[] = $line($openOn, '', __('Account opening balance'), $neg ? 'out' : 'in', ltrim((string) $a->opening_balance, '-'));
                            $openLine = false;
                        }
                        $rows[] = $line((string) $t->eff_date, $t->reference, $t->description, $t->direction, (string) $t->amount);
                    }
                    if ($openLine) {
                        $neg = Money::cmp((string) $a->opening_balance, '0') < 0;
                        $rows[] = $line($openOn, '', __('Account opening balance'), $neg ? 'out' : 'in', ltrim((string) $a->opening_balance, '-'));
                    }

                    return $rows;
                }],

            'transaction_register' => ['group' => 'ledger', 'title' => 'Transaction register', 'about' => 'All transactions in the period, with their status.', 'filters' => ['from', 'to', 'account_id', 'currency', 'direction', 'status', 'user_id'],
                'columns' => self::txCols(), 'run' => fn ($c) => self::txns($c, false)->when($c->f['status'], fn ($q, $v) => $q->where('status', $v))->orderBy('txn_date')->orderBy('id')->limit(self::LIMIT_EXPORT)->get()->map(fn ($t) => self::txRow($t, $c))],

            'inflows_by_category' => ['group' => 'ledger', 'title' => 'Money in by category', 'about' => 'Posted receipts grouped by category. Transfers between accounts are not income.', 'filters' => ['from', 'to', 'account_id', 'currency'],
                'columns' => [['key' => 'category', 'label' => 'Category'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'count', 'label' => 'Count', 'type' => 'number'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'sum' => true]], 'run' => fn ($c) => self::grouped($c, 'in')],

            'outflows_by_category' => ['group' => 'ledger', 'title' => 'Money out by category', 'about' => 'Posted payments grouped by category. Transfers between accounts are not expenses.', 'filters' => ['from', 'to', 'account_id', 'currency'],
                'columns' => [['key' => 'category', 'label' => 'Category'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'count', 'label' => 'Count', 'type' => 'number'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'sum' => true]], 'run' => fn ($c) => self::grouped($c, 'out')],

            'income_expense' => ['group' => 'ledger', 'title' => 'Money in and out by month', 'about' => 'Receipts, payments and the net movement for each month and currency.', 'filters' => ['from', 'to', 'account_id', 'currency'],
                'columns' => [['key' => 'month', 'label' => 'Month'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'in', 'label' => 'In', 'type' => 'money', 'sum' => true], ['key' => 'out', 'label' => 'Out', 'type' => 'money', 'sum' => true], ['key' => 'net', 'label' => 'Net', 'type' => 'money', 'sum' => true]],
                'run' => fn ($c) => AcctTransaction::operating(self::txns($c))->selectRaw("DATE_FORMAT(" . self::dateSql() . ",'%Y-%m') as m, currency, SUM(CASE WHEN direction='in' THEN amount ELSE 0 END) as i, SUM(CASE WHEN direction='out' THEN amount ELSE 0 END) as o")->groupBy('m', 'currency')->orderBy('m')->get()
                    ->map(fn ($r) => ['month' => $r->m, 'currency' => $r->currency, 'in' => (string) $r->i, 'out' => (string) $r->o, 'net' => Money::sub((string) $r->i, (string) $r->o)])],

            'payments_by_method' => ['group' => 'ledger', 'title' => 'Payments by method', 'about' => 'Posted payments by how they were paid (cash, bank transfer, cheque, mobile money ...).', 'filters' => ['from', 'to', 'account_id', 'currency'],
                'columns' => [['key' => 'method', 'label' => 'Method'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'count', 'label' => 'Count', 'type' => 'number'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'sum' => true]],
                'run' => fn ($c) => AcctTransaction::operating(self::txns($c)->where('direction', 'out'))->selectRaw('COALESCE(payment_method, ?) as m, currency, COUNT(*) n, SUM(amount) total', ['not stated'])->groupBy('m', 'currency')->orderByDesc('total')->get()
                    ->map(fn ($r) => ['method' => ucfirst(str_replace('_', ' ', $r->m)), 'currency' => $r->currency, 'count' => (int) $r->n, 'amount' => (string) $r->total])],

            'daily_cash' => ['group' => 'ledger', 'title' => 'Daily cash movement', 'about' => 'Cash accounts: money in, money out and net for each day.', 'filters' => ['from', 'to', 'account_id', 'currency'],
                'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'in', 'label' => 'In', 'type' => 'money', 'sum' => true], ['key' => 'out', 'label' => 'Out', 'type' => 'money', 'sum' => true], ['key' => 'net', 'label' => 'Net', 'type' => 'money', 'sum' => true]],
                'run' => fn ($c) => self::txns($c)->whereIn('account_id', AcctAccount::query()->whereNotIn('kind', ['bank_account', 'mobile_money', 'other'])->pluck('id')->all() ?: [0])->selectRaw(self::dateSql() . " d, currency, SUM(CASE WHEN direction='in' THEN amount ELSE 0 END) i, SUM(CASE WHEN direction='out' THEN amount ELSE 0 END) o")->groupBy('d', 'currency')->orderBy('d')->get()
                    ->map(fn ($r) => ['date' => (string) $r->d, 'currency' => $r->currency, 'in' => (string) $r->i, 'out' => (string) $r->o, 'net' => Money::sub((string) $r->i, (string) $r->o)])],

            'cash_position' => ['group' => 'reconciliation', 'title' => 'Cash in hand position', 'about' => 'Each cash account: balance, last physical count and the difference found.', 'filters' => ['currency'],
                'columns' => [['key' => 'name', 'label' => 'Account'], ['key' => 'custodian', 'label' => 'Custodian'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money', 'sum' => true], ['key' => 'last', 'label' => 'Last count', 'type' => 'date'], ['key' => 'diff', 'label' => 'Difference found', 'type' => 'money']],
                'run' => function ($c) {
                    $users = User::query()->pluck('name', 'id');

                    $accts = self::accounts($c)->whereNotIn('kind', ['bank_account', 'other'])->get();
                    // the latest approved cash count of each account, read together (the newest period, then the newest record)
                    $counts = AcctReconciliation::query()->whereIn('account_id', $accts->pluck('id')->all() ?: [0])->where('kind', 'cash')->where('status', 'approved')->orderBy('period_to')->orderBy('id')->get()->keyBy('account_id');

                    return $accts->map(function ($a) use ($users, $counts) {
                        $r = $counts[$a->id] ?? null;

                        return ['name' => $a->name, 'custodian' => $users[$a->custodian_user_id] ?? '-', 'currency' => $a->currency, 'balance' => (string) $a->balance, 'last' => $r?->period_to?->toDateString(), 'diff' => $r ? (string) $r->difference : null];
                    });
                }],

            'transfers_register' => ['group' => 'transfers', 'title' => 'Transfers between accounts', 'about' => 'Every transfer with both amounts, the rate used and its status.', 'filters' => ['from', 'to', 'account_id', 'status'],
                'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'from', 'label' => 'From'], ['key' => 'to', 'label' => 'To'], ['key' => 'amount', 'label' => 'Sent', 'type' => 'money', 'cur' => 'currency'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'received', 'label' => 'Received', 'type' => 'money', 'cur' => 'to_currency'], ['key' => 'to_currency', 'label' => 'Cur'], ['key' => 'rate', 'label' => 'Rate'], ['key' => 'status', 'label' => 'Status']],
                'run' => fn ($c) => self::transfersQ($c)->orderBy('transfer_date')->orderBy('id')->limit(self::LIMIT_EXPORT)->get()->map(fn ($t) => self::trRow($t, $c))],

            'cross_currency_transfers' => ['group' => 'transfers', 'title' => 'Currency conversion transfers', 'about' => 'Transfers between accounts of different currencies, with the rate and the exchange difference.', 'filters' => ['from', 'to', 'account_id', 'status'],
                'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'from', 'label' => 'From'], ['key' => 'to', 'label' => 'To'], ['key' => 'amount', 'label' => 'Sent', 'type' => 'money', 'cur' => 'currency'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'received', 'label' => 'Received', 'type' => 'money', 'cur' => 'to_currency'], ['key' => 'to_currency', 'label' => 'Cur'], ['key' => 'rate', 'label' => 'Rate'], ['key' => 'diff', 'label' => 'Exchange difference', 'type' => 'money', 'cur' => 'to_currency'], ['key' => 'status', 'label' => 'Status']],
                'run' => fn ($c) => self::transfersQ($c)->whereColumn('from_currency', '<>', 'to_currency')->orderBy('transfer_date')->orderBy('id')->get()->map(fn ($t) => self::trRow($t, $c))],

            'exchange_differences' => ['group' => 'transfers', 'title' => 'Exchange differences', 'about' => 'Posted transfers where the amount credited differs from amount x rate.', 'filters' => ['from', 'to'],
                'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'rate', 'label' => 'Rate'], ['key' => 'diff', 'label' => 'Difference', 'type' => 'money', 'cur' => 'to_currency', 'sum' => true], ['key' => 'to_currency', 'label' => 'Cur']],
                'run' => fn ($c) => self::transfersQ($c)->where('status', 'posted')->where('rounding_difference', '<>', 0)->orderBy('transfer_date')->get()->map(fn ($t) => self::trRow($t, $c))],

            'exchange_rates' => ['group' => 'transfers', 'title' => 'Exchange rate register', 'about' => 'Rates entered, who approved them and when they apply.', 'filters' => ['currency', 'status'],
                'columns' => [['key' => 'pair', 'label' => 'Pair'], ['key' => 'rate', 'label' => 'Rate'], ['key' => 'type', 'label' => 'Type'], ['key' => 'from', 'label' => 'From', 'type' => 'date'], ['key' => 'to', 'label' => 'To', 'type' => 'date'], ['key' => 'status', 'label' => 'Status'], ['key' => 'by', 'label' => 'Entered by'], ['key' => 'approved', 'label' => 'Approved by']],
                'run' => function ($c) {
                    $u = User::query()->pluck('name', 'id');

                    return AcctExchangeRate::query()->when($c->f['currency'], fn ($q, $v) => $q->where(fn ($x) => $x->where('base_currency', $v)->orWhere('quote_currency', $v)))->when(in_array($c->f['status'], ['pending', 'approved', 'rejected'], true), fn ($q) => $q->where('status', $c->f['status']))->orderByDesc('rate_at')->get()
                        ->map(fn ($r) => ['pair' => $r->base_currency . '/' . $r->quote_currency, 'rate' => rtrim(rtrim((string) $r->rate, '0'), '.'), 'type' => ucfirst($r->rate_type), 'from' => $r->effective_from->toDateString(), 'to' => optional($r->effective_to)->toDateString(), 'status' => ucfirst($r->status), 'by' => $u[$r->entered_by] ?? '-', 'approved' => $u[$r->approved_by] ?? '-']);
                }],

            'pending_approvals' => ['group' => 'control', 'title' => 'Pending approvals', 'about' => 'Transactions and transfers submitted or approved but not yet posted, with how long they have waited.', 'filters' => ['account_id', 'currency'],
                'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'account', 'label' => 'Account'], ['key' => 'description', 'label' => 'Description'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'sum' => true], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'status', 'label' => 'Status'], ['key' => 'waiting', 'label' => 'Waiting (hours)', 'type' => 'number']],
                'run' => function ($c) {
                    $a = self::acctQ($c, AcctTransaction::query())->whereNull('transfer_id')->whereIn('status', ['submitted', 'approved'])->when($c->f['account_id'], fn ($q, $v) => $q->where('account_id', $v))->when($c->f['currency'], fn ($q, $v) => $q->where('currency', $v))->get()
                        ->map(fn ($t) => ['type' => $t->direction === 'in' ? 'Receipt' : 'Payment', 'reference' => $t->reference, 'account' => $c->accts[$t->account_id] ?? '-', 'description' => $t->description, 'amount' => (string) $t->amount, 'currency' => $t->currency, 'status' => ucfirst($t->status), 'waiting' => (int) ($t->submitted_at ? $t->submitted_at->diffInHours(now()) : 0)]);
                    $b = self::transfersQ($c, false)->whereIn('status', ['submitted', 'approved'])->get()->map(fn ($t) => ['type' => 'Transfer', 'reference' => $t->reference, 'account' => ($c->accts[$t->from_account_id] ?? '-') . ' to ' . ($c->accts[$t->to_account_id] ?? '-'), 'description' => $t->reason, 'amount' => (string) $t->from_amount, 'currency' => $t->from_currency, 'status' => ucfirst($t->status), 'waiting' => (int) ($t->submitted_at ? $t->submitted_at->diffInHours(now()) : 0)]);

                    return $a->concat($b)->sortByDesc('waiting')->values();
                }],

            'rejected_cancelled' => ['group' => 'control', 'title' => 'Rejected and cancelled', 'about' => 'Transactions that were rejected or cancelled, with the reason.', 'filters' => ['from', 'to', 'account_id', 'currency'],
                'columns' => array_merge(self::txCols(), [['key' => 'reason_text', 'label' => 'Reason']]),
                'run' => fn ($c) => self::txns($c, false)->whereIn('status', ['rejected', 'cancelled'])->orderBy('txn_date')->get()->map(fn ($t) => self::txRow($t, $c) + ['reason_text' => $t->status === 'rejected' ? $t->reject_reason : $t->cancel_reason])],

            'reversals' => ['group' => 'control', 'title' => 'Reversals', 'about' => 'Corrections made by reversal: the original and the reversing entry stay linked.', 'filters' => ['from', 'to', 'account_id', 'currency'],
                'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'reference', 'label' => 'Reversal'], ['key' => 'original', 'label' => 'Original'], ['key' => 'account', 'label' => 'Account'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'sum' => true], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'reason_text', 'label' => 'Reason'], ['key' => 'by', 'label' => 'By']],
                'run' => function ($c) {
                    $u = User::query()->pluck('name', 'id');
                    $rows = self::txns($c, false)->where('kind', 'reversal')->orderBy('txn_date')->get();
                    $orig = AcctTransaction::query()->whereIn('id', $rows->pluck('reverses_id')->filter())->pluck('reference', 'id');

                    return $rows->map(fn ($t) => ['date' => $t->txn_date->toDateString(), 'reference' => $t->reference, 'original' => $orig[$t->reverses_id] ?? '-', 'account' => $c->accts[$t->account_id] ?? '-', 'amount' => (string) $t->amount, 'currency' => $t->currency, 'reason_text' => $t->reason, 'by' => $u[$t->created_by] ?? '-']);
                }],

            'adjustments' => ['group' => 'control', 'title' => 'Adjustments', 'about' => 'Adjustment entries and the reason given for each.', 'filters' => ['from', 'to', 'account_id', 'currency'],
                'columns' => array_merge(self::txCols(), [['key' => 'reason_text', 'label' => 'Reason']]),
                'run' => fn ($c) => self::txns($c, false)->where('kind', 'adjustment')->orderBy('txn_date')->get()->map(fn ($t) => self::txRow($t, $c) + ['reason_text' => $t->reason])],

            'large_transactions' => ['group' => 'control', 'title' => 'Large transactions', 'about' => 'Posted transactions at or above the large-transaction limit set for their currency.', 'filters' => ['from', 'to', 'account_id', 'currency'],
                'columns' => self::txCols(),
                'run' => function ($c) {
                    $lim = AcctSettings::get()['large_txn'];

                    return self::txns($c)->whereNotIn('kind', ['fee'])->orderByDesc('amount')->limit(5000)->get()->filter(fn ($t) => isset($lim[$t->currency]) && Money::cmp((string) $t->amount, (string) $lim[$t->currency]) >= 0)->map(fn ($t) => self::txRow($t, $c));
                }],

            'user_activity' => ['group' => 'control', 'title' => 'Activity by user', 'about' => 'How many transactions each person created and for how much, by currency.', 'filters' => ['from', 'to', 'account_id', 'currency', 'user_id'],
                'columns' => [['key' => 'user', 'label' => 'User'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'count', 'label' => 'Transactions', 'type' => 'number'], ['key' => 'in', 'label' => 'In', 'type' => 'money', 'sum' => true], ['key' => 'out', 'label' => 'Out', 'type' => 'money', 'sum' => true]],
                'run' => function ($c) {
                    $u = User::query()->pluck('name', 'id');

                    return AcctTransaction::operating(self::txns($c, false))->selectRaw("created_by, currency, COUNT(*) n, SUM(CASE WHEN direction='in' THEN amount ELSE 0 END) i, SUM(CASE WHEN direction='out' THEN amount ELSE 0 END) o")->groupBy('created_by', 'currency')->get()
                        ->map(fn ($r) => ['user' => $u[$r->created_by] ?? '-', 'currency' => $r->currency, 'count' => (int) $r->n, 'in' => (string) $r->i, 'out' => (string) $r->o]);
                }],

            'approval_turnaround' => ['group' => 'control', 'title' => 'Approval turnaround', 'about' => 'How long each transaction waited between submission and approval.', 'filters' => ['from', 'to', 'account_id'],
                'columns' => [['key' => 'reference', 'label' => 'Reference'], ['key' => 'account', 'label' => 'Account'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'submitted', 'label' => 'Submitted'], ['key' => 'approved', 'label' => 'Approved'], ['key' => 'hours', 'label' => 'Hours', 'type' => 'number']],
                'run' => function ($c) {
                    $rows = self::txns($c, false)->whereNotNull('submitted_at')->whereIn('status', ['approved', 'posted', 'reversed'])->orderBy('txn_date')->limit(2000)->get();
                    $ap = DB::connection('accounts')->table('acct_approvals')->where('subject_type', 'transaction')->where('action', 'approved')->whereIn('subject_id', $rows->pluck('id'))->selectRaw('subject_id, MAX(created_at) at')->groupBy('subject_id')->pluck('at', 'subject_id');

                    return $rows->filter(fn ($t) => isset($ap[$t->id]))->map(fn ($t) => ['reference' => $t->reference, 'account' => $c->accts[$t->account_id] ?? '-', 'amount' => (string) $t->amount, 'currency' => $t->currency, 'submitted' => $t->submitted_at->toDateTimeString(), 'approved' => (string) $ap[$t->id], 'hours' => (int) $t->submitted_at->diffInHours(\Carbon\Carbon::parse($ap[$t->id]))]);
                }],

            // single records for the Print / PDF of a transaction, a transfer or a reconciliation (not listed on the Reports page)
            'transaction_detail' => ['group' => 'ledger', 'hidden' => true, 'title' => 'Transaction record', 'about' => 'One transaction with its account, amount and state.', 'filters' => [],
                'columns' => self::txCols(),
                'run' => fn ($c) => self::acctQ($c, AcctTransaction::query())->where('id', (int) ($c->f['record_id'] ?? 0))->get()->map(fn ($t) => self::txRow($t, $c))],
            'transfer_detail' => ['group' => 'transfers', 'hidden' => true, 'title' => 'Transfer record', 'about' => 'One transfer with both amounts, the rate used and its state.', 'filters' => [],
                'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'from', 'label' => 'From'], ['key' => 'to', 'label' => 'To'], ['key' => 'amount', 'label' => 'Sent', 'type' => 'money', 'cur' => 'currency'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'received', 'label' => 'Received', 'type' => 'money', 'cur' => 'to_currency'], ['key' => 'to_currency', 'label' => 'Cur'], ['key' => 'rate', 'label' => 'Rate'], ['key' => 'status', 'label' => 'Status']],
                'run' => fn ($c) => self::transfersQ($c, false)->where('id', (int) ($c->f['record_id'] ?? 0))->get()->map(fn ($t) => self::trRow($t, $c))],
            'reconciliation_detail' => ['group' => 'reconciliation', 'hidden' => true, 'title' => 'Reconciliation record', 'about' => 'One cash count or bank reconciliation with the difference found.', 'filters' => [],
                'columns' => [['key' => 'reference', 'label' => 'Reference'], ['key' => 'kind', 'label' => 'Type'], ['key' => 'account', 'label' => 'Account'], ['key' => 'date', 'label' => 'As of', 'type' => 'date'], ['key' => 'expected', 'label' => 'WIMS says', 'type' => 'money'], ['key' => 'counted', 'label' => 'Counted', 'type' => 'money'], ['key' => 'difference', 'label' => 'Difference', 'type' => 'money'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'status', 'label' => 'Status']],
                'run' => fn ($c) => self::acctQ($c, AcctReconciliation::query())->where('id', (int) ($c->f['record_id'] ?? 0))->get()->map(fn ($r) => self::recRow($r, $c))],

            'reconciliation_summary' => ['group' => 'reconciliation', 'title' => 'Reconciliation summary', 'about' => 'All cash counts and bank reconciliations in the period.', 'filters' => ['from', 'to', 'account_id', 'status'],
                'columns' => [['key' => 'reference', 'label' => 'Reference'], ['key' => 'kind', 'label' => 'Type'], ['key' => 'account', 'label' => 'Account'], ['key' => 'date', 'label' => 'As of', 'type' => 'date'], ['key' => 'expected', 'label' => 'WIMS says', 'type' => 'money'], ['key' => 'counted', 'label' => 'Counted', 'type' => 'money'], ['key' => 'difference', 'label' => 'Difference', 'type' => 'money', 'sum' => true], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'status', 'label' => 'Status']],
                'run' => fn ($c) => self::reconQ($c)->orderBy('period_to')->get()->map(fn ($r) => self::recRow($r, $c))],

            'cash_discrepancies' => ['group' => 'reconciliation', 'title' => 'Cash and bank differences', 'about' => 'Reconciliations where the count or statement did not agree with WIMS.', 'filters' => ['from', 'to', 'account_id'],
                'columns' => [['key' => 'reference', 'label' => 'Reference'], ['key' => 'kind', 'label' => 'Type'], ['key' => 'account', 'label' => 'Account'], ['key' => 'date', 'label' => 'As of', 'type' => 'date'], ['key' => 'expected', 'label' => 'WIMS says', 'type' => 'money'], ['key' => 'counted', 'label' => 'Counted', 'type' => 'money'], ['key' => 'difference', 'label' => 'Difference', 'type' => 'money', 'sum' => true], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'status', 'label' => 'Status'], ['key' => 'explanation', 'label' => 'Explanation']],
                'run' => fn ($c) => self::reconQ($c)->where('difference', '<>', 0)->orderBy('period_to')->get()->map(fn ($r) => self::recRow($r, $c) + ['explanation' => $r->explanation])],

            'unreconciled' => ['group' => 'reconciliation', 'title' => 'Unreconciled transactions', 'about' => 'Posted transactions on bank accounts that no approved reconciliation has covered yet.', 'filters' => ['from', 'to', 'account_id', 'currency'],
                'columns' => self::txCols(),
                'run' => fn ($c) => self::txns($c)->whereNull('reconciled_at')->whereIn('account_id', AcctAccount::query()->where('kind', 'bank_account')->pluck('id')->all() ?: [0])->orderBy('txn_date')->limit(self::LIMIT_EXPORT)->get()->map(fn ($t) => self::txRow($t, $c))],

            'bank_exceptions' => ['group' => 'reconciliation', 'title' => 'Bank statement exceptions', 'about' => 'Statement lines that are unmatched, repeated or set aside.', 'filters' => ['from', 'to', 'account_id'],
                'columns' => [['key' => 'reconciliation', 'label' => 'Reconciliation'], ['key' => 'account', 'label' => 'Account'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'description', 'label' => 'Description'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'direction', 'label' => 'In/Out'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'status', 'label' => 'Status']],
                'run' => function ($c) {
                    $recs = self::reconQ($c)->where('kind', 'bank')->get()->keyBy('id');

                    return AcctReconItem::query()->whereIn('reconciliation_id', $recs->keys())->whereIn('status', ['unmatched', 'duplicate', 'ignored'])->whereDate('entry_date', '>=', $c->f['from'])->whereDate('entry_date', '<=', $c->f['to'])->orderBy('entry_date')->get()
                        ->map(function ($i) use ($recs, $c) {
                            $r = $recs[$i->reconciliation_id];

                            return ['reconciliation' => $r->reference, 'account' => $c->accts[$r->account_id] ?? '-', 'date' => $i->entry_date->toDateString(), 'description' => $i->description, 'reference' => $i->reference, 'direction' => $i->direction === 'in' ? 'In' : 'Out', 'amount' => (string) $i->amount, 'currency' => AcctAccount::query()->find($r->account_id)->currency ?? '', 'status' => ucfirst($i->status)];
                        });
                }],

            'low_balance' => ['group' => 'accounts', 'title' => 'Low balance accounts', 'about' => 'Accounts below their minimum balance, or below zero.', 'filters' => ['currency'],
                'columns' => [['key' => 'name', 'label' => 'Account'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'], ['key' => 'min', 'label' => 'Minimum', 'type' => 'money'], ['key' => 'short', 'label' => 'Below by', 'type' => 'money']],
                'run' => fn ($c) => self::accounts($c)->get()->filter(fn ($a) => ($a->min_balance !== null && Money::cmp((string) $a->balance, (string) $a->min_balance) < 0) || Money::cmp((string) $a->balance, '0') < 0)
                    ->map(fn ($a) => ['name' => $a->name, 'currency' => $a->currency, 'balance' => (string) $a->balance, 'min' => $a->min_balance !== null ? (string) $a->min_balance : null, 'short' => $a->min_balance !== null ? Money::sub((string) $a->min_balance, (string) $a->balance) : null])],

            'dormant_accounts' => ['group' => 'accounts', 'title' => 'Dormant accounts', 'about' => 'Active accounts with no posted movement for the number of days chosen.', 'filters' => ['days', 'currency'],
                'columns' => [['key' => 'name', 'label' => 'Account'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'], ['key' => 'last', 'label' => 'Last movement', 'type' => 'date'], ['key' => 'idle', 'label' => 'Idle days', 'type' => 'number']],
                'run' => function ($c) {
                    $last = AcctTransaction::query()->whereIn('status', AcctTransaction::LEDGER)->selectRaw('account_id, MAX(txn_date) d')->groupBy('account_id')->pluck('d', 'account_id');

                    return self::accounts($c)->where('status', 'active')->get()->map(fn ($a) => ['name' => $a->name, 'currency' => $a->currency, 'balance' => (string) $a->balance, 'last' => $last[$a->id] ?? null, 'idle' => $last[$a->id] ?? null ? (int) \Carbon\Carbon::parse($last[$a->id])->diffInDays(now()) : (int) ($a->created_at?->diffInDays(now()) ?? 0)])
                        ->filter(fn ($r) => $r['idle'] >= $c->f['days'])->sortByDesc('idle');
                }],

            'accounts_register' => ['group' => 'accounts', 'title' => 'Accounts register', 'about' => 'Every account, including inactive and closed ones, with its custodian and settings. Account numbers are always masked.', 'filters' => ['currency'],
                'columns' => [['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Name'], ['key' => 'type', 'label' => 'Type'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'number', 'label' => 'Number'], ['key' => 'institution', 'label' => 'Institution'], ['key' => 'custodian', 'label' => 'Custodian'], ['key' => 'opening', 'label' => 'Opening', 'type' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'], ['key' => 'status', 'label' => 'Status']],
                'run' => function ($c) {
                    $u = User::query()->pluck('name', 'id');

                    return self::accounts($c, true)->get()->map(fn ($a) => ['code' => $a->code, 'name' => $a->name, 'type' => AcctAccount::KINDS[$a->kind] ?? $a->kind, 'currency' => $a->currency, 'number' => $a->account_number_masked, 'institution' => $a->institution, 'custodian' => $u[$a->custodian_user_id] ?? '-', 'opening' => (string) $a->opening_balance, 'balance' => (string) $a->balance, 'status' => ucfirst($a->status)]);
                }],

            'funds_allocation' => ['group' => 'accounts', 'title' => 'Funds Allocation link', 'about' => 'For each funds allocation: allocated, actually received into accounts, spent from it and what remains. An allocation is a plan, not cash received.', 'filters' => ['from', 'to', 'currency'],
                'columns' => [['key' => 'allocation', 'label' => 'Allocation'], ['key' => 'title', 'label' => 'Title'], ['key' => 'allocated', 'label' => 'Allocated (TZS)', 'type' => 'money', 'cur' => 'tzs'], ['key' => 'currency', 'label' => 'Cur'], ['key' => 'received', 'label' => 'Received', 'type' => 'money', 'sum' => true], ['key' => 'spent', 'label' => 'Spent', 'type' => 'money', 'sum' => true], ['key' => 'remaining', 'label' => 'Remaining', 'type' => 'money', 'sum' => true]],
                'run' => function ($c) {
                    $g = self::txns($c)->whereNotNull('fund_allocation_id')->selectRaw("fund_allocation_id a, currency, SUM(CASE WHEN direction='in' THEN amount ELSE 0 END) i, SUM(CASE WHEN direction='out' THEN amount ELSE 0 END) o")->groupBy('a', 'currency')->get();
                    $al = DB::table('fund_allocations')->whereIn('id', $g->pluck('a'))->get(['id', 'reference', 'title'])->keyBy('id');
                    $sum = DB::table('fund_allocation_items')->whereNull('deleted_at')->whereIn('fund_allocation_id', $g->pluck('a'))->selectRaw('fund_allocation_id a, SUM(allocated_amount) s')->groupBy('a')->pluck('s', 'a');

                    return $g->map(fn ($r) => ['allocation' => $al[$r->a]->reference ?? '#' . $r->a, 'title' => $al[$r->a]->title ?? '', 'allocated' => (string) ($sum[$r->a] ?? 0), 'tzs' => 'TZS', 'currency' => $r->currency, 'received' => (string) $r->i, 'spent' => (string) $r->o, 'remaining' => Money::sub((string) $r->i, (string) $r->o)]);
                }],

            'audit_log' => ['group' => 'control', 'ability' => 'view-acct-audit', 'title' => 'Audit trail', 'about' => 'Who did what, when, from where, with the reason given.', 'filters' => ['from', 'to', 'user_id'],
                'columns' => [['key' => 'at', 'label' => 'When'], ['key' => 'user', 'label' => 'User'], ['key' => 'event', 'label' => 'Event'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'reason', 'label' => 'Reason'], ['key' => 'ip', 'label' => 'IP']],
                'run' => function ($c) {
                    $u = User::query()->pluck('name', 'id');

                    return AcctAuditEvent::query()->whereDate('created_at', '>=', $c->f['from'])->whereDate('created_at', '<=', $c->f['to'])->when($c->f['user_id'], fn ($q, $v) => $q->where('user_id', $v))->orderBy('id')->limit(self::LIMIT_EXPORT)->get()
                        ->map(fn ($e) => ['at' => $e->created_at->toDateTimeString(), 'user' => $u[$e->user_id] ?? '-', 'event' => $e->event, 'reference' => $e->reference, 'reason' => $e->reason, 'ip' => $e->ip]);
                }],
        ];
    }

    private static function transfersQ($ctx, bool $dates = true)
    {
        $f = $ctx->f;

        // a transfer is reported only to someone who can see BOTH of its accounts
        return AcctTransfer::query()->when($ctx->ids !== null, fn ($q) => $q->whereIn('from_account_id', $ctx->ids ?: [0])->whereIn('to_account_id', $ctx->ids ?: [0]))
            ->when($f['account_id'], fn ($q, $v) => $q->where(fn ($x) => $x->where('from_account_id', $v)->orWhere('to_account_id', $v)))
            ->when(in_array($f['status'], ['draft', 'submitted', 'approved', 'posted', 'rejected', 'cancelled', 'reversed'], true), fn ($q) => $q->where('status', $f['status']))
            ->when($dates, fn ($q) => $q->whereDate('transfer_date', '>=', $f['from'])->whereDate('transfer_date', '<=', $f['to']));
    }

    private static function trRow(AcctTransfer $t, $c): array
    {
        return ['date' => $t->transfer_date->toDateString(), 'reference' => $t->reference, 'from' => $c->accts[$t->from_account_id] ?? '-', 'to' => $c->accts[$t->to_account_id] ?? '-', 'amount' => (string) $t->from_amount, 'currency' => $t->from_currency,
            'received' => (string) $t->to_amount, 'to_currency' => $t->to_currency, 'rate' => $t->exchange_rate ? rtrim(rtrim((string) $t->exchange_rate, '0'), '.') : '-', 'diff' => (string) $t->rounding_difference, 'status' => ucfirst($t->status)];
    }

    private static function reconQ($c)
    {
        return self::acctQ($c, AcctReconciliation::query())->when($c->f['account_id'], fn ($q, $v) => $q->where('account_id', $v))->when(in_array($c->f['status'], ['draft', 'submitted', 'approved', 'rejected'], true), fn ($q) => $q->where('status', $c->f['status']))
            ->whereDate('period_to', '>=', $c->f['from'])->whereDate('period_to', '<=', $c->f['to']);
    }

    private static function recRow(AcctReconciliation $r, $c): array
    {
        return ['reference' => $r->reference, 'kind' => ucfirst($r->kind), 'account' => $c->accts[$r->account_id] ?? '-', 'date' => $r->period_to->toDateString(), 'expected' => (string) $r->expected_balance, 'counted' => (string) $r->counted_balance, 'difference' => (string) $r->difference,
            'currency' => AcctAccount::query()->find($r->account_id)->currency ?? '', 'status' => ucfirst($r->status)];
    }
}
