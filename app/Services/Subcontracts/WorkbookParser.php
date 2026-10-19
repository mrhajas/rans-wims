<?php

namespace App\Services\Subcontracts;

/**
 * Reads the subcontracts workbook into a staging model, sheet by sheet, WITHOUT interpreting more than the evidence supports (see docs/subcontracts-workbook-analysis.md).
 *
 * Every account sheet has: a header row (S.NO, DESCRIPTION, BENEFICIARY, [UNIT, QTY, RATE], CONTRACT or AMOUNT, PAID, BALANCE, PROPOSAL or REQUEST, REMARKS), work groups
 * (a heading in column A), item rows, subtotal rows, a SUMMARY block with a TOTAL row, and a PAYMENT DATE list. Layout A has quantity and rate, layout B has not.
 * Whatever cannot be read with confidence is kept and flagged; nothing is dropped.
 */
class WorkbookParser
{
    private const LABELS = ['DESCRIPTION' => 'desc', 'PROJECT' => 'desc', 'BENEFICIARY' => 'benef', 'UNIT' => 'unit', 'QTY' => 'qty', 'RATE' => 'rate', 'CONTRACT' => 'contract', 'AMOUNT' => 'contract', 'PAID' => 'paid', 'BALANCE' => 'balance', 'PROPOSAL' => 'proposal', 'REQUEST' => 'proposal', 'REMARKS' => 'remarks', 'REMARK' => 'remarks'];

    private XlsxReader $xr;

    public function __construct(XlsxReader $xr)
    {
        $this->xr = $xr;
    }

    /** All sheets: [sheetName => parsed|null]. SUMMARY is returned separately as the control list. */
    public function parseAll(): array
    {
        $sheets = $this->xr->sheets();
        $out = ['sheets' => [], 'summary' => []];
        foreach ($sheets as $s) {
            if (strtoupper(trim($s['name'])) === 'SUMMARY') {
                $out['summary'] = $this->summary($s['name']);
                continue;
            }
            $out['sheets'][] = ['name' => $s['name'], 'state' => $s['state']] + $this->parseSheet($s['name']);
        }

        return $out;
    }

    /** SUMMARY lines: [row, name, works, contract, paid, balance, ref (sheet name the formula points at)]. */
    public function summary(string $sheet): array
    {
        $cells = $this->xr->cells($sheet);
        $rows = [];
        foreach ($cells as $r => $row) {
            $contract = $row[4] ?? null;
            if (! $contract || ! is_float($contract['v'] ?? null) || $r < 4 || strtoupper(trim((string) ($row[2]['v'] ?? ''))) === 'TOTAL') {
                continue;
            }
            $ref = null;
            if (! empty($contract['f']) && preg_match("/^\(?'?([^'!]+)'?!/", $contract['f'], $m)) {
                $ref = str_replace("''", "'", $m[1]);
            }
            $rows[] = ['row' => $r, 'name' => trim((string) ($row[2]['v'] ?? '')), 'works' => trim((string) ($row[3]['v'] ?? '')), 'contract' => $contract['v'], 'paid' => (float) ($row[5]['v'] ?? 0), 'balance' => (float) ($row[6]['v'] ?? 0), 'ref' => $ref, 'remarks' => trim((string) ($row[7]['v'] ?? '')), 'formula' => $contract['f']];
        }

        return $rows;
    }

    public function parseSheet(string $sheet): array
    {
        $cells = $this->xr->cells($sheet);
        $maxRow = $cells ? max(array_keys($cells)) : 0;
        $flags = [];
        $val = fn ($r, $c) => $cells[$r][$c]['v'] ?? null;
        $str = function ($r, $c) use ($cells) {
            $v = $cells[$r][$c]['v'] ?? null;

            return is_string($v) ? trim(preg_replace('/\s{2,}/u', ' ', str_replace("\u{a0}", ' ', $v))) : (is_float($v) ? rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') : '');
        };
        $isNum = fn ($v) => is_float($v);

        // header row
        $hr = null;
        $cm = [];
        foreach ($cells as $r => $row) {
            $m = [];
            foreach ($row as $c => $cell) {
                $t = is_string($cell['v']) ? strtoupper(rtrim(trim($cell['v']), ':')) : '';
                if (isset(self::LABELS[$t]) && ! isset($m[self::LABELS[$t]])) {
                    $m[self::LABELS[$t]] = $c;
                }
            }
            if (isset($m['contract'], $m['paid'])) {
                $hr = $r;
                $cm = $m;
                break;
            }
        }
        if ($hr === null) {
            return ['error' => 'No header row with CONTRACT (or AMOUNT) and PAID was found.', 'flags' => [['level' => 'error', 'text' => 'No header row found: this sheet cannot be imported automatically.']]];
        }
        $cm += ['desc' => 2, 'benef' => 3];
        $layout = isset($cm['qty']) ? 'A' : 'B';

        // markers
        $summaryRow = null;
        $payRow = null;
        $payCol = 2;
        foreach ($cells as $r => $row) {
            if ($r <= $hr) {
                continue;
            }
            if ($summaryRow === null) {
                foreach ([1, 2, 3] as $c) {
                    if (isset($row[$c]) && is_string($row[$c]['v']) && strtoupper(trim($row[$c]['v'])) === 'SUMMARY') {
                        $summaryRow = $r;
                    }
                }
            }
            foreach ($row as $c => $cell) {
                if ($payRow === null && is_string($cell['v']) && str_starts_with(strtoupper(trim($cell['v'])), 'PAYMENT DATE')) {
                    $payRow = $r;
                    $payCol = $c;
                }
            }
        }
        $end = min(array_filter([$summaryRow, $payRow, $maxRow + 1], fn ($x) => $x !== null));

        // title and as-at date
        $titleParts = [];
        for ($r = 1; $r < $hr; $r++) {
            foreach ([1, 2, 3] as $c) {
                if ($str($r, $c) !== '') {
                    $titleParts[] = $str($r, $c);
                }
            }
        }
        $title = implode(' / ', $titleParts);
        [$suggested, $asAt] = $this->nameAndDate($title ?: $sheet, $sheet);

        // schedule
        $groups = [];
        $cur = ['name' => null, 'row' => $hr, 'notes' => [], 'items' => [], 'currency' => null, 'rate' => null];
        $sub = [];
        $payOnly = [];
        $totals = null;
        $spacers = 0;
        $lastItem = null;
        $usd = false;
        $mixed = false;
        $rateHint = null;
        for ($r = $hr + 1; $r < $end; $r++) {
            if (! isset($cells[$r])) {
                continue;
            }
            $a = isset($cells[$r][1]) && is_string($cells[$r][1]['v']) ? trim($cells[$r][1]['v']) : '';
            $b = $str($r, $cm['desc']);
            $benef = $str($r, $cm['benef']);
            $cv = $val($r, $cm['contract']);
            $pv = $val($r, $cm['paid']);
            $hasNum = $isNum($cv) || $isNum($pv);
            $cf = $cells[$r][$cm['contract']]['f'] ?? null;
            if (! $hasNum && $b === '' && $a !== '') {
                if ($cur['items'] || $cur['name'] !== null || $cur['notes']) {
                    $groups[] = $cur;
                }
                $cur = ['name' => $a, 'row' => $r, 'notes' => [], 'items' => [], 'currency' => null, 'rate' => null];
                continue;
            }
            if (! $hasNum && $b !== '' && $benef === '' && ! $isNum($val($r, $cm['proposal'] ?? 0))) {
                $cur['notes'][] = ['row' => $r, 'text' => $b];
                if (preg_match('/US\s*\$|USD/i', $b)) {
                    $cur['currency'] = 'USD';
                    $usd = true;
                }
                continue;
            }
            if ($hasNum && $b === '') {
                if (is_string($cf) && stripos($cf, 'SUM(') !== false) {
                    $sub[] = ['row' => $r, 'contract' => $cv, 'paid' => $pv];
                    $cur['subtotal'] = ['row' => $r, 'contract' => $isNum($cv) ? (float) $cv : null, 'paid' => $isNum($pv) ? (float) $pv : null, 'formula' => $cf];
                    continue;
                }
                $label = strtoupper($str($r, 1) . $str($r, 3));
                if (str_starts_with($label, 'TOTAL') || ($rateHint === null && false)) {
                    $sub[] = ['row' => $r, 'contract' => $cv, 'paid' => $pv];
                    continue;
                }
                if ($isNum($pv) && ! $isNum($cv)) {
                    $payOnly[] = ['row' => $r, 'amount' => $pv, 'group' => $cur['name'], 'after_item_row' => $lastItem['row'] ?? null, 'formula' => $cells[$r][$cm['paid']]['f'] ?? null];
                } else {
                    $sub[] = ['row' => $r, 'contract' => $cv, 'paid' => $pv];
                    $cur['subtotal'] = ['row' => $r, 'contract' => $isNum($cv) ? (float) $cv : null, 'paid' => $isNum($pv) ? (float) $pv : null, 'formula' => is_string($cf) ? $cf : null];
                }
                continue;
            }
            if ($b !== '' && str_starts_with(strtoupper($b), 'TOTAL')) {
                if (preg_match('/TOTAL IN (USD|TZS)/i', $b, $mm) && strtoupper($mm[1]) === 'TZS') {
                    // "TOTAL IN TZS" row: the conversion rate sits in the remarks column of that row
                    $rv = $val($r, $cm['remarks'] ?? 8);
                    if ($isNum($rv)) {
                        $rateHint = $rv;
                        $mixed = true;
                    }
                }
                $totals = ['row' => $r, 'contract' => $cv, 'paid' => $pv];
                $sub[] = ['row' => $r, 'contract' => $cv, 'paid' => $pv];
                continue;
            }
            // a spacer row: no description and nothing in any number column (the beneficiary alone is not an item)
            if ($b === '' && ! $hasNum && ! (isset($cm['qty']) && $isNum($val($r, $cm['qty']))) && ! (isset($cm['rate']) && $isNum($val($r, $cm['rate'])))) {
                $spacers++;
                continue;
            }
            // a heading typed in column A on the same row as the section's first item (e.g. "MWERA" beside the first line): the section starts here
            if ($a !== '' && ! preg_match('/^[\d.\s\/-]+$/', $a) && $b !== '') {
                if ($cur['items'] || $cur['name'] !== null || $cur['notes']) {
                    $groups[] = $cur;
                }
                $cur = ['name' => $a, 'row' => $r, 'notes' => [], 'items' => [], 'currency' => null, 'rate' => null];
            }
            // an item (also when it has a description and a beneficiary but no amount yet: it is kept, with zero)
            $paidF = $cells[$r][$cm['paid']]['f'] ?? null;
            $item = ['row' => $r, 'desc' => $b, 'benef' => $benef, 'unit_raw' => isset($cm['unit']) ? $str($r, $cm['unit']) : '', 'qty' => isset($cm['qty']) && $isNum($val($r, $cm['qty'])) ? $val($r, $cm['qty']) : null, 'rate' => isset($cm['rate']) && $isNum($val($r, $cm['rate'])) ? $val($r, $cm['rate']) : null,
                'contract' => $isNum($cv) ? $cv : 0.0, 'contract_formula' => is_string($cf) ? $cf : null, 'paid' => $isNum($pv) ? $pv : 0.0, 'paid_parts' => $this->parts($paidF, $pv), 'balance' => $isNum($val($r, $cm['balance'] ?? 0)) ? $val($r, $cm['balance']) : null,
                'proposal' => isset($cm['proposal']) && $isNum($val($r, $cm['proposal'])) ? $val($r, $cm['proposal']) : null, 'remarks' => isset($cm['remarks']) ? $str($r, $cm['remarks']) : '', 'no_amount' => ! $hasNum];
            $item['unit'] = SubcontractSettings::normalizeUnit($item['unit_raw']);
            if ($item['qty'] !== null && $item['rate'] !== null) {
                $item['qty_rate_ok'] = abs($item['qty'] * $item['rate'] - $item['contract']) <= 1.0;
            }
            if (preg_match('/US\s*\$/i', $item['remarks'])) {
                $cur['currency'] = $cur['currency'] ?: 'USD';
                $usd = true;
            }
            $cur['items'][] = $item;
            $lastItem = $item;
        }
        if ($cur['items'] || $cur['name'] !== null || $cur['notes']) {
            $groups[] = $cur;
        }

        // the TOTAL row of the SUMMARY block, else the last subtotal
        $reported = ['contract' => null, 'paid' => null, 'balance' => null];
        if ($summaryRow) {
            $stop = $payRow && $payRow > $summaryRow ? $payRow : $maxRow + 1;
            for ($r = $summaryRow + 1; $r < $stop; $r++) {
                foreach ($cells[$r] ?? [] as $c => $cell) {
                    if (is_string($cell['v']) && strtoupper(trim($cell['v'])) === 'TOTAL') {
                        $reported = ['contract' => $val($r, $cm['contract']), 'paid' => $val($r, $cm['paid']), 'balance' => $isNum($val($r, $cm['balance'] ?? 0)) ? $val($r, $cm['balance']) : null, 'row' => $r];
                    }
                }
            }
        }
        if ($reported['contract'] === null && $totals) {
            $reported = ['contract' => $totals['contract'], 'paid' => $totals['paid'], 'balance' => null, 'row' => $totals['row']];
        } elseif ($reported['contract'] === null && $sub) {
            $l = end($sub);
            $reported = ['contract' => $l['contract'], 'paid' => $l['paid'], 'balance' => null, 'row' => $l['row']];
        }

        // dated payment list
        $pays = [];
        $textLines = [];
        if ($payRow) {
            for ($r = $payRow + 1; $r <= $maxRow; $r++) {
                if (! isset($cells[$r])) {
                    continue;
                }
                $dc = $cells[$r][$payCol] ?? null;
                $ac = $cells[$r][$payCol + 1] ?? null;
                if (! $dc && ! $ac) {
                    continue;
                }
                $date = null;
                $rawDate = '';
                if ($dc) {
                    $rawDate = is_string($dc['v']) ? trim($dc['v']) : (is_float($dc['v']) ? ($dc['date'] ? (XlsxReader::serialToDate($dc['v']) ?: (string) $dc['v']) : (string) $dc['v']) : '');
                    $date = $dc['date'] && is_float($dc['v']) ? XlsxReader::serialToDate($dc['v']) : $this->parseDate($rawDate);
                }
                [$amt, $isUsd, $note] = $this->amount($cells[$r], $payCol + 1);
                if ($date === null && $amt === null) {
                    $textLines[] = ['row' => $r, 'text' => $rawDate . ($ac && is_string($ac['v']) ? ' ' . $ac['v'] : '')];
                    continue;
                }
                $pays[] = ['row' => $r, 'date' => $date, 'raw_date' => $rawDate, 'amount' => $amt, 'usd' => $isUsd, 'note' => $note];
            }
        }

        // lines of the SUMMARY block that have no detail section above them (a "LOAN" line typed in the summary): they are part of the sheet total, so they are kept as
        // a line of their own instead of being lost. A summary line that points at a section subtotal (e.g. =G25) is not one of these.
        if ($summaryRow) {
            $loose = [];
            $stopRow = $reported['row'] ?? ($payRow && $payRow > $summaryRow ? $payRow : $maxRow + 1);
            for ($r = $summaryRow + 1; $r < $stopRow; $r++) {
                $label = $str($r, $cm['desc']);
                $cv = $val($r, $cm['contract']);
                $pv = $val($r, $cm['paid']);
                if ($label === '' || ! $isNum($cv) || (float) $cv <= 0 || str_starts_with(strtoupper($label), 'TOTAL')) {
                    continue;
                }
                $f = $cells[$r][$cm['contract']]['f'] ?? null;
                $isPointer = is_string($f) && preg_match('/^(\(shared\)\s*)?\$?[A-Z]{1,2}\$?(\d+)$/', trim($f), $pm) && (int) $pm[2] !== $r;
                if ($isPointer) {
                    continue;
                }
                $paidF = $cells[$r][$cm['paid']]['f'] ?? null;
                $loose[] = ['row' => $r, 'desc' => $label, 'benef' => $str($r, $cm['benef']), 'unit_raw' => '', 'unit' => SubcontractSettings::normalizeUnit(''), 'qty' => null, 'rate' => null, 'contract' => (float) $cv, 'contract_formula' => is_string($f) ? $f : null,
                    'paid' => $isNum($pv) ? (float) $pv : 0.0, 'paid_parts' => $this->parts($paidF, $pv), 'balance' => null, 'proposal' => null, 'remarks' => 'A line of the SUMMARY block of the sheet with no detail section above it; it is part of the sheet total.', 'no_amount' => false];
            }
            if ($loose) {
                $groups[] = ['name' => 'Lines of the sheet summary (no detail section)', 'row' => $summaryRow, 'notes' => [], 'items' => $loose, 'currency' => null, 'rate' => null];
                $flags[] = ['level' => 'info', 'text' => count($loose) . ' line(s) exist only in the SUMMARY block of the sheet (' . implode(', ', array_map(fn ($l) => $l['desc'] . ' ' . number_format($l['contract'], 2), $loose)) . '): they are part of the sheet total and are imported as their own group.'];
            }
        }

        // each section subtotal against the rows its own formula adds up: finds ranges that stop one row short, stale values and typed totals
        $colIdx = function (string $letters): int {
            $n = 0;
            foreach (str_split(strtoupper($letters)) as $ch) {
                $n = $n * 26 + (ord($ch) - 64);
            }

            return $n;
        };
        $allItems = [];
        foreach ($groups as $g) {
            foreach ($g['items'] as $it) {
                $allItems[] = $it;
            }
        }
        foreach ($groups as $g) {
            $st = $g['subtotal'] ?? null;
            if (! $st || ! is_string($st['formula'] ?? null) || ! preg_match('/SUM\(\s*\$?([A-Z]+)\$?(\d+)\s*:\s*\$?([A-Z]+)\$?(\d+)\s*\)/i', $st['formula'], $mm) || $colIdx($mm[1]) !== $cm['contract']) {
                continue;
            }
            [$from, $to] = [(int) $mm[2], (int) $mm[4]];
            $inRange = 0.0;
            $omitted = 0.0;
            $omRows = [];
            $hit = false;
            foreach ($allItems as $it) {
                if ($it['row'] >= $from && $it['row'] <= $to) {
                    $inRange += $it['contract'];
                    $hit = true;
                } elseif ($it['row'] > $to && $it['row'] < $st['row'] && $it['contract'] != 0.0) {
                    $omitted += $it['contract'];
                    $omRows[] = $it['row'];
                }
            }
            if (! $hit) {
                continue; // a total of summary lines, not of items
            }
            $name = $g['name'] ?? 'section';
            if ($omRows) {
                $flags[] = ['level' => 'warn', 'text' => sprintf('Section "%s": the subtotal on row %d adds up rows %d-%d, but there are amounts on row(s) %s (%s) between that range and the subtotal that it leaves out.', $name, $st['row'], $from, $to, implode(', ', array_slice($omRows, 0, 5)), number_format($omitted, 2))];
            }
            if ($st['contract'] !== null && abs($inRange - $st['contract']) > 1) {
                $flags[] = ['level' => 'warn', 'text' => sprintf('Section "%s": the subtotal on row %d shows %s but the rows it adds up (%d-%d) are %s.', $name, $st['row'], number_format($st['contract'], 2), $from, $to, number_format($inRange, 2))];
            }
        }
        if ($spacers) {
            $flags[] = ['level' => 'info', 'text' => $spacers . ' empty row(s) with no description and no amount were skipped.'];
        }

        // flags
        $itemsAll = array_merge(...array_map(fn ($g) => $g['items'], $groups ?: [['items' => []]]));
        $sumC = array_sum(array_column($itemsAll, 'contract'));
        $sumP = array_sum(array_column($itemsAll, 'paid')) + array_sum(array_column($payOnly, 'amount'));
        $datedSum = array_sum(array_map(fn ($p) => (float) ($p['amount'] ?? 0), $pays));
        if ($reported['contract'] !== null && abs($sumC - $reported['contract']) > 1) {
            $flags[] = ['level' => 'warn', 'text' => sprintf('Items add up to %s but the sheet total is %s (difference %s).', number_format($sumC, 2), number_format($reported['contract'], 2), number_format($sumC - $reported['contract'], 2))];
        }
        if ($reported['paid'] !== null && abs($sumP - $reported['paid']) > 1) {
            $flags[] = ['level' => 'warn', 'text' => sprintf('Paid on the items adds up to %s but the sheet paid total is %s.', number_format($sumP, 2), number_format($reported['paid'], 2))];
        }
        if (! $pays) {
            $flags[] = ['level' => 'info', 'text' => 'No usable dated payment list: payments will have no dates.'];
        } elseif (abs($datedSum - $sumP) > 1) {
            $flags[] = ['level' => 'info', 'text' => sprintf('The dated payment list adds up to %s, the paid figure on the items is %s (difference %s). The items figure is imported; the list is kept as evidence of dates.', number_format($datedSum, 2), number_format($sumP, 2), number_format($datedSum - $sumP, 2))];
        }
        $over = count(array_filter($itemsAll, fn ($i) => $i['paid'] > $i['contract'] + 0.5));
        if ($over) {
            $flags[] = ['level' => 'warn', 'text' => $over . ' item(s) are paid above their contract amount.'];
        }
        $mism = count(array_filter($itemsAll, fn ($i) => isset($i['qty_rate_ok']) && ! $i['qty_rate_ok']));
        if ($mism) {
            $flags[] = ['level' => 'info', 'text' => $mism . ' item(s): the contract amount is not quantity x rate (kept as a fixed amount).'];
        }
        $noAmt = count(array_filter($itemsAll, fn ($i) => $i['no_amount']));
        if ($noAmt) {
            $flags[] = ['level' => 'info', 'text' => $noAmt . ' item(s) have no amounts on the sheet (kept with zero).'];
        }
        if ($payOnly) {
            $flags[] = ['level' => 'info', 'text' => count($payOnly) . ' paid amount(s) have no description (kept as payments on the previous item).'];
        }
        if ($textLines) {
            $flags[] = ['level' => 'info', 'text' => count($textLines) . ' line(s) in the payment list are text, not payments (kept in the staging copy).'];
        }
        $badDate = count(array_filter($pays, fn ($p) => $p['date'] === null));
        if ($badDate) {
            $flags[] = ['level' => 'warn', 'text' => $badDate . ' payment(s) have a date that could not be read (imported without a date).'];
        }
        $names = array_count_values(array_filter(array_column($itemsAll, 'benef')));
        if (count($names) > 1) {
            $flags[] = ['level' => 'info', 'text' => 'The beneficiary differs between items (' . implode(', ', array_slice(array_keys($names), 0, 6)) . '): the account name is not one person. The beneficiary is kept on each item.'];
        }
        $currency = 'TZS';
        $rate = null;
        if ($usd && ! $mixed) {
            $currency = 'USD';
            $flags[] = ['level' => 'warn', 'text' => 'The sheet is in US dollars. The agreement is created in USD; paying it later needs a rate at the time of payment.'];
        } elseif ($mixed) {
            foreach ($groups as &$g) {
                if ($g['currency'] === 'USD') {
                    $g['rate'] = $rateHint;
                }
            }
            unset($g);
            $rate = $rateHint;
            $flags[] = ['level' => 'warn', 'text' => 'Mixed currencies: a block is in US dollars and the rest in TZS. The dollar block is converted to TZS at ' . number_format((float) $rateHint, 2) . ' (the rate typed on the sheet); confirm it in the review.'];
        }

        return ['layout' => $layout, 'cols' => $cm, 'header_row' => $hr, 'title' => $title, 'suggested_name' => $suggested, 'as_at' => $asAt, 'currency' => $currency, 'rate' => $rate, 'groups' => $groups, 'pay_only' => $payOnly, 'pays' => $pays, 'text_lines' => $textLines,
            'reported' => $reported, 'sums' => ['contract' => round($sumC, 2), 'paid' => round($sumP, 2), 'dated' => round($datedSum, 2)], 'flags' => $flags, 'has_summary' => (bool) $summaryRow, 'item_count' => count($itemsAll)];
    }

    /** "=1500000+925000+1175000" -> [1500000, 925000, 1175000]; anything else -> []. */
    private function parts(?string $f, $v): array
    {
        if (! $f || ! preg_match('/^\s*=?\s*[\d.]+(\s*\+\s*[\d.]+)+\s*$/', $f)) {
            return [];
        }

        return array_map('floatval', preg_split('/\s*\+\s*/', trim(ltrim(trim($f), '='))));
    }

    /** @return array{0:?float,1:bool,2:?string} amount, is dollars, where it was taken from */
    private function amount(array $row, int $col): array
    {
        $c = $row[$col] ?? null;
        if ($c) {
            if (is_float($c['v'])) {
                return [$c['v'], false, null];
            }
            if (is_string($c['v']) && preg_match('/\d/', $c['v'])) {
                $usd = (bool) preg_match('/\$|US/i', $c['v']);
                $n = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $c['v']));

                return [is_numeric($n) ? (float) $n : null, $usd, null];
            }
        }
        // no amount next to the date (some sheets spread a week over several columns and total it on the right): take the right-most number
        $best = null;
        foreach ($row as $cn => $cell) {
            if ($cn > $col && is_float($cell['v'])) {
                $best = [$cell['v'], false, 'taken from column ' . $cn];
            }
        }

        return $best ?: [null, false, null];
    }

    private function parseDate(string $raw): ?string
    {
        $t = trim($raw);
        if ($t === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})$/', $t, $m)) {
            $y = (int) $m[3];
            $y = $y < 100 ? 2000 + $y : $y;

            return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }
        $clean = preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $t);
        $ts = strtotime($clean);

        return $ts && preg_match('/\d{4}/', $clean) ? date('Y-m-d', $ts) : null;
    }

    /** "FUNDI KIONGOZI- 05.02.2026" => ["FUNDI KIONGOZI", "2026-02-05"] */
    private function nameAndDate(string $title, string $sheet): array
    {
        $date = null;
        $name = $title;
        if (preg_match('/^(.*?)[\s\-–]*(\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4})\b(.*)$/u', $title, $m)) {
            $name = trim($m[1], " \t-–/");
            $date = $this->parseDate($m[2]);
            $tail = trim($m[3], " \t-–/");
            if ($tail !== '') {
                $name = trim($name . ' ' . $tail);
            }
        }
        $name = preg_replace('/\s+/', ' ', $name);

        return [$name !== '' ? $name : $sheet, $date];
    }
}
