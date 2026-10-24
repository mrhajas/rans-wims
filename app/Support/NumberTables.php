<?php

namespace App\Support;

/**
 * Adds a "No" (row number) column to every data table of a printed / PDF page that has none, so every report and every table carries one.
 *
 * It works on the finished HTML as text (it never re-parses and re-writes the page, so styles, SVG and everything else stay exactly as they were):
 *  - a DATA table is one whose header row is made only of <th> cells (two or more) and that has at least one row made only of <td> cells. Layout tables (letterhead, summary
 *    boxes, signature boxes, key / value lists) have no such header and are left alone;
 *  - a table that already starts with a number column (No, #, S/N, ...), or that carries data-no-number, is left alone;
 *  - the data rows (every cell a single column wide, as many cells as the header) get 1, 2, 3 ...; any other row (a total, a group title, "nothing to show") gets an empty
 *    cell so the columns stay lined up, and the header row gets "No".
 */
class NumberTables
{
    private const NUMBER_HEADS = '/^\s*(no\.?|#|s\/n|sn|sr\.?|sl\.?|number|nº|n°)\s*$/i';

    private const SKIP_CLASS = '/\b(stats?|parties|party|sign|sg|signature|footer|foot|letterhead|kv|meta|amount-card|qr|boxes?|no-number|barcode)\b/i';

    public static function inject(string $html): string
    {
        if (stripos($html, '<table') === false || stripos($html, '<th') === false) {
            return $html;
        }
        if (! preg_match_all('/<(\/?)(table|colgroup|thead|tbody|tfoot|tr|th|td)\b([^>]*)>/i', $html, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return $html;
        }

        $stack = [];
        $edits = []; // [position, text]
        foreach ($tags as $t) {
            [$whole, $pos] = $t[0];
            $closing = $t[1][0] === '/';
            $name = strtolower($t[2][0]);
            $attrs = $t[3][0];
            $end = $pos + strlen($whole);

            if ($name === 'table') {
                if (! $closing) {
                    $stack[] = ['attrs' => $attrs, 'colgroup' => null, 'rows' => [], 'row' => null, 'section' => 'body'];
                } elseif ($stack) {
                    $ctx = array_pop($stack);
                    if ($ctx['row']) {
                        $ctx['rows'][] = $ctx['row'];
                    }
                    foreach (self::plan($ctx, $html) as $e) {
                        $edits[] = $e;
                    }
                }
                continue;
            }
            if (! $stack) {
                continue;
            }
            $i = count($stack) - 1;
            $ctx = &$stack[$i];
            if ($name === 'colgroup' && ! $closing && $ctx['colgroup'] === null && ! $ctx['rows']) {
                $ctx['colgroup'] = $end;
            } elseif ($name === 'thead' || $name === 'tbody' || $name === 'tfoot') {
                $ctx['section'] = $closing ? 'body' : $name;
            } elseif ($name === 'tr') {
                if ($ctx['row']) {
                    $ctx['rows'][] = $ctx['row'];
                    $ctx['row'] = null;
                }
                if (! $closing) {
                    $ctx['row'] = ['open_end' => $end, 'section' => $ctx['section'], 'cells' => [], 'attrs' => $attrs];
                }
            } elseif (($name === 'th' || $name === 'td') && ! $closing && $ctx['row']) {
                $colspan = preg_match('/colspan\s*=\s*["\']?(\d+)/i', $attrs, $m) ? max(1, (int) $m[1]) : 1;
                $ctx['row']['cells'][] = ['type' => $name, 'colspan' => $colspan, 'pos' => $pos, 'end' => $end];
            }
            unset($ctx);
        }
        if (! $edits) {
            return $html;
        }

        usort($edits, fn ($a, $b) => $b[0] <=> $a[0]);
        foreach ($edits as [$pos, $text]) {
            $html = substr($html, 0, $pos) . $text . substr($html, $pos);
        }

        return $html;
    }

    /** What to insert into one finished table (nothing when it is not a data table or already has a number column). */
    private static function plan(array $ctx, string $html): array
    {
        $attrs = $ctx['attrs'];
        if (preg_match('/data-no-number|data-autono/i', $attrs) || preg_match(self::SKIP_CLASS, self::classOf($attrs))) {
            return [];
        }
        $rows = $ctx['rows'];
        // the header row: the first row, made only of th cells (two or more)
        $headIndex = null;
        foreach ($rows as $n => $r) {
            if (! $r['cells']) {
                continue;
            }
            $onlyTh = ! array_filter($r['cells'], fn ($c) => $c['type'] !== 'th');
            if ($onlyTh && count($r['cells']) >= 2) {
                $headIndex = $n;
            }
            break;
        }
        if ($headIndex === null) {
            return [];
        }
        $head = $rows[$headIndex];
        $cols = array_sum(array_column($head['cells'], 'colspan'));
        $hasDataRow = false;
        foreach (array_slice($rows, $headIndex + 1) as $r) {
            if ($r['cells'] && ! array_filter($r['cells'], fn ($c) => $c['type'] !== 'td')) {
                $hasDataRow = true;
                break;
            }
        }
        if (! $hasDataRow) {
            return [];
        }
        // already numbered? the first header cell reads No / # / S/N, has the idx class, or an earlier pass added ours
        $first = $head['cells'][0];
        $firstTag = substr($html, $first['pos'], $first['end'] - $first['pos']);
        $firstText = self::textAfter($html, $first['end']);
        if (preg_match(self::NUMBER_HEADS, $firstText) || preg_match('/class\s*=\s*["\'][^"\']*\b(idx|autono|txn-no-th)\b/i', $firstTag)) {
            return [];
        }

        $edits = [];
        $headRows = count(array_filter($rows, fn ($r) => $r['section'] === 'thead'));
        if ($ctx['colgroup'] !== null) {
            $edits[] = [$ctx['colgroup'], '<col style="width:28px" />'];
        }
        $n = 0;
        foreach ($rows as $idx => $r) {
            if ($idx < $headIndex) {
                continue;
            }
            if ($idx === $headIndex) {
                $rowspan = $head['section'] === 'thead' && $headRows > 1 ? ' rowspan="' . $headRows . '"' : '';
                $edits[] = [$r['open_end'], '<th class="autono" data-autono="1"' . $rowspan . ' style="width:28px;text-align:center;">No</th>'];
                continue;
            }
            if ($head['section'] === 'thead' && $r['section'] === 'thead') {
                continue; // covered by the rowspan of the first header row
            }
            if (! $r['cells']) {
                continue;
            }
            $isData = $r['section'] !== 'tfoot' && count($r['cells']) >= $cols - 1 && count($r['cells']) <= $cols + 1 && ! array_filter($r['cells'], fn ($c) => $c['type'] !== 'td' || $c['colspan'] !== 1)
                && ! preg_match('/\b(total|subtotal|group|cat-row|section|summary|foot|grand)\b/i', self::classOf($r['attrs']));
            $edits[] = [$r['open_end'], $isData ? '<td class="autono" style="width:28px;text-align:center;">' . ++$n . '</td>' : '<td class="autono" style="width:28px;"></td>'];
        }

        return $edits;
    }

    private static function classOf(string $attrs): string
    {
        return preg_match('/class\s*=\s*["\']([^"\']*)["\']/i', $attrs, $m) ? $m[1] : '';
    }

    /** The visible text that follows an opening tag, up to the next tag. */
    private static function textAfter(string $html, int $from): string
    {
        $stop = strpos($html, '<', $from);
        $text = substr($html, $from, ($stop === false ? strlen($html) : $stop) - $from);
        if (trim($text) === '') {
            // the cell text may sit inside an inner tag (a span, an icon, a link): take the text up to the closing cell
            $close = stripos($html, '</t', $from);
            $text = strip_tags(substr($html, $from, ($close === false ? 200 : min($close - $from, 400))));
        }

        return html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5);
    }
}
