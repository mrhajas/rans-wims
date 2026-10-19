<?php

namespace App\Services\Subcontracts;

use App\Models\Contact;
use App\Models\SubcontractAgreement as A;
use App\Models\SubcontractPaymentRequest as R;
use App\Models\SubcontractVariation as V;
use Illuminate\Support\Facades\DB;

/**
 * The subcontract reports in the WIMS report shape (title, filters, tiles, columns, rows, notes), used by the screen, print, PDF, Excel and CSV alike.
 * Every figure is read through SubcontractLedger; no report adds amounts of different currencies together (totals are per currency).
 */
class SubcontractReports
{
    public const LIMIT = 50000;

    /** PDF and print are limited: a PDF page set of thousands of table rows needs far more memory and time than a web server gives (a 500 row PDF took several seconds). The report then says so and points to Excel / CSV, which carry every row. */
    public const PDF_LIMIT = 200;

    /** key => [title, about, filters used, needs financial permission] */
    public static function catalog(): array
    {
        $F = ['contact_id', 'warehouse_id', 'status', 'currency', 'from', 'to'];

        return [
            ['key' => 'register', 'group' => 'Subcontractors', 'money' => true, 'title' => __('Subcontractor Register'), 'about' => __('Every subcontractor with agreements, contract value, paid and outstanding, per currency.'), 'filters' => ['contact_id', 'currency']],
            ['key' => 'statement', 'group' => 'Subcontractors', 'money' => true, 'title' => __('Subcontractor Account Statement'), 'about' => __('Contract, variations, requests, vouchers and payments of one subcontractor or agreement, with the balance after each line.'), 'filters' => ['contact_id', 'agreement_id', 'warehouse_id', 'currency', 'from', 'to', 'kind']],
            ['key' => 'financial_summary', 'group' => 'Subcontractors', 'money' => true, 'title' => __('Financial Summary by Subcontractor'), 'about' => __('Original, variations, revised, approved work, paid, approved unpaid and outstanding for each subcontractor.'), 'filters' => ['contact_id', 'warehouse_id', 'currency']],
            ['key' => 'opening_balances', 'group' => 'Balances', 'money' => true, 'title' => __('Opening Balances'), 'about' => __('For every subcontractor account: the contract value, what was paid to date (the opening figure), what was paid since through vouchers, and what is outstanding now, with the as-at date and the number of documents attached.'), 'filters' => ['contact_id', 'warehouse_id', 'status', 'currency']],
            ['key' => 'consolidated', 'group' => 'Subcontractors', 'money' => true, 'title' => __('Consolidated Subcontracted Works Summary'), 'about' => __('One line per agreement: contract, paid and balance. This replaces the SUMMARY sheet of the Excel workbook.'), 'filters' => ['contact_id', 'warehouse_id', 'status', 'currency']],
            ['key' => 'summary_tieout', 'group' => 'Subcontractors', 'money' => true, 'title' => __('Excel Summary Tie-Out'), 'about' => __('The TOTAL of the workbook SUMMARY sheet tied, line by line, to the dashboard total: duplicates, other currencies, differences and accounts not on the SUMMARY.'), 'filters' => []],
            ['key' => 'agreements', 'group' => 'Agreements', 'money' => false, 'title' => __('Subcontract Agreements Register'), 'about' => __('All agreements with project, status and dates (amounts only if you may see them).'), 'filters' => $F],
            ['key' => 'agreement_detail', 'group' => 'Agreements', 'money' => true, 'title' => __('Agreement Details and Contract Breakdown'), 'about' => __('One agreement: its terms, the contract breakdown and every figure.'), 'filters' => ['agreement_id']],
            ['key' => 'work_schedule', 'group' => 'Agreements', 'money' => true, 'title' => __('Work Item Schedule'), 'about' => __('The work items of an agreement or project with unit, quantity, rate and amount.'), 'filters' => ['contact_id', 'agreement_id', 'warehouse_id']],
            ['key' => 'group_summary', 'group' => 'Agreements', 'money' => true, 'title' => __('Work Group and Location Summary'), 'about' => __('The total of every work group (building, location, phase).'), 'filters' => ['contact_id', 'agreement_id', 'warehouse_id']],
            ['key' => 'original_revised', 'group' => 'Agreements', 'money' => true, 'title' => __('Original vs Revised Contract Value'), 'about' => __('The original amount, approved variations and the revised value of every agreement.'), 'filters' => ['contact_id', 'warehouse_id', 'status', 'currency']],
            ['key' => 'variations', 'group' => 'Agreements', 'money' => true, 'title' => __('Approved Contract Variations'), 'about' => __('Variations with their status, amount, reason and approver.'), 'filters' => ['contact_id', 'agreement_id', 'status', 'from', 'to']],
            ['key' => 'completed', 'group' => 'Agreements', 'money' => false, 'title' => __('Completed and Terminated Agreements'), 'about' => __('Agreements that are completed, terminated or cancelled.'), 'filters' => ['contact_id', 'warehouse_id', 'from', 'to']],
            ['key' => 'requests', 'group' => 'Payments', 'money' => true, 'title' => __('Subcontractor Payment Requests'), 'about' => __('All payment requests with status, requested and approved amounts.'), 'filters' => ['contact_id', 'agreement_id', 'status', 'from', 'to']],
            ['key' => 'pending', 'group' => 'Payments', 'money' => false, 'title' => __('Pending Approvals'), 'about' => __('Agreements, variations and payment requests that wait for a review or a decision.'), 'filters' => ['contact_id']],
            ['key' => 'approved_unpaid', 'group' => 'Payments', 'money' => true, 'title' => __('Approved but Unpaid Requests'), 'about' => __('Approved requests and what is still to be paid on each.'), 'filters' => ['contact_id', 'agreement_id', 'currency']],
            ['key' => 'voucher_history', 'group' => 'Payments', 'money' => true, 'title' => __('Payment Voucher History by Subcontractor'), 'about' => __('Every Payment Voucher made for a subcontract request and its state.'), 'filters' => ['contact_id', 'agreement_id', 'status', 'from', 'to']],
            ['key' => 'payments', 'group' => 'Payments', 'money' => true, 'title' => __('Actual Payments by Date Range'), 'about' => __('Payments whose voucher was actually paid, by payment date.'), 'filters' => ['contact_id', 'warehouse_id', 'from', 'to']],
            ['key' => 'payments_project', 'group' => 'Payments', 'money' => true, 'title' => __('Payments by Project'), 'about' => __('Paid amounts (vouchers and counted historical payments) by project.'), 'filters' => ['warehouse_id', 'from', 'to', 'currency']],
            ['key' => 'payments_location', 'group' => 'Payments', 'money' => true, 'title' => __('Payments by Work Location'), 'about' => __('Paid amounts by work group / location, from the allocations of payments to work items.'), 'filters' => ['contact_id', 'warehouse_id', 'from', 'to']],
            ['key' => 'deductions', 'group' => 'Payments', 'money' => true, 'title' => __('Advances, Retention and Deductions'), 'about' => __('Advance requests and the retention terms of agreements (WIMS holds no other deduction type yet).'), 'filters' => ['contact_id', 'agreement_id']],
            ['key' => 'currency', 'group' => 'Payments', 'money' => true, 'title' => __('Currency and Exchange Rates'), 'about' => __('Payments in a foreign currency with the rate, its date and the TZS amount; historical conversions are flagged.'), 'filters' => ['contact_id', 'currency', 'from', 'to']],
            ['key' => 'commitments', 'group' => 'Balances', 'money' => true, 'title' => __('Outstanding Contract Commitments'), 'about' => __('The part of the revised contract value that is not yet paid.'), 'filters' => ['contact_id', 'warehouse_id', 'status', 'currency']],
            ['key' => 'payables', 'group' => 'Balances', 'money' => true, 'title' => __('Outstanding Payables'), 'about' => __('Approved requests not yet paid.'), 'filters' => ['contact_id', 'warehouse_id', 'currency']],
            ['key' => 'reconciliation', 'group' => 'Balances', 'money' => true, 'title' => __('Contract Balance and Reconciliation'), 'about' => __('Imported accounts: the workbook figures against WIMS and the difference, with its status.'), 'filters' => ['contact_id', 'status']],
            ['key' => 'import', 'group' => 'Balances', 'money' => true, 'title' => __('Historical Excel Import Report'), 'about' => __('What each import batch brought in, sheet by sheet.'), 'filters' => ['from', 'to']],
            ['key' => 'completed_eligible', 'group' => 'Payments', 'money' => true, 'title' => __('Completed Contracts Eligible for Payment'), 'about' => __('Completed agreements that can be put on a contract payment request (verified, with evidence when required), with what was paid, what is waiting and what is still open to request. Choose the status "All" to see the payable agreements that are not eligible yet and why.'), 'filters' => ['contact_id', 'agreement_id', 'warehouse_id', 'status', 'currency', 'from', 'to']],
            ['key' => 'claims', 'group' => 'Payments', 'money' => true, 'title' => __('Contract Payment Requests'), 'about' => __('Every line of every contract payment request with its decision (approved, rejected, returned, deferred), the reason, the payment request it created and how much of it is paid.'), 'filters' => ['contact_id', 'agreement_id', 'warehouse_id', 'status', 'currency', 'from', 'to']],
            ['key' => 'claim_allocations', 'group' => 'Payments', 'money' => true, 'title' => __('Contract Payment Voucher Allocations'), 'about' => __('Each Payment Voucher of a contract payment request split by agreement, with the instalments paid on every line (partial payments) and what is still left to pay on it.'), 'filters' => ['contact_id', 'agreement_id', 'warehouse_id', 'status', 'from', 'to']],
            ['key' => 'claim_detail', 'group' => 'Payments', 'hidden' => true, 'money' => true, 'title' => __('Contract Payment Request'), 'about' => __('One contract payment request: its lines, decisions, payment requests and vouchers.'), 'filters' => ['claim_id']],
            ['key' => 'request_detail', 'group' => 'Payments', 'hidden' => true, 'money' => true, 'title' => __('Payment Request'), 'about' => __('One payment request: its work items, amounts, decision and payments.'), 'filters' => ['request_id']],
            ['key' => 'variation_detail', 'group' => 'Agreements', 'hidden' => true, 'money' => true, 'title' => __('Contract Variation'), 'about' => __('One variation: its reason, work items, amount and decision.'), 'filters' => ['variation_id']],
            ['key' => 'audit', 'group' => 'Control', 'money' => true, 'title' => __('Audit and Change History'), 'about' => __('Who did what on subcontract records.'), 'filters' => ['from', 'to']],
        ];
    }

    public static function find(string $type): ?array
    {
        foreach (self::catalog() as $c) {
            if ($c['key'] === $type) {
                return $c;
            }
        }

        return null;
    }

    public static function exists(string $type): bool
    {
        return self::find($type) !== null;
    }

    public static function normalize(array $f): array
    {
        $date = fn ($v) => ! empty($v) && strtotime($v) ? date('Y-m-d', strtotime($v)) : null;
        $int = fn ($v) => ctype_digit((string) ($v ?? '')) && ($v ?? '') !== '' ? (int) $v : null;

        return ['contact_id' => $int($f['contact_id'] ?? null), 'agreement_id' => $int($f['agreement_id'] ?? null), 'warehouse_id' => $int($f['warehouse_id'] ?? null), 'status' => mb_substr(trim((string) ($f['status'] ?? '')), 0, 30) ?: null,
            'currency' => in_array($f['currency'] ?? '', SubcontractSettings::get()['currencies'], true) ? $f['currency'] : null, 'from' => $date($f['from'] ?? null), 'to' => $date($f['to'] ?? null), 'kind' => mb_substr(trim((string) ($f['kind'] ?? '')), 0, 20) ?: null,
            'request_id' => $int($f['request_id'] ?? null), 'variation_id' => $int($f['variation_id'] ?? null), 'claim_id' => $int($f['claim_id'] ?? null)];
    }

    public static function build($user, string $type, array $f, int $limit = self::LIMIT, bool $withQr = false): array
    {
        $def = self::find($type);
        abort_unless($def, 404);
        if ($def['money']) {
            abort_unless($user->can('view-financials-subcontracts'), 403);
        }
        $f = self::normalize($f);
        $canMoney = $user->can('view-financials-subcontracts');
        [$cols, $rows, $tiles, $notes, $sections] = self::{'r_' . $type}($user, $f, $canMoney);
        $total = count($rows);
        $kept = array_slice($rows, 0, $limit);
        $cols = array_map(fn ($c) => $c + ['align' => in_array($c['type'] ?? 'text', ['money', 'num'], true) ? 'right' : 'left'], $cols);
        $display = array_map(function ($r) use ($cols) {
            $o = [];
            foreach ($cols as $c) {
                $v = $r[$c['key']] ?? '';
                $o[$c['key']] = ($c['type'] ?? '') === 'money' && $v !== '' && $v !== null ? SubcontractAmounts::group($v) : $v;
            }
            if (isset($r['_link'])) {
                $o['_link'] = $r['_link']; // which record the row opens on screen; never an exported column
            }

            return $o;
        }, $kept);

        $qr = null;
        if ($withQr && in_array($type, ['agreement_detail', 'statement'], true) && $f['agreement_id']) {
            $qr = \App\Support\PrintQrCode::dataUri(route('subcontracts.agreements.show', $f['agreement_id']));
        }

        if ($withQr && $type === 'request_detail' && $f['request_id']) {
            $qr = \App\Support\PrintQrCode::dataUri(route('subcontracts.requests.show', $f['request_id']));
        }
        if ($withQr && $type === 'variation_detail' && $f['variation_id']) {
            $qr = \App\Support\PrintQrCode::dataUri(route('subcontracts.variations.show', $f['variation_id']));
        }

        $signing = ['type' => 'subcontract_report'];
        if ($type === 'agreement_detail' && $f['agreement_id']) {
            $signing = ['type' => 'subcontract_agreement', 'id' => $f['agreement_id']];
        } elseif ($type === 'request_detail' && $f['request_id']) {
            $signing = ['type' => 'subcontract_request', 'id' => $f['request_id']];
        } elseif ($type === 'variation_detail' && $f['variation_id']) {
            $signing = ['type' => 'subcontract_variation', 'id' => $f['variation_id']];
        }

        return ['qr' => $qr, 'signing' => $signing, 'currency' => $type === 'summary_tieout' ? 'TZS' : null, 'type' => $type, 'title' => $def['title'], 'reference' => 'SCR-' . now()->format('Ymd-His'), 'subtitle' => $def['about'], 'table_title' => $def['title'], 'filters' => self::filterText($user, $f), 'tiles' => array_values(array_merge([['label' => __('Rows'), 'value' => $total]], $tiles)),
            'columns' => $cols, 'rows' => $display, 'raw' => $kept, 'total_rows' => $total, 'truncated' => $total > $limit, 'sections' => $sections,
            'notes' => array_merge($total > $limit ? [__('Only the first :n of :t rows are shown here. Download the Excel or CSV file to get every row.', ['n' => $limit, 't' => $total])] : [], $notes, [__('Amounts of different currencies are never added together: totals are shown per currency.'), __('Only a Payment Voucher that is Authorised and marked paid counts as paid; requests, approvals and unpaid vouchers do not.'), __('Figures are drawn from the records at the time of generation.')])];
    }

    /** The filter line of a report. A name or reference is printed only when the reader may see that record (a hidden agreement or a project the person is not assigned to shows just its number). */
    private static function filterText($user, array $f): array
    {
        $o = [];
        $agr = fn ($id) => A::query()->visibleTo($user)->whereKey($id)->value('reference') ?: '#' . $id;
        if ($f['contact_id']) { $o[__('Subcontractor')] = optional(Contact::withTrashed()->find($f['contact_id']))->name; }
        if ($f['agreement_id']) { $o[__('Agreement')] = $agr($f['agreement_id']); }
        if ($f['claim_id']) { $o[__('Contract payment request')] = \App\Models\SubcontractClaim::withoutGlobalScopes()->whereKey($f['claim_id'])->value('reference') ?: '#' . $f['claim_id']; }
        if ($f['request_id']) { $o[__('Request')] = \App\Models\SubcontractPaymentRequest::withoutGlobalScopes()->whereKey($f['request_id'])->whereIn('agreement_id', A::query()->visibleTo($user)->select('id'))->value('reference') ?: '#' . $f['request_id']; }
        if ($f['variation_id']) { $o[__('Variation')] = \App\Models\SubcontractVariation::withoutGlobalScopes()->whereKey($f['variation_id'])->whereIn('agreement_id', A::query()->visibleTo($user)->select('id'))->value('reference') ?: '#' . $f['variation_id']; }
        if ($f['warehouse_id']) {
            $mine = accessible_warehouse_ids();
            $o[__('Project')] = ($user->hasRole('Super Admin') || $user->view_all || ($mine !== null && in_array((int) $f['warehouse_id'], array_map('intval', (array) $mine), true))) ? (optional(\App\Models\Warehouse::withoutGlobalScopes()->find($f['warehouse_id']))->name ?: '#' . $f['warehouse_id']) : '#' . $f['warehouse_id'];
        }
        foreach (['status' => __('Status'), 'currency' => __('Currency'), 'from' => __('From'), 'to' => __('To')] as $k => $l) {
            if ($f[$k]) { $o[$l] = $f[$k]; }
        }

        return $o ?: [__('Filters') => __('None')];
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------------------

    /** The archive is reported only when asked for (status Archived, or one named agreement). */
    private static function agreements($user, array $f, array $statuses = null)
    {
        return A::query()->visibleTo($user)->with(['contact:id,name', 'warehouse:id,name,status', 'administrator:id,name'])
            ->when($f['contact_id'], fn ($q, $v) => $q->where('contact_id', $v))->when($f['agreement_id'], fn ($q, $v) => $q->where('id', $v))->when($f['warehouse_id'], fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($f['status'], fn ($q, $v) => $q->where('status', $v))->when($statuses, fn ($q, $v) => $q->whereIn('status', $v))
            ->when($f['status'] !== A::ARCHIVED && ! $f['agreement_id'] && ! ($statuses && in_array(A::ARCHIVED, $statuses, true)), fn ($q) => $q->where('status', '<>', A::ARCHIVED))->when($f['currency'], fn ($q, $v) => $q->where('currency', $v))->orderBy('id');
    }

    /** The filters for the agreements behind a report whose own "status" filter belongs to the listed records (a request, a variation, a voucher, a reconciliation), not to the agreement. */
    private static function agreementsOf(array $f): array
    {
        return array_merge($f, ['status' => null]);
    }

    private static function sumByCurrency(array $rows, array $keys): array
    {
        $t = [];
        foreach ($rows as $r) {
            foreach ($keys as $label => $key) {
                $c = $r['currency'] ?? 'TZS';
                $t[$label][$c] = bcadd($t[$label][$c] ?? '0.00', (string) ($r[$key] ?? '0'), 2);
            }
        }
        $tiles = [];
        foreach ($t as $label => $by) {
            foreach ($by as $c => $v) {
                $tiles[] = ['label' => $label . ' (' . $c . ')', 'value' => SubcontractAmounts::group($v)];
            }
        }

        return $tiles;
    }

    private static function money(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'money'];
    }

    private static function d($v): string
    {
        return $v ? (is_string($v) ? substr($v, 0, 10) : $v->format('Y-m-d')) : '';
    }

    // ---- the reports ---------------------------------------------------------------------------------------------------------------

    private static function r_register($user, $f, $m)
    {
        $ags = self::agreements($user, $f)->get();
        $fig = SubcontractLedger::forAgreements($ags->pluck('id')->all());
        $by = [];
        foreach ($ags as $a) {
            $x = $fig[$a->id];
            $k = $a->contact_id . '|' . $a->currency;
            $by[$k] ??= ['_link' => ['type' => 'contact', 'id' => $a->contact_id], 'subcontractor' => optional($a->contact)->name, 'currency' => $a->currency, 'agreements' => 0, 'active' => 0, 'revised' => '0.00', 'paid' => '0.00', 'outstanding' => '0.00'];
            $by[$k]['agreements']++;
            $by[$k]['active'] += in_array($a->status, A::PAYABLE, true) ? 1 : 0;
            if (! in_array($a->status, [A::DRAFT, A::CANCELLED], true)) {
                $by[$k]['revised'] = bcadd($by[$k]['revised'], $x['revised'], 2);
                $by[$k]['paid'] = bcadd($by[$k]['paid'], $x['paid_total'], 2);
                $by[$k]['outstanding'] = bcadd($by[$k]['outstanding'], $x['commitment_left'], 2);
            }
        }
        $rows = array_values($by);
        usort($rows, fn ($a, $b) => strcasecmp((string) $a['subcontractor'], (string) $b['subcontractor']));

        return [[['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'currency', 'label' => __('Currency')], ['key' => 'agreements', 'label' => __('Agreements'), 'type' => 'num'], ['key' => 'active', 'label' => __('Payable'), 'type' => 'num'],
            self::money('revised', __('Revised contract')), self::money('paid', __('Paid')), self::money('outstanding', __('Outstanding'))], $rows, self::sumByCurrency($rows, [__('Revised') => 'revised', __('Paid') => 'paid', __('Outstanding') => 'outstanding']), [], []];
    }

    private static function r_statement($user, $f, $m)
    {
        $s = SubcontractStatement::build($user, $f);
        $cols = [['key' => 'date', 'label' => __('Date')], ['key' => 'source', 'label' => __('Source')], ['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'type', 'label' => __('Type')], ['key' => 'reference', 'label' => __('Reference')], ['key' => 'description', 'label' => __('Description')],
            ['key' => 'currency', 'label' => __('Cur.')], self::money('commitment', __('Contract')), self::money('approved', __('Approved')), self::money('paid', __('Paid')), self::money('balance', __('Remaining contract'))];

        return [$cols, $s['rows'], self::sumByCurrency($s['rows'], [__('Paid') => 'paid']), $s['notes'], []];
    }

    private static function r_financial_summary($user, $f, $m)
    {
        $ags = self::agreements($user, $f)->whereNotIn('status', [A::DRAFT, A::CANCELLED])->get();
        $fig = SubcontractLedger::forAgreements($ags->pluck('id')->all());
        $by = [];
        foreach ($ags as $a) {
            $x = $fig[$a->id];
            $k = $a->contact_id . '|' . $a->currency;
            $by[$k] ??= ['_link' => ['type' => 'contact', 'id' => $a->contact_id], 'subcontractor' => optional($a->contact)->name, 'currency' => $a->currency] + array_fill_keys(['original', 'variations', 'revised', 'approved_work', 'paid', 'approved_unpaid', 'outstanding'], '0.00');
            foreach (['original' => 'original', 'variations' => 'variations_net', 'revised' => 'revised', 'approved_work' => 'approved_work', 'paid' => 'paid_total', 'approved_unpaid' => 'approved_unpaid', 'outstanding' => 'commitment_left'] as $k2 => $src) {
                $by[$k][$k2] = bcadd($by[$k][$k2], $x[$src], 2);
            }
        }
        $rows = array_values($by);

        return [[['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'currency', 'label' => __('Cur.')], self::money('original', __('Original')), self::money('variations', __('Variations')), self::money('revised', __('Revised')), self::money('approved_work', __('Approved work')),
            self::money('paid', __('Paid')), self::money('approved_unpaid', __('Approved unpaid')), self::money('outstanding', __('Outstanding commitment'))], $rows, self::sumByCurrency($rows, [__('Revised') => 'revised', __('Paid') => 'paid', __('Outstanding') => 'outstanding']),
            [__('Approved work = counted historical payments + approved request amounts. Outstanding commitment = revised contract value - paid.')], []];
    }

    private static function r_opening_balances($user, $f, $m)
    {
        $ags = self::agreements($user, $f)->orderBy('id')->get();
        $fig = SubcontractLedger::forAgreements($ags->pluck('id')->all());
        $op = DB::table('subcontract_historical_payments')->whereIn('agreement_id', $ags->pluck('id')->all() ?: [0])->whereNull('deleted_at')->where('counted', 1)->selectRaw('agreement_id, SUM(amount) s, COUNT(*) n, MAX(payment_date) d')->groupBy('agreement_id')->get()->keyBy('agreement_id');
        $docs = DB::table('attachments')->where('model_type', A::class)->where('group', 'opening')->whereIn('model_id', $ags->pluck('id')->all() ?: [0])->selectRaw('model_id, COUNT(*) n')->groupBy('model_id')->pluck('n', 'model_id');
        $rows = [];
        foreach ($ags as $a) {
            $x = $fig[$a->id];
            $o = $op[$a->id] ?? null;
            $rows[] = ['_link' => ['type' => 'agreement', 'id' => $a->id], 'reference' => $a->reference, 'subcontractor' => optional($a->contact)->name, 'title' => $a->title, 'project' => optional($a->warehouse)->name, 'currency' => $a->currency,
                'contract' => $x['revised'], 'opening' => SubcontractAmounts::money($o->s ?? 0), 'since' => $x['paid_live'], 'paid' => $x['paid_total'], 'outstanding' => $x['commitment_left'], 'as_at' => $a->as_at_date ? $a->as_at_date->toDateString() : ($o->d ?? ''),
                'lines' => (int) ($o->n ?? 0), 'docs' => (int) ($docs[$a->id] ?? 0), 'status' => $a->status];
        }

        return [[['key' => 'reference', 'label' => __('Agreement')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'title', 'label' => __('Works')], ['key' => 'project', 'label' => __('Project')], ['key' => 'currency', 'label' => __('Cur.')],
            self::money('contract', __('Contract')), self::money('opening', __('Opening Paid')), self::money('since', __('Paid Since')), self::money('paid', __('Paid In Total')), self::money('outstanding', __('Outstanding')),
            ['key' => 'as_at', 'label' => __('As At')], ['key' => 'lines', 'label' => __('Lines'), 'type' => 'num'], ['key' => 'docs', 'label' => __('Documents'), 'type' => 'num'], ['key' => 'status', 'label' => __('Status')]],
            $rows, self::sumByCurrency($rows, [__('Contract') => 'contract', __('Opening Paid') => 'opening', __('Outstanding') => 'outstanding']), [__('Opening paid is the amount the account had been paid up to the as-at date; paid since is what was paid afterwards through Payment Vouchers. Outstanding = contract minus paid in total.')], []];
    }

    private static function r_consolidated($user, $f, $m)
    {
        $ags = self::agreements($user, $f)->whereNotIn('status', [A::DRAFT, A::CANCELLED])->get();
        $fig = SubcontractLedger::forAgreements($ags->pluck('id')->all());
        $rows = [];
        foreach ($ags as $i => $a) {
            $x = $fig[$a->id];
            $rows[] = ['_link' => ['type' => 'agreement', 'id' => $a->id], 'no' => $i + 1, 'subcontractor' => optional($a->contact)->name, 'works' => $a->title, 'project' => optional($a->warehouse)->name, 'kind' => optional($a->warehouse)->status, 'currency' => $a->currency, 'contract' => $x['revised'], 'paid' => $x['paid_total'], 'balance' => $x['commitment_left'], 'status' => $a->status];
        }

        return [[['key' => 'no', 'label' => __('S.No'), 'type' => 'num'], ['key' => 'subcontractor', 'label' => __('Name')], ['key' => 'works', 'label' => __('Description of works')], ['key' => 'project', 'label' => __('Project')], ['key' => 'kind', 'label' => __('Category')], ['key' => 'currency', 'label' => __('Cur.')],
            self::money('contract', __('Contract')), self::money('paid', __('Paid')), self::money('balance', __('Balance')), ['key' => 'status', 'label' => __('Status')]], $rows, self::sumByCurrency($rows, [__('Contract') => 'contract', __('Paid') => 'paid', __('Balance') => 'balance']), [], []];
    }

    private static function r_summary_tieout($user, $f, $m)
    {
        $t = SubcontractTieOut::build('TZS');
        if (! $t) {
            return [[['key' => 'name', 'label' => __('Line')]], [['name' => __('No imported workbook with a SUMMARY sheet was found.')]], [], [], []];
        }
        $kinds = ['duplicate' => __('Listed twice'), 'currency' => __('Other currency'), 'outside' => __('Not in the totals'), 'detail' => __('Sheet detail differs'), 'extra' => __('Not on the SUMMARY')];
        $rows = [['_style' => 'total', 'line' => __('Excel SUMMARY total'), 'kind' => '', 'contract' => $t['excel']['contract'], 'paid' => $t['excel']['paid'], 'balance' => $t['excel']['balance'], 'note' => __('As typed on the SUMMARY sheet, :b balance.', ['b' => number_format((float) $t['excel']['balance_typed'], 2)])]];
        foreach ($t['lines'] as $l) {
            $rows[] = ['_link' => $l['link'], 'line' => $l['name'], 'kind' => $kinds[$l['kind']] ?? $l['kind'], 'contract' => $l['contract'], 'paid' => $l['paid'], 'balance' => bcsub(ltrim($l['contract'], '+'), ltrim($l['paid'], '+'), 2), 'note' => $l['note']];
        }
        $rows[] = ['_style' => 'total', 'line' => __('Total after the differences'), 'kind' => '', 'contract' => $t['result']['contract'], 'paid' => $t['result']['paid'], 'balance' => $t['result']['balance'], 'note' => ''];
        $rows[] = ['_style' => 'total', 'line' => __('Dashboard total (TZS)'), 'kind' => '', 'contract' => $t['dashboard']['contract'], 'paid' => $t['dashboard']['paid'], 'balance' => $t['dashboard']['balance'], 'note' => $t['ties'] ? __('Ties to the Excel SUMMARY.') : __('Does not tie: check the lines above.')];
        $notes = [];
        foreach ($t['balance_notes'] as $n) {
            $notes[] = __(':n: the SUMMARY shows a balance of :t but contract minus paid is :w (difference :g). WIMS always uses contract minus paid.', ['n' => $n['name'], 't' => SubcontractAmounts::group($n['typed']), 'w' => SubcontractAmounts::group($n['worked']), 'g' => SubcontractAmounts::group($n['gap'])]);
        }

        return [[['key' => 'line', 'label' => __('Line')], ['key' => 'kind', 'label' => __('Kind')], self::money('contract', __('Contract')), self::money('paid', __('Paid')), self::money('balance', __('Balance')), ['key' => 'note', 'label' => __('Why')]], $rows, [], $notes, []];
    }

    private static function r_agreements($user, $f, $m)
    {
        $ags = self::agreements($user, $f)->when($f['from'], fn ($q, $v) => $q->whereDate('agreement_date', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('agreement_date', '<=', $v))->get();
        $fig = $m ? SubcontractLedger::forAgreements($ags->pluck('id')->all()) : [];
        $rows = $ags->map(fn ($a) => ['_link' => ['type' => 'agreement', 'id' => $a->id], 'reference' => $a->reference, 'subcontractor' => optional($a->contact)->name, 'title' => $a->title, 'project' => optional($a->warehouse)->name, 'site' => $a->site, 'status' => $a->status, 'currency' => $a->currency, 'date' => self::d($a->agreement_date),
            'expected' => self::d($a->expected_completion), 'admin' => optional($a->administrator)->name, 'revised' => $m ? ($fig[$a->id]['revised'] ?? '') : '', 'paid' => $m ? ($fig[$a->id]['paid_total'] ?? '') : ''])->all();
        $cols = [['key' => 'reference', 'label' => __('Agreement')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'title', 'label' => __('Title')], ['key' => 'project', 'label' => __('Project')], ['key' => 'site', 'label' => __('Site / building')], ['key' => 'status', 'label' => __('Status')],
            ['key' => 'date', 'label' => __('Date')], ['key' => 'expected', 'label' => __('Expected end')], ['key' => 'admin', 'label' => __('Administrator')]];
        foreach (SubcontractFields::listFields('agreement') as $cf) {
            $cols[] = ['key' => $cf['key'], 'label' => $cf['label'], 'type' => $cf['type'] === 'number' ? 'num' : 'text'];
            foreach ($ags as $i => $a) {
                $v = SubcontractFields::values('agreement', $a->extra_attributes)[$cf['key']] ?? '';
                $rows[$i][$cf['key']] = is_bool($v) ? ($v ? __('Yes') : __('No')) : (string) $v;
            }
        }
        if ($m) {
            $cols = array_merge($cols, [['key' => 'currency', 'label' => __('Cur.')], self::money('revised', __('Revised')), self::money('paid', __('Paid'))]);
        }

        return [$cols, $rows, $m ? self::sumByCurrency($rows, [__('Revised') => 'revised']) : [], [], []];
    }

    private static function r_agreement_detail($user, $f, $m)
    {
        abort_unless($f['agreement_id'], 422, __('Choose an agreement.'));
        $a = self::agreements($user, ['agreement_id' => $f['agreement_id']] + $f)->firstOrFail();
        $x = SubcontractLedger::forAgreement($a->id);
        $rows = [];
        $items = DB::table('subcontract_work_items as i')->leftJoin('subcontract_work_groups as g', 'g.id', '=', 'i.group_id')->leftJoin('warehouses as w', 'w.id', '=', 'g.warehouse_id')->leftJoin('subcontract_variations as v', 'v.id', '=', 'i.variation_id')->where('i.agreement_id', $a->id)->whereNull('i.deleted_at')->where('i.status', 'active')
            ->orderBy('g.sort_order')->orderBy('i.line_no')->get(['i.*', DB::raw(SubcontractProjectMapping::displayNameSql('g', 'w') . ' as gname'), 'v.status as vstatus', 'v.reference as vref']);
        foreach ($items as $i) {
            $rows[] = ['_link' => ['type' => 'agreement', 'id' => $a->id], 'group' => $i->gname ?: '-', 'description' => $i->description . ($i->vref ? ' [' . $i->vref . ($i->vstatus !== 'Approved' ? ' ' . $i->vstatus : '') . ']' : ''), 'beneficiary' => $i->beneficiary_name, 'unit' => $i->unit, 'qty' => $i->quantity !== null ? rtrim(rtrim(number_format((float) $i->quantity, 4, '.', ''), '0'), '.') : '', 'rate' => $i->rate !== null ? SubcontractAmounts::group($i->rate) : '', 'amount' => $i->amount];
        }
        $tiles = [['label' => __('Original'), 'value' => SubcontractAmounts::group($x['original']) . ' ' . $a->currency], ['label' => __('Variations (net)'), 'value' => SubcontractAmounts::group($x['variations_net'])], ['label' => __('Revised'), 'value' => SubcontractAmounts::group($x['revised'])],
            ['label' => __('Approved work'), 'value' => SubcontractAmounts::group($x['approved_work'])], ['label' => __('Paid'), 'value' => SubcontractAmounts::group($x['paid_total'])], ['label' => __('Outstanding commitment'), 'value' => SubcontractAmounts::group($x['commitment_left'])]];
        $terms = [__('Agreement') . ': ' . $a->reference . ' - ' . $a->title, __('Subcontractor') . ': ' . optional($a->contact)->name, __('Project') . ': ' . (optional($a->warehouse)->name ?: '-') . ($a->site ? ' / ' . $a->site : ''), __('Status') . ': ' . $a->status, __('Payment terms') . ': ' . ($a->payment_terms ?: '-'), __('Advance terms') . ': ' . ($a->advance_terms ?: '-'), __('Retention') . ': ' . $a->retention_percent . '%'];

        return [[['key' => 'group', 'label' => __('Group')], ['key' => 'description', 'label' => __('Work item')], ['key' => 'beneficiary', 'label' => __('Beneficiary')], ['key' => 'unit', 'label' => __('Unit')], ['key' => 'qty', 'label' => __('Qty'), 'type' => 'num'], ['key' => 'rate', 'label' => __('Rate'), 'type' => 'num'], self::money('amount', __('Amount (:c)', ['c' => $a->currency]))], $rows, $tiles, $terms, []];
    }

    private static function r_request_detail($user, $f, $m)
    {
        abort_unless($f['request_id'], 422, __('Choose a payment request.'));
        $r = \App\Models\SubcontractPaymentRequest::with(['agreement.contact', 'agreement.warehouse', 'requester:id,name', 'items.workItem'])->findOrFail($f['request_id']);
        abort_unless(A::query()->visibleTo($user)->where('id', $r->agreement_id)->exists(), 403);
        $a = $r->agreement;
        $rf = SubcontractLedger::requestFigures($r->id);
        $rows = $r->items->map(fn ($i) => ['work' => optional($i->workItem)->description ?: '-', 'description' => $i->description ?: '-', 'amount' => (string) $i->amount])->all();
        $tiles = [['label' => __('Requested'), 'value' => number_format((float) $r->requested_amount, 2) . ' ' . $r->currency], ['label' => __('Approved'), 'value' => $r->approved_amount !== null ? number_format((float) $r->approved_amount, 2) : '-'],
            ['label' => __('Paid'), 'value' => SubcontractAmounts::group(($rf['paid'] ?? 0))], ['label' => __('Left to pay'), 'value' => SubcontractAmounts::group(($rf['left'] ?? 0))]];
        $notes = [__('Request') . ': ' . $r->reference . ' (' . __($r->status) . ')', __('Agreement') . ': ' . $a->reference . ' - ' . $a->title, __('Subcontractor') . ': ' . optional($a->contact)->name, __('Project') . ': ' . (optional($a->warehouse)->name ?: '-'),
            __('Date') . ': ' . self::d($r->request_date), __('Requested by') . ': ' . optional($r->requester)->name, __('For') . ': ' . ($r->description ?: '-')];
        if ($r->decision_remarks) {
            $notes[] = __('Decision remarks') . ': ' . $r->decision_remarks;
        }
        if ($r->override) {
            $notes[] = __('Approved above the contract headroom. Reason') . ': ' . ($r->override_reason ?: '-');
        }
        $notes[] = __('This request is not money paid. Only a Payment Voucher marked paid counts as a payment.');

        return [[['key' => 'work', 'label' => __('Work item')], ['key' => 'description', 'label' => __('Description')], self::money('amount', __('Amount (:c)', ['c' => $r->currency]))], $rows, $tiles, $notes, []];
    }

    private static function r_variation_detail($user, $f, $m)
    {
        abort_unless($f['variation_id'], 422, __('Choose a variation.'));
        $v = \App\Models\SubcontractVariation::with(['agreement.contact', 'agreement.warehouse', 'requester:id,name'])->findOrFail($f['variation_id']);
        abort_unless(A::query()->visibleTo($user)->where('id', $v->agreement_id)->exists(), 403);
        $a = $v->agreement;
        $items = \App\Models\SubcontractWorkItem::where('variation_id', $v->id)->orderBy('line_no')->get();
        $rows = $items->map(fn ($i) => ['description' => $i->description, 'unit' => $i->unit, 'qty' => $i->quantity !== null ? rtrim(rtrim(number_format((float) $i->quantity, 4, '.', ''), '0'), '.') : '', 'rate' => $i->rate !== null ? SubcontractAmounts::group($i->rate) : '', 'amount' => (string) $i->amount])->all();
        $sign = $v->direction === 'increase' ? '+' : '-';
        $x = SubcontractLedger::forAgreement($a->id);
        $tiles = [['label' => __('Variation'), 'value' => $sign . SubcontractAmounts::group($v->amount) . ' ' . $a->currency], ['label' => __('Agreement revised value'), 'value' => SubcontractAmounts::group($x['revised'])], ['label' => __('Original'), 'value' => SubcontractAmounts::group($x['original'])]];
        $notes = [__('Variation') . ': ' . $v->reference . ' - ' . $v->title . ' (' . __($v->status) . ')', __('Agreement') . ': ' . $a->reference . ' - ' . $a->title, __('Subcontractor') . ': ' . optional($a->contact)->name, __('Requested by') . ': ' . optional($v->requester)->name,
            __('Reason') . ': ' . ($v->reason ?: '-'), __('Effective date') . ': ' . self::d($v->effective_date)];
        if ($v->decision_remarks) {
            $notes[] = __('Decision remarks') . ': ' . $v->decision_remarks;
        }
        $notes[] = __('Only an approved variation changes the revised contract value; the original amount stays.');

        return [[['key' => 'description', 'label' => __('Work item')], ['key' => 'unit', 'label' => __('Unit')], ['key' => 'qty', 'label' => __('Qty'), 'type' => 'num'], ['key' => 'rate', 'label' => __('Rate'), 'type' => 'num'], self::money('amount', __('Amount (:c)', ['c' => $a->currency]))], $rows, $tiles, $notes, []];
    }

    private static function r_work_schedule($user, $f, $m)
    {
        $ids = self::agreements($user, $f)->pluck('id');
        $items = DB::table('subcontract_work_items as i')->join('subcontract_agreements as a', 'a.id', '=', 'i.agreement_id')->leftJoin('subcontract_work_groups as g', 'g.id', '=', 'i.group_id')->leftJoin('warehouses as w', 'w.id', '=', 'g.warehouse_id')->leftJoin('subcontract_variations as v', 'v.id', '=', 'i.variation_id')->whereIn('i.agreement_id', $ids)->whereNull('i.deleted_at')->where('i.status', 'active')->where(fn ($q) => $q->whereNull('i.variation_id')->orWhere('v.status', 'Approved'))
            ->orderBy('a.id')->orderBy('g.sort_order')->orderBy('i.line_no')->get(['i.*', DB::raw(SubcontractProjectMapping::displayNameSql('g', 'w') . ' as gname'), 'a.reference as aref', 'a.currency']);
        $rows = $items->map(fn ($i) => ['_link' => ['type' => 'agreement', 'id' => $i->agreement_id], 'agreement' => $i->aref, 'group' => $i->gname ?: '-', 'description' => $i->description, 'beneficiary' => $i->beneficiary_name, 'unit' => $i->unit, 'qty' => $i->quantity !== null ? rtrim(rtrim(number_format((float) $i->quantity, 4, '.', ''), '0'), '.') : '', 'rate' => $i->rate !== null ? SubcontractAmounts::group($i->rate) : '', 'currency' => $i->currency, 'amount' => $i->amount])->all();

        return [[['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'group', 'label' => __('Group')], ['key' => 'description', 'label' => __('Work item')], ['key' => 'beneficiary', 'label' => __('Beneficiary')], ['key' => 'unit', 'label' => __('Unit')], ['key' => 'qty', 'label' => __('Qty'), 'type' => 'num'],
            ['key' => 'rate', 'label' => __('Rate'), 'type' => 'num'], ['key' => 'currency', 'label' => __('Cur.')], self::money('amount', __('Amount'))], $rows, self::sumByCurrency($rows, [__('Schedule') => 'amount']), [], []];
    }

    private static function r_group_summary($user, $f, $m)
    {
        $ids = self::agreements($user, $f)->pluck('id');
        $rows = DB::table('subcontract_work_items as i')->join('subcontract_agreements as a', 'a.id', '=', 'i.agreement_id')->leftJoin('subcontract_work_groups as g', 'g.id', '=', 'i.group_id')->leftJoin('warehouses as w', 'w.id', '=', 'g.warehouse_id')->leftJoin('subcontract_variations as v', 'v.id', '=', 'i.variation_id')->whereIn('i.agreement_id', $ids)->whereNull('i.deleted_at')->where('i.status', 'active')->where(fn ($q) => $q->whereNull('i.variation_id')->orWhere('v.status', 'Approved'))
            ->groupBy('a.id', 'a.reference', 'a.currency', 'g.id', 'g.name', 'w.name')->orderBy('a.id')->orderBy('g.sort_order')->selectRaw('a.id aid, a.reference aref, a.currency, COALESCE(' . SubcontractProjectMapping::displayNameSql('g', 'w') . ', \'-\') gname, COUNT(*) n, SUM(i.amount) s')->get()
            ->map(fn ($r) => ['_link' => ['type' => 'agreement', 'id' => $r->aid], 'agreement' => $r->aref, 'group' => $r->gname, 'items' => (int) $r->n, 'currency' => $r->currency, 'amount' => $r->s])->all();

        return [[['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'group', 'label' => __('Work group / location')], ['key' => 'items', 'label' => __('Items'), 'type' => 'num'], ['key' => 'currency', 'label' => __('Cur.')], self::money('amount', __('Total'))], $rows, self::sumByCurrency($rows, [__('Total') => 'amount']), [__('A group total is the sum of its items.')], []];
    }

    private static function r_original_revised($user, $f, $m)
    {
        $ags = self::agreements($user, $f)->whereNotIn('status', [A::DRAFT, A::CANCELLED])->get();
        $fig = SubcontractLedger::forAgreements($ags->pluck('id')->all());
        $rows = $ags->map(fn ($a) => ['_link' => ['type' => 'agreement', 'id' => $a->id], 'reference' => $a->reference, 'subcontractor' => optional($a->contact)->name, 'title' => $a->title, 'currency' => $a->currency, 'original' => $fig[$a->id]['original'], 'up' => $fig[$a->id]['variations_up'], 'down' => $fig[$a->id]['variations_down'], 'revised' => $fig[$a->id]['revised']])->all();

        return [[['key' => 'reference', 'label' => __('Agreement')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'title', 'label' => __('Title')], ['key' => 'currency', 'label' => __('Cur.')], self::money('original', __('Original')), self::money('up', __('Approved increases')), self::money('down', __('Approved reductions')), self::money('revised', __('Revised'))],
            $rows, self::sumByCurrency($rows, [__('Original') => 'original', __('Revised') => 'revised']), [__('Revised = original + approved increases - approved reductions. Draft, submitted and rejected variations are not included.')], []];
    }

    private static function r_variations($user, $f, $m)
    {
        $ids = self::agreements($user, self::agreementsOf($f))->pluck('id');
        $vs = V::query()->whereIn('agreement_id', $ids)->when($f['status'], fn ($q, $v) => $q->where('status', $v))->when($f['from'], fn ($q, $v) => $q->whereDate('created_at', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('created_at', '<=', $v))->with(['agreement.contact:id,name', 'requester:id,name'])->orderBy('id')->get();
        $rows = $vs->map(fn ($v) => ['_link' => ['type' => 'variation', 'id' => $v->id], 'reference' => $v->reference, 'agreement' => optional($v->agreement)->reference, 'subcontractor' => optional(optional($v->agreement)->contact)->name, 'title' => $v->title, 'direction' => __(ucfirst($v->direction)), 'status' => $v->status, 'currency' => optional($v->agreement)->currency,
            'amount' => ($v->direction === 'reduction' ? '-' : '') . $v->amount, 'by' => optional($v->requester)->name, 'approved' => self::d($v->approved_at), 'reason' => $v->reason])->all();

        return [[['key' => 'reference', 'label' => __('Variation')], ['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'title', 'label' => __('Title')], ['key' => 'direction', 'label' => __('Kind')], ['key' => 'status', 'label' => __('Status')],
            ['key' => 'currency', 'label' => __('Cur.')], self::money('amount', __('Amount')), ['key' => 'by', 'label' => __('Requested by')], ['key' => 'approved', 'label' => __('Approved on')], ['key' => 'reason', 'label' => __('Reason')]], $rows, [], [], []];
    }

    private static function r_completed($user, $f, $m)
    {
        $ags = self::agreements($user, $f, [A::COMPLETED, A::TERMINATED, A::CANCELLED])->when($f['from'], fn ($q, $v) => $q->whereDate('actual_completion', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('actual_completion', '<=', $v))->get();
        $rows = $ags->map(fn ($a) => ['_link' => ['type' => 'agreement', 'id' => $a->id], 'reference' => $a->reference, 'subcontractor' => optional($a->contact)->name, 'title' => $a->title, 'project' => optional($a->warehouse)->name, 'status' => $a->status, 'started' => self::d($a->start_date), 'ended' => self::d($a->actual_completion), 'remarks' => $a->decision_remarks])->all();

        return [[['key' => 'reference', 'label' => __('Agreement')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'title', 'label' => __('Title')], ['key' => 'project', 'label' => __('Project')], ['key' => 'status', 'label' => __('Status')], ['key' => 'started', 'label' => __('Started')], ['key' => 'ended', 'label' => __('Ended')], ['key' => 'remarks', 'label' => __('Remarks')]], $rows, [], [], []];
    }

    // ---- completed contracts and contract payment requests (claims) --------------------------------------------------------------------------

    private static function r_completed_eligible($user, $f, $m)
    {
        $list = SubcontractClaims::eligibleAgreements($f['contact_id'], $f['warehouse_id'], $user, $f['status'] === 'All', $f['currency']);
        $rows = [];
        foreach ($list as $x) {
            if (($f['agreement_id'] && (int) $x['agreement_id'] !== (int) $f['agreement_id']) || ($f['from'] && ($x['completed_on'] ?? '') < $f['from']) || ($f['to'] && ($x['completed_on'] ?? '') > $f['to'])) {
                continue;
            }
            $rows[] = ['_link' => ['type' => 'agreement', 'id' => $x['agreement_id']], 'agreement' => $x['reference'], 'subcontractor' => $x['contact'], 'title' => $x['title'], 'project' => implode(', ', $x['projects']), 'currency' => $x['currency'], 'completed_on' => $x['completed_on'] ?? '', 'completed_by' => $x['completed_by'] ?? '',
                'verified' => $x['verified'] ? __('Yes') : __('No'), 'verified_by' => $x['verified_by'] ?? '', 'evidence' => $x['evidence_count'], 'state' => $x['eligible'] ? __('Eligible') : ($x['blocked_message'] ?? ''), 'revised' => $x['revised'], 'previous_paid' => $x['previous_paid'],
                'pending' => $x['pending'], 'approved_unpaid' => $x['approved_unpaid'], 'remaining' => $x['remaining_eligible']];
        }

        return [[['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'title', 'label' => __('Title')], ['key' => 'project', 'label' => __('Project')], ['key' => 'currency', 'label' => __('Cur.')], ['key' => 'completed_on', 'label' => __('Completed on')], ['key' => 'completed_by', 'label' => __('Completed by')],
            ['key' => 'verified', 'label' => __('Verified')], ['key' => 'verified_by', 'label' => __('Verified by')], ['key' => 'evidence', 'label' => __('Documents'), 'type' => 'num'], ['key' => 'state', 'label' => __('State')], self::money('revised', __('Revised contract')), self::money('previous_paid', __('Previous actual payments')),
            self::money('pending', __('Waiting (requests and claims)')), self::money('approved_unpaid', __('Approved, not paid')), self::money('remaining', __('Open to request'))], $rows, self::sumByCurrency($rows, [__('Open to request') => 'remaining']),
            [__('Open to request = revised contract value - approved work - requests and claim lines that are waiting. The agreement is the unit that is paid: work groups are not paid on their own.')], []];
    }

    private static function r_claims($user, $f, $m)
    {
        $ids = self::agreements($user, self::agreementsOf($f))->pluck('id');
        $lines = DB::table('subcontract_claim_lines as l')->join('subcontract_claims as c', 'c.id', '=', 'l.claim_id')->join('subcontract_agreements as a', 'a.id', '=', 'l.agreement_id')->join('contacts as ct', 'ct.id', '=', 'c.contact_id')->leftJoin('warehouses as w', 'w.id', '=', 'a.warehouse_id')
            ->leftJoin('subcontract_payment_requests as r', 'r.id', '=', 'l.request_id')->leftJoin('users as du', 'du.id', '=', 'l.decided_by_user_id')->whereNull('l.deleted_at')->whereNull('c.deleted_at')->whereIn('l.agreement_id', $ids)
            ->when($f['status'], fn ($q, $v) => $q->where('c.status', $v))->when($f['from'], fn ($q, $v) => $q->whereDate('c.request_date', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('c.request_date', '<=', $v))
            ->orderBy('c.id')->orderBy('l.id')->get(['l.*', 'c.reference as cref', 'c.request_date', 'c.status as cstatus', 'c.currency as ccur', 'ct.name as cname', 'a.reference as aref', 'w.name as wname', 'r.reference as rref', 'du.name as dname']);
        $rf = SubcontractLedger::requestFiguresMany($lines->pluck('request_id')->filter()->all());
        $rows = $lines->map(function ($l) use ($rf) {
            $x = $l->request_id ? ($rf[$l->request_id] ?? null) : null;

            return ['_link' => ['type' => 'claim', 'id' => $l->claim_id], 'claim' => $l->cref, 'date' => self::d($l->request_date), 'claim_status' => $l->cstatus, 'subcontractor' => $l->cname, 'agreement' => $l->aref, 'project' => $l->wname ?: '', 'currency' => $l->ccur, 'requested' => $l->requested_amount,
                'line_status' => $l->status, 'approved' => $l->approved_amount ?? '', 'decided_by' => $l->dname ?: '', 'decided_on' => self::d($l->decided_at), 'reason' => $l->decision_reason ?: '', 'request' => $l->rref ?: '', 'paid' => $x ? $x['paid'] : '', 'progress' => $x ? SubcontractClaims::progress($x) : '', 'override' => $l->override ? __('Yes') : ''];
        })->all();

        return [[['key' => 'claim', 'label' => __('Contract payment request')], ['key' => 'date', 'label' => __('Date')], ['key' => 'claim_status', 'label' => __('Request status')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'project', 'label' => __('Project')], ['key' => 'currency', 'label' => __('Cur.')],
            self::money('requested', __('Requested')), ['key' => 'line_status', 'label' => __('Line')], self::money('approved', __('Approved')), ['key' => 'decided_by', 'label' => __('Decided by')], ['key' => 'decided_on', 'label' => __('Decided on')], ['key' => 'reason', 'label' => __('Reason')], ['key' => 'request', 'label' => __('Payment request')],
            self::money('paid', __('Paid')), ['key' => 'progress', 'label' => __('Payment')], ['key' => 'override', 'label' => __('Override')]], $rows, self::sumByCurrency($rows, [__('Requested') => 'requested', __('Approved') => 'approved']),
            [__('An approved line is an ordinary approved payment request: it is also listed in the payment request reports. Only a Payment Voucher marked paid counts as paid.')], []];
    }

    private static function r_claim_allocations($user, $f, $m)
    {
        $ids = self::agreements($user, self::agreementsOf($f))->pluck('id');
        $pays = DB::table('subcontract_payments as p')->join('subcontract_claims as c', 'c.id', '=', 'p.claim_id')->join('subcontract_claim_lines as l', 'l.id', '=', 'p.claim_line_id')->join('subcontract_agreements as a', 'a.id', '=', 'p.agreement_id')->join('contacts as ct', 'ct.id', '=', 'c.contact_id')
            ->join('subcontract_payment_requests as r', 'r.id', '=', 'p.request_id')->leftJoin('payment_vouchers as v', 'v.id', '=', 'p.payment_voucher_id')->whereNull('p.deleted_at')->whereIn('p.agreement_id', $ids)
            ->orderBy('p.claim_line_id')->orderBy('p.id')->get(['p.*', 'c.reference as cref', 'a.reference as aref', 'ct.name as cname', 'r.reference as rref', 'l.approved_amount as line_approved', 'v.pv_number', 'v.status as vstatus', 'v.paid_at', 'v.deleted_at as vdel']);
        $cum = [];
        $n = [];
        $rows = [];
        foreach ($pays as $p) {
            $paid = $p->pv_number && $p->vstatus === 'Authorised' && $p->paid_at && ! $p->vdel && $p->status !== 'cancelled';
            $state = $p->status === 'cancelled' ? __('Cancelled') : ($p->pv_number ? ($paid ? __('Paid') : ($p->vdel ? __('Deleted') : $p->vstatus)) : __('Voucher not created yet'));
            $live = $p->status !== 'cancelled' && ! ($p->pv_number && ($p->vdel || in_array($p->vstatus, ['Rejected', 'Cancelled'], true)));
            if ($live) {
                $n[$p->claim_line_id] = ($n[$p->claim_line_id] ?? 0) + 1;
            }
            if ($paid) {
                $cum[$p->claim_line_id] = bcadd($cum[$p->claim_line_id] ?? '0.00', (string) $p->amount, 2);
            }
            $when = $paid ? self::d($p->paid_at) : self::d($p->created_at);
            if (($f['status'] && $f['status'] !== $state) || ($f['from'] && $when < $f['from']) || ($f['to'] && $when > $f['to'])) {
                continue;
            }
            $paidSoFar = $cum[$p->claim_line_id] ?? '0.00';
            $rows[] = ['_link' => $p->payment_voucher_id && $p->pv_number ? ['type' => 'voucher', 'id' => $p->payment_voucher_id] : ['type' => 'claim', 'id' => $p->claim_id], 'claim' => $p->cref, 'voucher' => $p->pv_number ?: '-', 'state' => $state, 'paid_on' => $paid ? self::d($p->paid_at) : '', 'subcontractor' => $p->cname, 'agreement' => $p->aref,
                'request' => $p->rref, 'instalment' => $live ? $n[$p->claim_line_id] : '', 'currency' => $p->currency, 'amount' => $p->amount, 'amount_tzs' => $p->amount_tzs, 'line_approved' => $p->line_approved ?? '', 'paid_to_date' => $paidSoFar, 'left_after' => $p->line_approved !== null ? bcsub((string) $p->line_approved, $paidSoFar, 2) : ''];
        }

        return [[['key' => 'claim', 'label' => __('Contract payment request')], ['key' => 'voucher', 'label' => __('Voucher')], ['key' => 'state', 'label' => __('State')], ['key' => 'paid_on', 'label' => __('Paid on')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'request', 'label' => __('Payment request')],
            ['key' => 'instalment', 'label' => __('Instalment'), 'type' => 'num'], ['key' => 'currency', 'label' => __('Cur.')], self::money('amount', __('Amount')), self::money('amount_tzs', __('Voucher part (TZS)')), self::money('line_approved', __('Line approved')), self::money('paid_to_date', __('Paid to date')), self::money('left_after', __('Left to pay'))],
            $rows, [], [__('One voucher can pay several agreements: the voucher parts add up to the voucher total. A line can be paid in instalments by later vouchers; "Paid to date" counts only vouchers that are Authorised and marked paid.')], []];
    }

    private static function r_claim_detail($user, $f, $m)
    {
        abort_unless($f['claim_id'], 422, __('Choose a contract payment request.'));
        $c = \App\Models\SubcontractClaim::with(['contact', 'warehouse', 'requester:id,name'])->findOrFail($f['claim_id']);
        $lines = $c->lines()->with('agreement:id,reference,title,currency,warehouse_id')->get();
        abort_unless(A::query()->visibleTo($user)->whereIn('id', $lines->pluck('agreement_id')->all() ?: [0])->count() === $lines->pluck('agreement_id')->unique()->count(), 403);
        $fig = SubcontractClaims::figures([$c->id]);
        $names = DB::table('users')->whereIn('id', $lines->pluck('decided_by_user_id')->push($c->submitted_by_user_id, $c->reviewed_by_user_id)->filter()->unique()->all() ?: [0])->pluck('name', 'id');
        $rows = $lines->map(function ($l) use ($fig) {
            $x = $fig['lines'][$l->id] ?? null;

            return ['agreement' => optional($l->agreement)->reference, 'title' => optional($l->agreement)->title, 'status' => $l->status, 'requested' => (string) $l->requested_amount, 'approved' => $l->approved_amount !== null ? (string) $l->approved_amount : '', 'paid' => $x ? $x['paid'] : '', 'left' => $x ? $x['left'] : '', 'progress' => $x ? $x['progress'] : '', 'reason' => $l->decision_reason ?: ''];
        })->all();
        $cx = $fig['claims'][$c->id] ?? null;
        $tiles = [['label' => __('Requested'), 'value' => SubcontractAmounts::group((string) $c->requested_total) . ' ' . $c->currency], ['label' => __('Approved'), 'value' => SubcontractAmounts::group((string) $c->approved_total)], ['label' => __('Paid'), 'value' => SubcontractAmounts::group($cx['paid'] ?? '0.00')], ['label' => __('Left to pay'), 'value' => SubcontractAmounts::group($cx['left'] ?? '0.00')]];
        $notes = [__('Contract payment request') . ': ' . $c->reference . ' (' . __($c->status) . ')', __('Subcontractor') . ': ' . optional($c->contact)->name, __('Project') . ': ' . (optional($c->warehouse)->name ?: '-'), __('Date') . ': ' . self::d($c->request_date), __('Requested by') . ': ' . optional($c->requester)->name,
            __('Submitted by') . ': ' . ($names[$c->submitted_by_user_id] ?? '-'), __('For') . ': ' . ($c->description ?: '-'), __('This is not money paid. Only a Payment Voucher marked paid counts as a payment.')];
        $vouchers = DB::table('subcontract_payments as p')->join('payment_vouchers as v', 'v.id', '=', 'p.payment_voucher_id')->join('subcontract_agreements as a', 'a.id', '=', 'p.agreement_id')->where('p.claim_id', $c->id)->whereNull('p.deleted_at')->where('p.status', '<>', 'cancelled')->orderBy('p.id')
            ->get(['v.pv_number', 'v.status', 'v.paid_at', 'v.deleted_at', 'a.reference as aref', 'p.amount', 'p.amount_tzs'])->map(fn ($v) => ['voucher' => $v->pv_number, 'state' => $v->paid_at && $v->status === 'Authorised' && ! $v->deleted_at ? __('Paid') : ($v->deleted_at ? __('Deleted') : $v->status), 'agreement' => $v->aref, 'amount' => $v->amount, 'amount_tzs' => $v->amount_tzs])->all();
        $sections = [['type' => 'table', 'placement' => 'after', 'title' => __('Payment vouchers'), 'empty' => __('No voucher yet.'), 'columns' => array_map(fn ($c2) => $c2 + ['align' => ($c2['type'] ?? '') === 'money' ? 'right' : 'left'], [['key' => 'voucher', 'label' => __('Voucher')], ['key' => 'state', 'label' => __('State')], ['key' => 'agreement', 'label' => __('Agreement')], self::money('amount', __('Amount (:c)', ['c' => $c->currency])), self::money('amount_tzs', __('Voucher part (TZS)'))]),
            'rows' => array_map(fn ($r) => ['voucher' => $r['voucher'], 'state' => $r['state'], 'agreement' => $r['agreement'], 'amount' => SubcontractAmounts::group($r['amount']), 'amount_tzs' => SubcontractAmounts::group($r['amount_tzs'])], $vouchers)]];

        return [[['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'title', 'label' => __('Title')], ['key' => 'status', 'label' => __('Line')], self::money('requested', __('Requested (:c)', ['c' => $c->currency])), self::money('approved', __('Approved')), self::money('paid', __('Paid')), self::money('left', __('Left to pay')), ['key' => 'progress', 'label' => __('Payment')], ['key' => 'reason', 'label' => __('Decision reason')]], $rows, $tiles, $notes, $sections];
    }

    private static function requestRows($user, $f, array $statuses = null)
    {
        $ids = self::agreements($user, self::agreementsOf($f))->pluck('id');

        return R::query()->whereIn('agreement_id', $ids)->when($statuses, fn ($q, $v) => $q->whereIn('status', $v))->when(! $statuses && $f['status'], fn ($q) => $q->where('status', $f['status']))->when($f['from'], fn ($q, $v) => $q->whereDate('request_date', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('request_date', '<=', $v))
            ->with(['agreement:id,reference,title', 'contact:id,name', 'requester:id,name'])->orderBy('id')->get();
    }

    private static function r_requests($user, $f, $m)
    {
        $rs = self::requestRows($user, $f);
        $rows = $rs->map(fn ($r) => ['_link' => ['type' => 'request', 'id' => $r->id], 'reference' => $r->reference, 'agreement' => optional($r->agreement)->reference, 'subcontractor' => optional($r->contact)->name, 'date' => self::d($r->request_date), 'kind' => __(R::KINDS[$r->kind] ?? $r->kind), 'status' => $r->status, 'currency' => $r->currency,
            'requested' => $r->requested_amount, 'approved' => $r->approved_amount ?? '', 'by' => optional($r->requester)->name, 'override' => $r->override ? __('Yes') : ''])->all();

        return [[['key' => 'reference', 'label' => __('Request')], ['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'date', 'label' => __('Date')], ['key' => 'kind', 'label' => __('Kind')], ['key' => 'status', 'label' => __('Status')], ['key' => 'currency', 'label' => __('Cur.')],
            self::money('requested', __('Requested')), self::money('approved', __('Approved')), ['key' => 'by', 'label' => __('By')], ['key' => 'override', 'label' => __('Override')]], $rows, self::sumByCurrency($rows, [__('Requested') => 'requested']), [], []];
    }

    private static function r_pending($user, $f, $m)
    {
        $rows = [];
        foreach (self::agreements($user, $f, [A::SUBMITTED, A::REVIEW])->get() as $a) {
            $rows[] = ['_link' => ['type' => 'agreement', 'id' => $a->id], 'kind' => __('Agreement'), 'reference' => $a->reference, 'subcontractor' => optional($a->contact)->name, 'title' => $a->title, 'status' => $a->status, 'since' => self::d($a->submitted_at), 'amount' => $m ? $a->original_amount : '', 'currency' => $a->currency];
        }
        $ids = self::agreements($user, self::agreementsOf($f))->pluck('id');
        foreach (V::query()->whereIn('agreement_id', $ids)->whereIn('status', [V::SUBMITTED, V::REVIEWED])->with('agreement.contact:id,name')->get() as $v) {
            $rows[] = ['_link' => ['type' => 'variation', 'id' => $v->id], 'kind' => __('Variation'), 'reference' => $v->reference, 'subcontractor' => optional(optional($v->agreement)->contact)->name, 'title' => $v->title, 'status' => $v->status, 'since' => self::d($v->updated_at), 'amount' => $m ? $v->amount : '', 'currency' => optional($v->agreement)->currency];
        }
        foreach (self::requestRows($user, $f, [R::SUBMITTED, R::REVIEW]) as $r) {
            $rows[] = ['_link' => ['type' => 'request', 'id' => $r->id], 'kind' => __('Payment request'), 'reference' => $r->reference, 'subcontractor' => optional($r->contact)->name, 'title' => $r->description ?: optional($r->agreement)->title, 'status' => $r->status, 'since' => self::d($r->updated_at), 'amount' => $m ? $r->requested_amount : '', 'currency' => $r->currency];
        }

        if (\App\Support\SchemaCache::hasTable('subcontract_claims')) {
            // contract payment requests with a line that still waits for a decision (a claim whose agreements the reader may not all see is left out)
            $ok = $ids->all();
            foreach (DB::table('subcontract_claims as c')->join('contacts as ct', 'ct.id', '=', 'c.contact_id')->whereNull('c.deleted_at')->whereNotIn('c.status', ['Draft', 'Cancelled'])
                ->whereExists(fn ($e) => $e->from('subcontract_claim_lines as l')->whereColumn('l.claim_id', 'c.id')->whereNull('l.deleted_at')->where('l.status', 'Pending')->whereIn('l.agreement_id', $ok))
                ->whereNotExists(fn ($e) => $e->from('subcontract_claim_lines as n')->whereColumn('n.claim_id', 'c.id')->whereNull('n.deleted_at')->where('n.status', '<>', 'Cancelled')->whereNotIn('n.agreement_id', $ok))
                ->when($f['contact_id'], fn ($q, $v) => $q->where('c.contact_id', $v))->get(['c.id', 'c.reference', 'c.status', 'c.currency', 'c.description', 'c.updated_at', 'ct.name as cname']) as $cl) {
                $amt = $m ? (string) DB::table('subcontract_claim_lines')->where('claim_id', $cl->id)->where('status', 'Pending')->whereNull('deleted_at')->sum('requested_amount') : '';
                $rows[] = ['_link' => ['type' => 'claim', 'id' => $cl->id], 'kind' => __('Contract payment request'), 'reference' => $cl->reference, 'subcontractor' => $cl->cname, 'title' => $cl->description ?: __('Contract payment request'), 'status' => $cl->status, 'since' => self::d($cl->updated_at), 'amount' => $amt !== '' ? SubcontractAmounts::money($amt) : '', 'currency' => $cl->currency];
            }
        }

        return [[['key' => 'kind', 'label' => __('Kind')], ['key' => 'reference', 'label' => __('Reference')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'title', 'label' => __('Title / description')], ['key' => 'status', 'label' => __('Status')], ['key' => 'since', 'label' => __('Since')], ['key' => 'currency', 'label' => __('Cur.')], self::money('amount', __('Amount'))], $rows, [], [], []];
    }

    private static function r_approved_unpaid($user, $f, $m)
    {
        $rows = [];
        $reqs = self::requestRows($user, $f, [R::APPROVED]);
        $figs = SubcontractLedger::requestFiguresMany(collect($reqs)->pluck('id')->all()); // two queries for every request, not two per request
        foreach ($reqs as $r) {
            $x = $figs[$r->id];
            if (bccomp(bcsub($x['approved'], $x['paid'], 2), '0', 2) <= 0) {
                continue;
            }
            $rows[] = ['_link' => ['type' => 'request', 'id' => $r->id], 'reference' => $r->reference, 'agreement' => optional($r->agreement)->reference, 'subcontractor' => optional($r->contact)->name, 'approved_on' => self::d($r->approved_at), 'currency' => $r->currency, 'approved' => $x['approved'], 'paid' => $x['paid'], 'in_process' => $x['in_process'], 'unpaid' => bcsub($x['approved'], $x['paid'], 2)];
        }

        return [[['key' => 'reference', 'label' => __('Request')], ['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'approved_on', 'label' => __('Approved on')], ['key' => 'currency', 'label' => __('Cur.')],
            self::money('approved', __('Approved')), self::money('paid', __('Paid')), self::money('in_process', __('In a voucher, not paid')), self::money('unpaid', __('Approved unpaid'))], $rows, self::sumByCurrency($rows, [__('Approved unpaid') => 'unpaid']), [], []];
    }

    private static function paymentRows($user, $f)
    {
        $ids = self::agreements($user, self::agreementsOf($f))->pluck('id');

        return DB::table('subcontract_payments as p')->join('subcontract_agreements as a', 'a.id', '=', 'p.agreement_id')->join('contacts as c', 'c.id', '=', 'a.contact_id')->join('subcontract_payment_requests as r', 'r.id', '=', 'p.request_id')->leftJoin('payment_vouchers as v', 'v.id', '=', 'p.payment_voucher_id')
            ->whereIn('p.agreement_id', $ids)->whereNull('p.deleted_at')->orderBy('p.id')->get(['p.*', 'a.reference as aref', 'a.warehouse_id', 'c.name as cname', 'r.reference as rref', 'v.pv_number', 'v.status as vstatus', 'v.paid_at', 'v.deleted_at as vdel']);
    }

    private static function r_voucher_history($user, $f, $m)
    {
        $rows = [];
        foreach (self::paymentRows($user, $f) as $p) {
            $paid = $p->pv_number && $p->vstatus === 'Authorised' && $p->paid_at && ! $p->vdel;
            $state = $p->status === 'cancelled' ? __('Cancelled') : ($p->pv_number ? ($paid ? __('Paid') : ($p->vdel ? __('Deleted') : $p->vstatus)) : __('Voucher not created yet'));
            if ($f['status'] && $f['status'] !== $state) {
                continue;
            }
            $when = $paid ? self::d($p->paid_at) : self::d($p->created_at); // the day it was paid, or the day it was started
            if (($f['from'] && $when < $f['from']) || ($f['to'] && $when > $f['to'])) {
                continue;
            }
            $rows[] = ['_link' => $p->payment_voucher_id && $p->pv_number ? ['type' => 'voucher', 'id' => $p->payment_voucher_id] : ['type' => 'request', 'id' => $p->request_id], 'subcontractor' => $p->cname, 'agreement' => $p->aref, 'request' => $p->rref, 'voucher' => $p->pv_number ?: '-', 'state' => $state, 'paid_on' => $paid ? self::d($p->paid_at) : '', 'currency' => $p->currency, 'amount' => $p->amount, 'amount_tzs' => $p->amount_tzs];
        }

        return [[['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'request', 'label' => __('Request')], ['key' => 'voucher', 'label' => __('Voucher')], ['key' => 'state', 'label' => __('State')], ['key' => 'paid_on', 'label' => __('Paid on')],
            ['key' => 'currency', 'label' => __('Cur.')], self::money('amount', __('Amount')), self::money('amount_tzs', __('Voucher (TZS)'))], $rows, [], [], []];
    }

    private static function r_payments($user, $f, $m)
    {
        $rows = [];
        foreach (self::paymentRows($user, $f) as $p) {
            $paid = $p->pv_number && $p->vstatus === 'Authorised' && $p->paid_at && ! $p->vdel;
            if (! $paid || $p->status === 'cancelled') {
                continue;
            }
            $d = self::d($p->paid_at);
            if (($f['from'] && $d < $f['from']) || ($f['to'] && $d > $f['to'])) {
                continue;
            }
            $rows[] = ['_link' => ['type' => 'voucher', 'id' => $p->payment_voucher_id], 'date' => $d, 'subcontractor' => $p->cname, 'agreement' => $p->aref, 'voucher' => $p->pv_number, 'request' => $p->rref, 'currency' => $p->currency, 'amount' => $p->amount, 'amount_tzs' => $p->amount_tzs];
        }
        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return [[['key' => 'date', 'label' => __('Paid on')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'voucher', 'label' => __('Voucher')], ['key' => 'request', 'label' => __('Request')], ['key' => 'currency', 'label' => __('Cur.')], self::money('amount', __('Amount')), self::money('amount_tzs', __('Voucher (TZS)'))],
            $rows, self::sumByCurrency($rows, [__('Paid') => 'amount']), [__('Only vouchers that are Authorised and marked paid are listed. Historical (Excel) payments are not Payment Vouchers and appear in the account statement.')], []];
    }

    private static function r_payments_project($user, $f, $m)
    {
        $by = [];
        foreach (self::paymentRows($user, $f) as $p) {
            $paid = $p->pv_number && $p->vstatus === 'Authorised' && $p->paid_at && ! $p->vdel && $p->status !== 'cancelled';
            $d = $paid ? self::d($p->paid_at) : null;
            if (! $paid || ($f['from'] && $d < $f['from']) || ($f['to'] && $d > $f['to'])) {
                continue;
            }
            $k = ($p->warehouse_id ?: 0) . '|' . $p->currency;
            $by[$k] ??= ['wh' => $p->warehouse_id, 'currency' => $p->currency, 'live' => '0.00', 'legacy' => '0.00'];
            $by[$k]['live'] = bcadd($by[$k]['live'], (string) $p->amount, 2);
        }
        $ids = self::agreements($user, $f)->get(['id', 'warehouse_id', 'currency']);
        $wh = $ids->keyBy('id');
        foreach (DB::table('subcontract_historical_payments')->whereIn('agreement_id', $ids->pluck('id'))->whereNull('deleted_at')->where('counted', 1)->selectRaw('agreement_id, SUM(amount) s')->groupBy('agreement_id')->get() as $h) {
            $a = $wh[$h->agreement_id];
            $k = ($a->warehouse_id ?: 0) . '|' . $a->currency;
            $by[$k] ??= ['wh' => $a->warehouse_id, 'currency' => $a->currency, 'live' => '0.00', 'legacy' => '0.00'];
            $by[$k]['legacy'] = bcadd($by[$k]['legacy'], (string) $h->s, 2);
        }
        $names = DB::table('warehouses')->whereIn('id', collect($by)->pluck('wh')->filter())->pluck('name', 'id');
        $rows = collect($by)->map(fn ($r) => ['project' => $r['wh'] ? ($names[$r['wh']] ?? '-') : __('No project'), 'currency' => $r['currency'], 'live' => $r['live'], 'legacy' => $r['legacy'], 'total' => bcadd($r['live'], $r['legacy'], 2)])->sortBy('project')->values()->all();

        return [[['key' => 'project', 'label' => __('Project')], ['key' => 'currency', 'label' => __('Cur.')], self::money('live', __('Paid through vouchers')), self::money('legacy', __('Historical payments (counted)')), self::money('total', __('Total paid'))], $rows, self::sumByCurrency($rows, [__('Paid') => 'total']),
            [__('Historical payments have no payment date in the workbook totals, so the date filter applies to vouchers only.')], []];
    }

    private static function r_payments_location($user, $f, $m)
    {
        $ids = self::agreements($user, $f)->pluck('id');
        $rows = DB::table('subcontract_payment_allocations as al')->join('subcontract_payments as p', 'p.id', '=', 'al.payment_id')->join('payment_vouchers as v', 'v.id', '=', 'p.payment_voucher_id')->join('subcontract_work_items as i', 'i.id', '=', 'al.work_item_id')->leftJoin('subcontract_work_groups as g', 'g.id', '=', 'i.group_id')->leftJoin('warehouses as w', 'w.id', '=', 'g.warehouse_id')
            ->join('subcontract_agreements as a', 'a.id', '=', 'p.agreement_id')->whereIn('p.agreement_id', $ids)->whereNull('p.deleted_at')->where('p.status', '<>', 'cancelled')->where('v.status', 'Authorised')->whereNotNull('v.paid_at')->whereNull('v.deleted_at')
            ->when($f['from'], fn ($q, $v) => $q->whereDate('v.paid_at', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('v.paid_at', '<=', $v))
            ->groupBy('a.id', 'a.reference', 'a.currency', 'g.name', 'w.name')->selectRaw("a.id aid, a.reference aref, a.currency, COALESCE(" . SubcontractProjectMapping::displayNameSql('g', 'w') . ", '-') gname, SUM(al.amount) s")->get()->map(fn ($r) => ['_link' => ['type' => 'agreement', 'id' => $r->aid], 'agreement' => $r->aref, 'location' => $r->gname, 'currency' => $r->currency, 'amount' => $r->s])->all();

        return [[['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'location', 'label' => __('Work group / location')], ['key' => 'currency', 'label' => __('Cur.')], self::money('amount', __('Paid'))], $rows, self::sumByCurrency($rows, [__('Paid') => 'amount']),
            [__('Only payments allocated to work items appear here; a payment on a request without work items is paid at agreement level (see the other payment reports).')], []];
    }

    private static function r_deductions($user, $f, $m)
    {
        $rows = [];
        $ags = self::agreements($user, $f)->get();
        foreach ($ags as $a) {
            if ((float) $a->retention_percent > 0 || $a->advance_terms) {
                $rows[] = ['_link' => ['type' => 'agreement', 'id' => $a->id], 'kind' => __('Terms'), 'agreement' => $a->reference, 'subcontractor' => optional($a->contact)->name, 'reference' => '-', 'currency' => $a->currency, 'amount' => '', 'note' => __('Retention :p%', ['p' => $a->retention_percent]) . ($a->advance_terms ? '; ' . mb_substr($a->advance_terms, 0, 120) : '')];
            }
        }
        foreach (R::query()->whereIn('agreement_id', $ags->pluck('id'))->where('kind', 'advance')->with(['agreement:id,reference', 'contact:id,name'])->get() as $r) {
            $rows[] = ['_link' => ['type' => 'request', 'id' => $r->id], 'kind' => __('Advance request'), 'agreement' => optional($r->agreement)->reference, 'subcontractor' => optional($r->contact)->name, 'reference' => $r->reference, 'currency' => $r->currency, 'amount' => $r->approved_amount ?? $r->requested_amount, 'note' => $r->status];
        }

        return [[['key' => 'kind', 'label' => __('Kind')], ['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'reference', 'label' => __('Reference')], ['key' => 'currency', 'label' => __('Cur.')], self::money('amount', __('Amount')), ['key' => 'note', 'label' => __('Note')]], $rows, [],
            [__('WIMS does not yet apply retention or recoveries automatically: they are recorded as terms and as advance requests. Nothing is deducted silently.')], []];
    }

    private static function r_currency($user, $f, $m)
    {
        $rows = [];
        foreach (self::paymentRows($user, $f) as $p) {
            if ($p->currency === 'TZS' || ($f['currency'] && $p->currency !== $f['currency']) || $p->status === 'cancelled') {
                continue;
            }
            if ($p->pv_number && ($p->vdel || in_array($p->vstatus, ['Rejected', 'Cancelled'], true))) {
                continue; // its voucher is void: nothing was paid and nothing is waiting
            }
            $paid = $p->pv_number && $p->vstatus === 'Authorised' && $p->paid_at && ! $p->vdel;
            $date = self::d($p->paid_at) ?: self::d($p->created_at);
            if (($f['from'] && $date < $f['from']) || ($f['to'] && $date > $f['to'])) {
                continue;
            }
            $rows[] = ['_link' => ['type' => 'agreement', 'id' => $p->agreement_id], 'source' => 'WIMS', 'state' => $paid ? __('Paid') : __('In a voucher, not paid'), 'subcontractor' => $p->cname, 'agreement' => $p->aref, 'reference' => $p->pv_number ?: $p->rref, 'date' => $date, 'currency' => $p->currency, 'amount' => $p->amount, 'paid_amount' => $paid ? $p->amount : '0.00', 'rate' => (string) $p->exchange_rate, 'rate_date' => self::d($p->rate_date), 'tzs' => $p->amount_tzs];
        }
        $ids = self::agreements($user, $f)->pluck('id');
        foreach (DB::table('subcontract_historical_payments as h')->join('subcontract_agreements as a', 'a.id', '=', 'h.agreement_id')->join('contacts as c', 'c.id', '=', 'a.contact_id')->whereIn('h.agreement_id', $ids)->whereNull('h.deleted_at')->where('h.counted', 1)->where('h.currency', '<>', 'TZS')->when($f['currency'], fn ($q, $v) => $q->where('h.currency', $v))->get(['h.*', 'a.reference as aref', 'c.name as cname']) as $h) {
            $rows[] = ['_link' => ['type' => 'agreement', 'id' => $h->agreement_id], 'source' => __('Historical'), 'state' => __('Paid'), 'subcontractor' => $h->cname, 'agreement' => $h->aref, 'reference' => $h->source_sheet, 'date' => self::d($h->payment_date), 'currency' => $h->currency, 'amount' => $h->amount, 'paid_amount' => $h->amount, 'rate' => (string) $h->exchange_rate, 'rate_date' => self::d($h->rate_date), 'tzs' => $h->amount_tzs ?? ''];
        }

        return [[['key' => 'source', 'label' => __('Source')], ['key' => 'state', 'label' => __('State')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'agreement', 'label' => __('Agreement')], ['key' => 'reference', 'label' => __('Reference')], ['key' => 'date', 'label' => __('Date')], ['key' => 'currency', 'label' => __('Cur.')], self::money('amount', __('Amount')),
            ['key' => 'rate', 'label' => __('Rate'), 'type' => 'num'], ['key' => 'rate_date', 'label' => __('Rate date')], self::money('tzs', __('TZS equivalent'))], $rows, self::sumByCurrency($rows, [__('Paid') => 'paid_amount']),
            [__('Historical rates come from the workbook and are kept as they were; a rate is never applied to a new payment automatically.'), __('The date range applies to WIMS payments (the day paid, or the day the payment was started); historical payments are listed whatever the range. The Paid total counts only vouchers marked paid and counted historical payments.')], []];
    }

    private static function r_commitments($user, $f, $m)
    {
        $ags = self::agreements($user, $f, A::PAYABLE)->get();
        $fig = SubcontractLedger::forAgreements($ags->pluck('id')->all());
        $rows = $ags->map(fn ($a) => ['_link' => ['type' => 'agreement', 'id' => $a->id], 'reference' => $a->reference, 'subcontractor' => optional($a->contact)->name, 'project' => optional($a->warehouse)->name, 'currency' => $a->currency, 'revised' => $fig[$a->id]['revised'], 'paid' => $fig[$a->id]['paid_total'], 'left' => $fig[$a->id]['commitment_left']])->filter(fn ($r) => bccomp($r['left'], '0', 2) !== 0)->values()->all();

        return [[['key' => 'reference', 'label' => __('Agreement')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'project', 'label' => __('Project')], ['key' => 'currency', 'label' => __('Cur.')], self::money('revised', __('Revised contract')), self::money('paid', __('Paid')), self::money('left', __('Outstanding commitment'))],
            $rows, self::sumByCurrency($rows, [__('Outstanding commitment') => 'left']), [__('Outstanding commitment is the contract value still to be paid, whether or not it is approved for payment.')], []];
    }

    private static function r_payables($user, $f, $m)
    {
        $ags = self::agreements($user, $f, A::PAYABLE)->get();
        $fig = SubcontractLedger::forAgreements($ags->pluck('id')->all());
        $rows = $ags->map(fn ($a) => ['_link' => ['type' => 'agreement', 'id' => $a->id], 'reference' => $a->reference, 'subcontractor' => optional($a->contact)->name, 'project' => optional($a->warehouse)->name, 'currency' => $a->currency, 'approved' => $fig[$a->id]['approved_requests'], 'paid_live' => $fig[$a->id]['paid_live'], 'unpaid' => $fig[$a->id]['approved_unpaid']])->filter(fn ($r) => bccomp($r['unpaid'], '0', 2) > 0)->values()->all();

        return [[['key' => 'reference', 'label' => __('Agreement')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'project', 'label' => __('Project')], ['key' => 'currency', 'label' => __('Cur.')], self::money('approved', __('Approved requests')), self::money('paid_live', __('Paid on them')), self::money('unpaid', __('Outstanding payable'))],
            $rows, self::sumByCurrency($rows, [__('Outstanding payable') => 'unpaid']), [], []];
    }

    private static function r_reconciliation($user, $f, $m)
    {
        $ids = self::agreements($user, self::agreementsOf($f))->pluck('id');
        $rs = DB::table('subcontract_reconciliations as r')->join('subcontract_agreements as a', 'a.id', '=', 'r.agreement_id')->join('contacts as c', 'c.id', '=', 'a.contact_id')->whereIn('r.agreement_id', $ids)->whereNull('r.deleted_at')->when($f['status'], fn ($q, $v) => $q->where('r.status', $v))->orderBy('r.id')->get(['r.*', 'a.reference as aref', 'a.currency', 'c.name as cname']);
        $fig = SubcontractLedger::forAgreements($rs->pluck('agreement_id')->all());
        $rows = $rs->map(fn ($r) => ['_link' => ['type' => 'agreement', 'id' => $r->agreement_id], 'reference' => $r->aref, 'subcontractor' => $r->cname, 'currency' => $r->currency, 'src_contract' => $r->source_contract, 'wims_contract' => $fig[$r->agreement_id]['original'] ?? '', 'src_paid' => $r->source_paid, 'wims_paid' => $fig[$r->agreement_id]['paid_legacy'] ?? '',
            'diff' => bcsub((string) ($fig[$r->agreement_id]['paid_legacy'] ?? '0'), (string) ($r->source_paid ?? '0'), 2), 'dated' => $r->dated_list_total, 'status' => $r->status, 'note' => $r->note])->all();

        return [[['key' => 'reference', 'label' => __('Agreement')], ['key' => 'subcontractor', 'label' => __('Subcontractor')], ['key' => 'currency', 'label' => __('Cur.')], self::money('src_contract', __('Workbook contract')), self::money('wims_contract', __('WIMS original')), self::money('src_paid', __('Workbook paid')), self::money('wims_paid', __('WIMS historical paid')),
            self::money('diff', __('Difference')), self::money('dated', __('Dated list total')), ['key' => 'status', 'label' => __('Status')], ['key' => 'note', 'label' => __('Note')]], $rows, [], [__('The "dated list" is evidence of payment dates; it is never added to the paid figure.')], []];
    }

    private static function r_import($user, $f, $m)
    {
        $sheets = DB::table('subcontract_import_sheets as s')->join('subcontract_import_batches as b', 'b.id', '=', 's.batch_id')->whereNull('s.deleted_at')->when($f['from'], fn ($q, $v) => $q->whereDate('b.created_at', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('b.created_at', '<=', $v))->orderBy('s.id')
            ->get(['s.*', 'b.reference as bref', 'b.status as bstatus']);
        $refs = A::withoutGlobalScopes()->whereIn('id', $sheets->pluck('agreement_id')->filter()->unique()->values()->all() ?: [0])->pluck('reference', 'id'); // one query for the agreement references
        $rows = $sheets->map(function ($s) use ($refs) {
                $t = json_decode($s->totals ?? '[]', true) ?: [];
                $fl = json_decode($s->flags ?? '[]', true) ?: [];

                return ['_link' => ['type' => 'import', 'id' => $s->batch_id], 'batch' => $s->bref, 'sheet' => $s->sheet_name, 'state' => $s->sheet_state, 'decision' => $s->decision, 'subcontractor' => $s->suggested_name, 'currency' => $s->currency, 'contract' => $t['contract'] ?? '', 'paid' => $t['paid'] ?? '', 'dated' => $t['dated_sum'] ?? '', 'flags' => count($fl), 'agreement' => $s->agreement_id ? ($refs[$s->agreement_id] ?? '') : ''];
            })->all();

        return [[['key' => 'batch', 'label' => __('Batch')], ['key' => 'sheet', 'label' => __('Sheet')], ['key' => 'state', 'label' => __('Hidden?')], ['key' => 'decision', 'label' => __('Decision')], ['key' => 'subcontractor', 'label' => __('Account')], ['key' => 'currency', 'label' => __('Cur.')], self::money('contract', __('Contract')), self::money('paid', __('Paid')),
            self::money('dated', __('Dated list')), ['key' => 'flags', 'label' => __('Flags'), 'type' => 'num'], ['key' => 'agreement', 'label' => __('Agreement made')]], $rows, [], [], []];
    }

    private static function r_audit($user, $f, $m)
    {
        abort_unless($user->can('view-audit-subcontracts'), 403);
        $rows = DB::table('activity_log as l')->leftJoin('users as u', 'u.id', '=', 'l.causer_id')->where('l.log_name', 'Subcontracts')->when($f['from'], fn ($q, $v) => $q->whereDate('l.created_at', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('l.created_at', '<=', $v))
            ->orderByDesc('l.id')->limit(self::LIMIT + 1)->get(['l.created_at', 'l.description', 'u.name', 'l.properties'])->map(fn ($r) => ['at' => substr((string) $r->created_at, 0, 16), 'who' => $r->name ?: __('System'), 'what' => $r->description, 'detail' => mb_substr(self::scrub((string) $r->properties), 0, 220)])->all();

        return [[['key' => 'at', 'label' => __('When')], ['key' => 'who', 'label' => __('Who')], ['key' => 'what', 'label' => __('What')], ['key' => 'detail', 'label' => __('Detail')]], $rows, [], [__('Passwords and secrets are never recorded in this log.')], []];
    }

    private static function scrub(string $json): string
    {
        return preg_replace('/"(password|token|secret)[^"]*":"[^"]*"/i', '"$1":"***"', $json) ?? $json;
    }
}
