<?php

namespace App\Support;

use App\Models\Adjustment;
use App\Models\Checkin;
use App\Models\Checkout;
use App\Models\DeliveryNote;
use App\Models\GatePass;
use App\Models\Transfer;
use Illuminate\Support\Carbon;

/**
 * Builds the neutral "document" array rendered by resources/views/print/document.blade.php,
 * so Check-in / Check-out / Transfer / Adjustment prints and the Delivery Note all share
 * one polished layout and only differ in the data they feed it.
 */
class PrintDocuments
{
    public static function qty($value): string
    {
        $text = number_format((float) $value, 2, '.', ',');

        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }

    private static function date($value): string
    {
        return $value ? Carbon::parse($value)->format('d M Y') : '-';
    }

    private static function valuedLines($items, bool $useLinePrice = false): array
    {
        $lines = [];
        $total = 0.0;
        $totalQty = 0.0;

        foreach ($items as $line) {
            $unitValue = (float) (($useLinePrice ? ($line->price ?: optional($line->item)->price) : optional($line->item)->price) ?: 0);
            $lineTotal = (float) $line->quantity * $unitValue;
            $total += $lineTotal;
            $totalQty += (float) $line->quantity;

            $lines[] = [
                'name' => optional($line->item)->name ?? '-',
                'code' => optional($line->item)->code,
                'unit' => optional($line->unit)->name ?? optional($line->unit)->code ?? '-',
                'qty' => self::qty($line->quantity),
                'unit_value' => number_format($unitValue, 2),
                'total' => number_format($lineTotal, 2),
            ];
        }

        return [$lines, $total, $totalQty];
    }

    private static function base(array $doc): array
    {
        return $doc + ['parent' => null, 'notes' => null, 'show_values' => true, 'draft' => false, 'stamp_boxes' => false, 'extra_cols' => false, 'tick_col' => false, 'change_cols' => false, 'table' => null, 'landscape' => false, 'extra_parties' => [], 'gate_security' => false, 'site_receipt' => false, 'copies' => [], 'compact' => false, 'typed_signers' => []];
    }

    public static function checkin(Checkin $checkin): array
    {
        [$lines, $total, $totalQty] = self::valuedLines($checkin->items, true);

        return self::base([
            'accent' => '#2f9be6', 'accent_dark' => '#0b6fb8', 'accent_soft' => '#eef7ff', 'accent_line' => '#bfe0fb',
            'icon' => 'download', 'title' => 'CHECK-IN RECEIPT', 'subtitle' => 'Goods received into the warehouse',
            'reference' => $checkin->reference,
            'status' => ['label' => $checkin->draft ? 'Draft' : 'Completed', 'class' => $checkin->draft ? 'status-warn' : 'status-info'],
            'draft' => (bool) $checkin->draft,
            'stats' => [
                ['icon' => 'calendar', 'label' => 'Date', 'value' => self::date($checkin->date)],
                ['icon' => 'package', 'label' => 'Line Items', 'value' => count($lines)],
                ['icon' => 'hash', 'label' => 'Total Quantity', 'value' => self::qty($totalQty)],
                ['icon' => 'cash', 'label' => 'Total Value', 'value' => number_format($total, 2)],
            ],
            'parties' => [
                ['title' => 'Supplier / Contact', 'icon' => 'user', 'rows' => [
                    ['Name', optional($checkin->contact)->name ?? '-'],
                    ['Phone', optional($checkin->contact)->phone ?? '-'],
                    ['Email', optional($checkin->contact)->email ?? '-'],
                ]],
                ['title' => 'Delivered To', 'icon' => 'pin', 'rows' => [
                    ['Warehouse', optional($checkin->warehouse)->name ?? '-'],
                    ['Responsible', optional($checkin->user)->name ?? '-'],
                ]],
            ],
            'lines' => $lines, 'total' => number_format($total, 2), 'total_qty' => self::qty($totalQty),
            'notes' => $checkin->details,
            'signatures' => [
                ['role' => 'Received By', 'name' => optional($checkin->user)->name, 'date' => self::date($checkin->date)],
                ['role' => 'Delivered By', 'name' => optional($checkin->contact)->name, 'date' => null],
            ],
            // Signatories module: when the new block is on, "Received By" comes from it and the supplier (a contact, not a WIMS user) stays a typed line.
            'typed_signers' => [['role' => 'Delivered By', 'name' => optional($checkin->contact)->name, 'date' => null]],
            'footer' => 'Check-in ' . $checkin->reference,
        ]);
    }

    public static function checkout(Checkout $checkout): array
    {
        [$lines, $total, $totalQty] = self::valuedLines($checkout->items, true);

        return self::base([
            'accent' => '#ea580c', 'accent_dark' => '#c2410c', 'accent_soft' => '#fff7ed', 'accent_line' => '#fed7aa',
            'icon' => 'upload', 'title' => 'CHECK-OUT RECEIPT', 'subtitle' => 'Materials issued from the warehouse',
            'reference' => $checkout->reference,
            'status' => ['label' => $checkout->draft ? 'Draft' : 'Completed', 'class' => $checkout->draft ? 'status-warn' : 'status-active'],
            'draft' => (bool) $checkout->draft,
            'stats' => [
                ['icon' => 'calendar', 'label' => 'Date', 'value' => self::date($checkout->date)],
                ['icon' => 'package', 'label' => 'Line Items', 'value' => count($lines)],
                ['icon' => 'hash', 'label' => 'Total Quantity', 'value' => self::qty($totalQty)],
                ['icon' => 'cash', 'label' => 'Total Value', 'value' => number_format($total, 2)],
            ],
            'parties' => [
                ['title' => 'Issued From', 'icon' => 'pin', 'rows' => [
                    ['Warehouse', optional($checkout->warehouse)->name ?? '-'],
                    ['Responsible', optional($checkout->user)->name ?? '-'],
                    ['Work Activity', optional($checkout->workActivity)->name ?? '-'],
                ]],
                ['title' => 'Issued To', 'icon' => 'user', 'rows' => [
                    ['Name', optional($checkout->contact)->name ?? 'Consumed on site'],
                    ['Phone', optional($checkout->contact)->phone ?? '-'],
                ]],
            ],
            'lines' => $lines, 'total' => number_format($total, 2), 'total_qty' => self::qty($totalQty),
            'notes' => $checkout->details,
            'signatures' => [
                ['role' => 'Issued By', 'name' => optional($checkout->user)->name, 'date' => self::date($checkout->date)],
                ['role' => 'Received By', 'name' => optional($checkout->contact)->name, 'date' => null],
            ],
            // Signatories module: when the new block is on, "Issued By" comes from it and the receiving contact stays a typed line.
            'typed_signers' => [['role' => 'Received By', 'name' => optional($checkout->contact)->name, 'date' => null]],
            'footer' => 'Check-out ' . $checkout->reference,
        ]);
    }

    public static function transfer(Transfer $transfer): array
    {
        [$lines, $total, $totalQty] = self::valuedLines($transfer->items);

        $status = $transfer->draft ? 'Draft' : ($transfer->received_at ? 'Received' : 'Approved');
        $statusClass = $transfer->draft ? 'status-warn' : ($transfer->received_at ? 'status-active' : 'status-neutral');

        return self::base([
            'accent' => '#2563eb', 'accent_dark' => '#1d4ed8', 'accent_soft' => '#eff6ff', 'accent_line' => '#bfdbfe',
            'icon' => 'transfer', 'title' => 'TRANSFER NOTE', 'subtitle' => 'Stock moved between warehouses',
            'reference' => $transfer->reference,
            'status' => ['label' => $status, 'class' => $statusClass],
            'draft' => (bool) $transfer->draft,
            'stats' => [
                ['icon' => 'calendar', 'label' => 'Date', 'value' => self::date($transfer->date)],
                ['icon' => 'package', 'label' => 'Line Items', 'value' => count($lines)],
                ['icon' => 'hash', 'label' => 'Total Quantity', 'value' => self::qty($totalQty)],
                ['icon' => 'cash', 'label' => 'Total Value', 'value' => number_format($total, 2)],
            ],
            'parties' => [
                ['title' => 'Transferred From', 'icon' => 'pin', 'rows' => [
                    ['Warehouse', optional($transfer->fromWarehouse)->name ?? '-'],
                    ['Dispatched By', optional($transfer->user)->name ?? '-'],
                ]],
                ['title' => 'Transferred To', 'icon' => 'pin', 'rows' => [
                    ['Warehouse', optional($transfer->toWarehouse)->name ?? '-'],
                    ['Received By', optional($transfer->receivedBy)->name ?? 'Pending'],
                    ['Received On', $transfer->received_at ? Carbon::parse($transfer->received_at)->format('d M Y H:i') : '-'],
                ]],
            ],
            'lines' => $lines, 'total' => number_format($total, 2), 'total_qty' => self::qty($totalQty),
            'notes' => $transfer->details,
            'signatures' => [
                ['role' => 'Dispatched By', 'name' => optional($transfer->user)->name, 'date' => self::date($transfer->date)],
                ['role' => 'Received By', 'name' => optional($transfer->receivedBy)->name ?? 'Pending', 'date' => $transfer->received_at ? Carbon::parse($transfer->received_at)->format('d M Y H:i') : null],
            ],
            'footer' => 'Transfer ' . $transfer->reference,
        ]);
    }

    /** The value report of a Super Adjustment or an Adjustment (App\Services\ValueReport): a landscape sheet with the totals up top and one table. */
    public static function valueReport(array $r, ?\App\Models\User $by = null, ?string $submittedBy = null, ?string $approvedBy = null): array
    {
        $t = $r['totals'];
        $m = fn ($v) => number_format((float) $v, 2);
        $q = fn ($v) => self::qty($v);
        $s = fn ($v, $f) => ($v > 0 ? '+' : ($v < 0 ? '-' : '')) . $f(abs($v));
        $L = $r['labels'];
        $rows = [];
        foreach ($r['rows'] as $row) {
            $rows[] = [
                ['v' => $row['name'] . (! empty($row['code']) ? ' (' . $row['code'] . ')' : '') . (! empty($row['cleared']) ? ' - cleared' : '') . (($r['sort'] ?? null) === 'category' && ! empty($row['category']) ? ' [' . $row['category'] . ']' : ''), 'a' => 'l'], ['v' => $row['unit'] ?: '-', 'a' => 'l'], ['v' => $m($row['price'])],
                ['v' => $q($row['before'])], ['v' => $m($row['before_total'])], ['v' => $q($row['after'])], ['v' => $m($row['after_total'])],
                ['v' => $s($row['diff'], $q), 'tone' => $row['diff'] <=> 0], ['v' => $s($row['diff_value'], $m), 'tone' => $row['diff_value'] <=> 0, 'b' => true],
            ];
        }
        $super = $r['kind'] === 'super_adjustment';

        return self::base([
            'accent' => '#4f46e5', 'accent_dark' => '#4338ca', 'accent_soft' => '#eef2ff', 'accent_line' => '#c7d2fe',
            'icon' => 'sliders', 'title' => 'VALUE REPORT', 'subtitle' => $super ? 'System quantity against the physical count, in quantity and in value' : 'Stock before and after the adjustment, in quantity and in value',
            'reference' => $r['reference'],
            'status' => match ($r['status']) {
                'Approved' => ['label' => 'Approved', 'class' => 'status-active'],
                'Rejected' => ['label' => 'Rejected', 'class' => 'status-danger'],
                default => ['label' => $r['status'] === 'Awaiting Phase 2' ? 'Awaiting 2nd approval' : 'Pending Approval', 'class' => 'status-warn'],
            },
            'show_values' => false, 'landscape' => true, 'compact' => true,
            'stats' => [
                ['icon' => 'package', 'label' => 'Items', 'value' => $t['items']],
                ['icon' => 'cash', 'label' => $L['before_total'], 'value' => $m($t['before'])],
                ['icon' => 'cash', 'label' => $L['after_total'], 'value' => $m($t['after'])],
                ['icon' => 'sliders', 'label' => 'Difference in Value', 'value' => $s($t['diff'], $m)],
                ['icon' => 'hash', 'label' => 'Above / Below', 'value' => '+' . $m($t['above']) . ' / -' . $m($t['below'])],
            ],
            'parties' => [
                ['title' => $super ? 'Super Adjustment' : 'Adjustment', 'icon' => 'sliders', 'rows' => array_values(array_filter([
                    ['Warehouse', $r['warehouse'] ?: '-'], ['Date', self::date($r['date'])], ['Status', $r['status']],
                ]))],
                ['title' => 'Authorisation', 'icon' => 'user', 'rows' => [['Prepared By', $submittedBy ?: '-'], ['Approved By', $approvedBy ?: '-'], ['Report By', optional($by)->name ?: '-']]],
            ],
            'table' => [
                'title' => 'Items and values', 'note' => (! empty($r['sort']) ? 'Sorted ' . ['az' => 'by item name A-Z', 'category' => 'by category', 'high' => 'by physical count, high to low', 'low' => 'by physical count, low to high'][$r['sort']] . '. ' : '') . $r['basis'] . ' ' . 'The difference of a line is its value after minus its value before; the totals are the sum of the lines.' . ($t['no_price'] ? ' ' . $t['no_price'] . ' item(s) have no unit price in the Items module, so their value is 0.' : ''),
                'cols' => [
                    ['label' => 'Item', 'a' => 'l'], ['label' => 'Unit', 'a' => 'l'], ['label' => 'Unit Price'],
                    ['label' => $L['before']], ['label' => $L['before_total']], ['label' => $L['after']], ['label' => $L['after_total']], ['label' => 'Difference'], ['label' => 'Difference in Value'],
                ],
                'rows' => $rows,
                'totals' => [['v' => 'Totals', 'span' => 3, 'a' => 'l'], ['v' => $q($t['before_qty'])], ['v' => $m($t['before'])], ['v' => $q($t['after_qty'])], ['v' => $m($t['after'])],
                    ['v' => $s($t['diff_qty'], $q), 'tone' => $t['diff_qty'] <=> 0], ['v' => $s($t['diff'], $m), 'tone' => $t['diff'] <=> 0, 'b' => true]],
            ],
            'lines' => [], 'total' => '', 'total_qty' => '', 'notes' => null,
            'signatures' => [['role' => 'Prepared By', 'name' => $submittedBy, 'date' => self::date($r['date'])], ['role' => 'Approved By', 'name' => $approvedBy, 'date' => $r['status'] === 'Approved' ? self::date($r['date']) : '']],
            'footer' => 'Value report ' . $r['reference'],
        ]);
    }

    /**
     * The activity archive as a WIMS document (landscape): one part of the archive, with the period and filters it was made for. $m: part, parts, first, last (row numbers of
     * this part), total, period, log, search; $rows: [date, log, description, by, record] per line.
     */
    public static function activityArchive(array $m, array $rows, ?string $by = null): array
    {
        $cells = [];
        foreach ($rows as $r) {
            $cells[] = [['v' => $r[0], 'a' => 'l', 'nw' => true], ['v' => $r[1] ?: '-', 'a' => 'l', 'nw' => true], ['v' => $r[2], 'a' => 'l'], ['v' => $r[3] ?: '-', 'a' => 'l', 'nw' => true], ['v' => $r[4] ?: '-', 'a' => 'l', 'nw' => true]];
        }

        return self::base([
            'accent' => '#0f766e', 'accent_dark' => '#115e59', 'accent_soft' => '#f0fdfa', 'accent_line' => '#99f6e4',
            'icon' => 'file', 'title' => 'ACTIVITY ARCHIVE', 'subtitle' => 'History moved out of the live activity log, kept for the record',
            'reference' => $m['parts'] > 1 ? 'Part ' . $m['part'] . ' of ' . $m['parts'] : 'Complete',
            'status' => ['label' => $m['parts'] > 1 ? 'Part ' . $m['part'] . '/' . $m['parts'] : 'Complete', 'class' => 'status-info'],
            'show_values' => false, 'landscape' => true, 'compact' => true,
            'stats' => [
                ['icon' => 'hash', 'label' => 'Records in this file', 'value' => number_format(count($rows))],
                ['icon' => 'package', 'label' => 'Records in all', 'value' => number_format($m['total'])],
                ['icon' => 'calendar', 'label' => 'Period', 'value' => $m['period']],
                ['icon' => 'note', 'label' => 'Log / words', 'value' => ($m['log'] ?: 'All logs') . ($m['search'] ? ' / "' . $m['search'] . '"' : '')],
            ],
            'parties' => [
                ['title' => 'Report', 'icon' => 'file', 'rows' => [['Records', number_format($m['first']) . ' to ' . number_format($m['last']) . ' of ' . number_format($m['total'])], ['Period', $m['period']], ['Generated', now()->format('d/m/Y H:i')]]],
                ['title' => 'Prepared by', 'icon' => 'user', 'rows' => [['Name', $by ?: '-']]],
            ],
            'table' => [
                'title' => 'Activity history', 'note' => 'Oldest first. Nothing in this list was deleted: it moved from the live activity log to the archive after the retention period set in Settings.', 'start' => max(0, $m['first'] - 1),
                'cols' => [['label' => 'Date', 'a' => 'l'], ['label' => 'Log', 'a' => 'l'], ['label' => 'What happened', 'a' => 'l'], ['label' => 'Done by', 'a' => 'l'], ['label' => 'Record', 'a' => 'l']],
                'rows' => $cells,
            ],
            'lines' => [], 'total' => '', 'total_qty' => '', 'notes' => null,
            'signatures' => [['role' => 'Prepared By', 'name' => $by, 'date' => now()->format('d/m/Y')]],
            'footer' => 'Activity archive' . ($m['parts'] > 1 ? ' part ' . $m['part'] . ' of ' . $m['parts'] : ''),
        ]);
    }

    /**
     * One optimisation (Settings > Optimize) as a WIMS document: the general status and why, who ran it, who prints or downloads it now and who did before, every task with what it did,
     * the sizes before and after, the database speed read by read, and the numbers that were used.
     */
    public static function optimizerRun(\App\Models\OptimizerRun $run, ?\App\Models\User $by = null, array $earlier = [], string $action = 'Printed'): array
    {
        $b = (array) $run->before;
        $a = (array) $run->after;
        $by_ = fn ($v) => \App\Support\Optimizer\Runner::bytes($v);
        $sb = (float) $run->speed_before_ms;
        $sa = (float) $run->speed_after_ms;
        $speed = $sb > 0 ? round((($sb - $sa) / $sb) * 100, 1) : 0;
        $ref = 'OPT-' . str_pad((string) $run->id, 5, '0', STR_PAD_LEFT);
        $rating = \App\Support\Optimizer\Runner::ratingLabel($run->rating);

        $rows = [];
        foreach ((array) $run->results as $r) {
            $notes = implode(' ', array_map('strval', (array) ($r['notes'] ?? [])));
            $rows[] = [['v' => $r['title'] ?? '-', 'a' => 'l'], ['v' => $r['summary'] ?? '-', 'a' => 'l'], ['v' => number_format((int) ($r['changed'] ?? 0)), 'a' => 'r', 'nw' => true],
                ['v' => $by_((int) ($r['bytes'] ?? 0)), 'a' => 'r', 'nw' => true], ['v' => ($r['ok'] ?? false) ? 'Done' : 'Error', 'a' => 'l', 'nw' => true], ['v' => $notes ?: '-', 'a' => 'l']];
        }
        $status = ['done' => ['Completed', 'status-info'], 'partial' => ['Partly done', 'status-warn'], 'failed' => ['Failed', 'status-warn'], 'running' => ['Running', 'status-warn']][$run->status] ?? ['Completed', 'status-info'];
        $why = array_values(array_map('strval', (array) $run->rating_why));

        $pair = fn ($label, $x, $y, $moreIsBetter = false) => [$label, $by_($x) . '  >  ' . $by_($y) . '  (' . (($moreIsBetter ? $y - $x : $x - $y) >= 0 ? '+' : '') . $by_($moreIsBetter ? $y - $x : $x - $y) . ')'];
        $sizes = [
            $pair('Database (with its free space)', (int) ($b['db_total_bytes'] ?? $b['db_bytes'] ?? 0), (int) ($a['db_total_bytes'] ?? $a['db_bytes'] ?? 0)),
            $pair('Uploaded pictures', (int) ($b['images_bytes'] ?? 0), (int) ($a['images_bytes'] ?? 0)),
            $pair('Server log files', (int) ($b['logs_bytes'] ?? 0), (int) ($a['logs_bytes'] ?? 0)),
            $pair('Kept originals of pictures', (int) ($b['originals_bytes'] ?? 0), (int) ($a['originals_bytes'] ?? 0)),
            $pair('Free disk space', (int) ($b['disk_free_bytes'] ?? 0), (int) ($a['disk_free_bytes'] ?? 0), true),
        ];
        $after = [];
        foreach ((array) (((array) $run->speed_after)['items'] ?? []) as $i) {
            $after[$i['name']] = $i['ms'];
        }
        $reads = [];
        foreach ((array) (((array) $run->speed_before)['items'] ?? []) as $i) {
            $y = $after[$i['name']] ?? $i['ms'];
            $reads[] = [$i['name'], number_format($i['ms'], 2) . ' ms  >  ' . number_format($y, 2) . ' ms'];
        }
        $reads[] = ['All together', number_format($sb, 2) . ' ms  >  ' . number_format($sa, 2) . ' ms  (' . ($sb > 0 ? ($speed >= 0 ? '+' : '') . $speed . '%' : '-') . ')'];

        $o = (array) $run->options;
        $print = [[$action, ($by->name ?? '-') . ($by && $by->roles->first() ? ' (' . $by->roles->first()->name . ')' : '') . ', ' . now()->format('d/m/Y H:i:s')]];
        foreach ($earlier as $e) {
            $print[] = [$e['action'], ($e['who'] ?? '-') . ($e['role'] ? ' (' . $e['role'] . ')' : '') . ', ' . \Illuminate\Support\Carbon::parse($e['at'])->format('d/m/Y H:i:s')];
        }

        return self::base([
            'accent' => '#4f46e5', 'accent_dark' => '#3730a3', 'accent_soft' => '#eef2ff', 'accent_line' => '#c7d2fe',
            'icon' => 'file', 'title' => 'DATA OPTIMISATION REPORT', 'subtitle' => 'What was optimised, who did it, what was gained and how well it went',
            'reference' => $ref,
            'status' => ['label' => strtoupper($rating) . ($run->rating ? '' : ' - ' . $status[0]), 'class' => in_array($run->rating, ['excellent', 'very_good'], true) ? 'status-info' : 'status-warn'],
            'show_values' => false, 'compact' => true,
            'stats' => [
                ['icon' => 'package', 'label' => 'Space gained', 'value' => $by_((int) $run->saved_bytes)],
                ['icon' => 'clock', 'label' => 'Database speed', 'value' => ($sb > 0 ? ($speed >= 0 ? '+' : '') . $speed . '%' : '-')],
                ['icon' => 'hash', 'label' => 'Files changed', 'value' => number_format((int) $run->files_changed)],
                ['icon' => 'hash', 'label' => 'Tables rebuilt', 'value' => number_format((int) $run->tables_changed)],
            ],
            'parties' => [
                ['title' => 'Overall status: ' . $rating, 'icon' => 'file', 'rows' => array_map(fn ($w, $i) => [$i === 0 ? 'Why' : '', $w], $why, array_keys($why)) ?: [['Why', '-']]],
                ['title' => 'Run', 'icon' => 'user', 'rows' => [['Done by', ($run->user_name ?: '-')], ['Started', optional($run->started_at)->format('d/m/Y H:i:s') ?: '-'], ['Finished', optional($run->finished_at)->format('d/m/Y H:i:s') ?: '-'], ['Took', (int) $run->seconds . ' s'], ['Run status', $status[0]]]],
            ],
            'extra_parties' => [
                ['title' => 'Sizes before and after (gain in brackets)', 'icon' => 'package', 'rows' => $sizes],
                ['title' => 'Database speed, read by read (middle of five tries)', 'icon' => 'clock', 'rows' => $reads],
                ['title' => 'Printed and downloaded by', 'icon' => 'user', 'rows' => $print],
            ],
            'table' => [
                'title' => 'What was done', 'note' => 'Every figure was measured by the system before and after the run. Database speed is the time of a fixed set of typical reads; small differences are normal noise.', 'start' => 0,
                'cols' => [['label' => 'Task', 'a' => 'l'], ['label' => 'Result', 'a' => 'l'], ['label' => 'Changed', 'a' => 'r'], ['label' => 'Space', 'a' => 'r'], ['label' => 'Outcome', 'a' => 'l'], ['label' => 'Notes', 'a' => 'l']],
                'rows' => $rows,
            ],
            'lines' => [], 'total' => '', 'total_qty' => '',
            'notes' => 'Numbers used: longest side ' . ($o['opt_image_max_px'] ?? '-') . ' px, quality ' . ($o['opt_image_quality'] ?? '-') . '%, pictures under ' . ($o['opt_image_min_kb'] ?? '-') . ' KB left alone, originals kept ' . ($o['opt_keep_originals_days'] ?? '-') . ' day(s), log files older than ' . ($o['opt_log_gzip_days'] ?? '-') . ' day(s) zipped, ' . ($o['opt_seconds'] ?? '-') . ' s per step. Backups are never touched. The kept originals of compressed pictures (' . $by_((int) ($a['originals_bytes'] ?? 0)) . ') are removed automatically after the days above, which then frees that space too.',
            'signatures' => [['role' => 'Run By', 'name' => $run->user_name, 'date' => optional($run->finished_at ?: $run->started_at)->format('d/m/Y')], ['role' => $action . ' By', 'name' => optional($by)->name, 'date' => now()->format('d/m/Y')]],
            'footer' => 'Data optimisation ' . $ref . ' - ' . $rating,
        ]);
    }

    /** One run of WIMS Logs as a WIMS document: what was archived log by log, the space it took before and after, the speed, who ran it, and who printed it. */
    public static function wimsLogRun(\App\Models\WimsLogRun $run, ?\App\Models\User $by = null, array $earlier = [], string $action = 'Printed'): array
    {
        $by_ = fn ($v) => \App\Support\Optimizer\Runner::bytes((int) $v);
        $sb = (float) $run->speed_before_ms;
        $sa = (float) $run->speed_after_ms;
        $speed = $sb > 0 ? round((($sb - $sa) / $sb) * 100, 1) : 0;
        $ref = $run->ref();
        $labels = collect(\App\Support\WimsLogs\Sources::all())->map(fn ($s) => $s['label'])->all();
        $status = ['done' => ['Completed', 'status-info'], 'partial' => ['Partly done', 'status-warn'], 'failed' => ['Failed', 'status-warn'], 'running' => ['Running', 'status-warn']][$run->status] ?? ['Completed', 'status-info'];
        $saved = max(0, (int) $run->original_bytes - (int) $run->stored_bytes);

        $rows = [];
        foreach ($run->archives()->orderBy('id')->get() as $a) {
            $pct = $a->original_bytes > 0 ? round((1 - $a->stored_bytes / $a->original_bytes) * 100) : 0;
            $period = ($a->date_from ? $a->date_from->format('d/m/Y') : '-') . ' - ' . ($a->date_to ? $a->date_to->format('d/m/Y') : '-');
            $rows[] = [['v' => $a->ref(), 'a' => 'l', 'nw' => true], ['v' => $labels[$a->source] ?? $a->source, 'a' => 'l'], ['v' => number_format($a->rows_n), 'a' => 'r', 'nw' => true],
                ['v' => $by_($a->original_bytes), 'a' => 'r', 'nw' => true], ['v' => $by_($a->stored_bytes), 'a' => 'r', 'nw' => true], ['v' => $pct . '%', 'a' => 'r', 'nw' => true],
                ['v' => $period, 'a' => 'l', 'nw' => true], ['v' => $a->verified ? 'Verified' : ($a->error ? 'Error' : 'Not verified'), 'a' => 'l', 'nw' => true]];
        }

        $b = (array) ($run->before['db'] ?? []);
        $af = (array) ($run->after['db'] ?? []);
        $bt = (int) ($b['bytes'] ?? 0) + (int) ($b['free'] ?? 0);
        $at = (int) ($af['bytes'] ?? 0) + (int) ($af['free'] ?? 0);
        $pair = fn ($label, $x, $y) => [$label, $by_($x) . '  >  ' . $by_($y) . '  (' . (($x - $y) >= 0 ? '-' : '+') . $by_(abs($x - $y)) . ')'];
        $sizes = [
            $pair('Whole database (with its free space)', $bt, $at),
            ['Archived records as they were in the database', $by_($run->original_bytes)],
            ['The same records in the archive files (data and index)', $by_($run->stored_bytes)],
            ['Space saved by compression', $by_($saved)],
            ['Database files really made smaller', $by_(max(0, (int) $run->db_freed_bytes))],
        ];
        $after = [];
        foreach ((array) (((array) $run->speed_after)['items'] ?? []) as $i) {
            $after[$i['name']] = $i['ms'];
        }
        $reads = [];
        foreach ((array) (((array) $run->speed_before)['items'] ?? []) as $i) {
            $y = $after[$i['name']] ?? $i['ms'];
            $reads[] = [$i['name'], number_format($i['ms'], 2) . ' ms  >  ' . number_format($y, 2) . ' ms'];
        }
        $reads[] = ['All together', number_format($sb, 2) . ' ms  >  ' . number_format($sa, 2) . ' ms  (' . ($sb > 0 ? ($speed >= 0 ? '+' : '') . $speed . '%' : '-') . ')'];

        $o = (array) ($run->options['keep_days'] ?? []);
        $keep = implode(', ', array_map(fn ($k, $d) => ($labels[$k] ?? $k) . ' ' . $d . ' d', array_keys($o), $o));
        $print = [[$action, ($by->name ?? '-') . ($by && $by->roles->first() ? ' (' . $by->roles->first()->name . ')' : '') . ', ' . now()->format('d/m/Y H:i:s')]];
        foreach ($earlier as $e) {
            $print[] = [$e['action'], ($e['who'] ?? '-') . ($e['role'] ? ' (' . $e['role'] . ')' : '') . ', ' . \Illuminate\Support\Carbon::parse($e['at'])->format('d/m/Y H:i:s')];
        }

        return self::base([
            'accent' => '#0f766e', 'accent_dark' => '#115e59', 'accent_soft' => '#f0fdfa', 'accent_line' => '#99f6e4',
            'icon' => 'file', 'title' => 'WIMS LOGS ARCHIVE REPORT', 'subtitle' => 'Which logs were moved out of the live database, how big they were before and after, and who ran it',
            'reference' => $ref,
            'status' => ['label' => strtoupper($status[0]), 'class' => $status[1]],
            'show_values' => false, 'compact' => true,
            'stats' => [
                ['icon' => 'package', 'label' => 'Records archived', 'value' => number_format((int) $run->rows_archived)],
                ['icon' => 'package', 'label' => 'Space saved', 'value' => $by_($saved)],
                ['icon' => 'package', 'label' => 'Database made smaller by', 'value' => $by_(max(0, (int) $run->db_freed_bytes))],
                ['icon' => 'clock', 'label' => 'Database speed', 'value' => ($sb > 0 ? ($speed >= 0 ? '+' : '') . $speed . '%' : '-')],
            ],
            'parties' => [
                ['title' => 'Run', 'icon' => 'user', 'rows' => [['Done by', ($run->user_name ?: '-')], ['How', $run->trigger === 'schedule' ? 'By the schedule' : 'Run by hand'], ['Started', optional($run->started_at)->format('d/m/Y H:i:s') ?: '-'], ['Finished', optional($run->finished_at)->format('d/m/Y H:i:s') ?: '-'], ['Time taken', (int) $run->seconds . ' s']]],
                ['title' => 'Days each log stays live', 'icon' => 'calendar', 'rows' => [['Rule', $keep ?: '-']]],
            ],
            'extra_parties' => [
                ['title' => 'Sizes', 'icon' => 'package', 'rows' => $sizes],
                ['title' => 'Database speed, read by read (middle of five tries)', 'icon' => 'clock', 'rows' => $reads],
                ['title' => 'Printed and downloaded by', 'icon' => 'user', 'rows' => $print],
            ],
            'table' => [
                'title' => 'What was archived', 'note' => 'Nothing was lost: each record was written to its archive, read back and compared with the original before it was removed from the live table. Every archived record can be read and downloaded in Settings > WIMS Logs.', 'start' => 0,
                'cols' => [['label' => 'Archive', 'a' => 'l'], ['label' => 'Log', 'a' => 'l'], ['label' => 'Records', 'a' => 'r'], ['label' => 'Before', 'a' => 'r'], ['label' => 'Archive', 'a' => 'r'], ['label' => 'Saved', 'a' => 'r'], ['label' => 'Period', 'a' => 'l'], ['label' => 'Check', 'a' => 'l']],
                'rows' => $rows,
            ],
            'lines' => [], 'total' => '', 'total_qty' => '',
            'notes' => 'The archive files are kept on the server and are never removed automatically. Business records (stock, items, requests, transfers, approvals) are never touched.',
            'signatures' => [['role' => 'Run By', 'name' => $run->user_name, 'date' => optional($run->finished_at ?: $run->started_at)->format('d/m/Y')], ['role' => $action . ' By', 'name' => optional($by)->name, 'date' => now()->format('d/m/Y')]],
            'footer' => 'WIMS Logs ' . $ref,
        ]);
    }

    /** How many items one printed or PDF Items report lists; the CSV has them all. */
    public const ITEMS_LIST_LIMIT = 2000;

    /** "Out of stock", "Low" or "In stock", by the same rule as the tiles on the Items page. */
    public static function itemStockState($item): string
    {
        $q = (float) ($item->stock_sum_quantity ?? 0);
        if ((int) $item->track_quantity !== 1) {
            return 'Not tracked';
        }
        if ($q <= 0) {
            return 'Out of stock';
        }

        return (float) $item->alert_quantity > 0 && $q <= (float) $item->alert_quantity ? 'Low' : 'In stock';
    }

    /** The Items list as a WIMS document: the items the page is showing (same search, type, category, deleted and sort), who printed it and when. */
    public static function itemsList($items, int $total, array $f, ?\App\Models\User $by = null, string $action = 'Printed'): array
    {
        $money = fn ($v) => number_format((float) $v, 2);
        $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.') ?: '0';
        $rows = [];
        $value = 0.0;
        $counts = ['Consumable' => 0, 'Used' => 0, 'Out of stock' => 0, 'Low' => 0];
        foreach ($items as $item) {
            $state = self::itemStockState($item);
            $item->is_used ? $counts['Used']++ : $counts['Consumable']++;
            if (isset($counts[$state])) {
                $counts[$state]++;
            }
            $value += (float) $item->price * max(0, (float) $item->stock_sum_quantity);
            $rows[] = [
                ['v' => (string) $item->code, 'a' => 'l', 'nw' => true],
                ['v' => (string) $item->name . ($item->deleted_at ? ' (deleted)' : ''), 'a' => 'l'],
                ['v' => $item->is_used ? 'Used' : 'Consumable', 'a' => 'l', 'nw' => true],
                ['v' => $item->categories->pluck('name')->implode(', ') ?: '-', 'a' => 'l'],
                ['v' => optional($item->unit)->name ?: '-', 'a' => 'l', 'nw' => true],
                ['v' => $money($item->price), 'a' => 'r', 'nw' => true],
                ['v' => $qty($item->stock_sum_quantity), 'a' => 'r', 'nw' => true],
                ['v' => $state, 'a' => 'l', 'nw' => true],
            ];
        }
        $shown = count($rows);
        $typeLabel = ['consumable' => 'Consumable', 'used' => 'Used', 'low' => 'Low stock', 'out' => 'Out of stock'][$f['type'] ?? ''] ?? 'All items';
        $sortLabel = ($f['sort'] ?? null) ? ucfirst($f['sort']) . ' (' . (($f['direction'] ?? 'desc') === 'asc' ? 'low to high' : 'high to low') . ')' : 'Newest first';
        $trash = ['with' => 'Including deleted items', 'only' => 'Deleted items only'][$f['trashed'] ?? ''] ?? 'Not deleted';
        $cut = $total > $shown ? 'The first ' . number_format($shown) . ' of ' . number_format($total) . ' items are listed here. Use Download CSV on the Items page to get all of them.' : null;
        $search = trim((string) ($f['search'] ?? ''));

        return self::base([
            'accent' => '#0f766e', 'accent_dark' => '#115e59', 'accent_soft' => '#f0fdfa', 'accent_line' => '#99f6e4',
            'icon' => 'package', 'title' => 'ITEMS LIST', 'subtitle' => 'The catalogue as the Items page shows it: prices, stock levels and types',
            'reference' => $search !== '' ? strtoupper(\Illuminate\Support\Str::limit($search, 22)) : 'ALL ITEMS',
            'status' => ['label' => strtoupper($typeLabel), 'class' => 'status-info'],
            'show_values' => false, 'compact' => true,
            'stats' => [
                ['icon' => 'package', 'label' => $search !== '' || ($f['type'] ?? null) || ($f['category'] ?? null) ? 'Items found' : 'Items', 'value' => number_format($total)],
                ['icon' => 'hash', 'label' => 'Consumable / Used (listed)', 'value' => number_format($counts['Consumable']) . ' / ' . number_format($counts['Used'])],
                ['icon' => 'package', 'label' => 'Out of stock / Low (listed)', 'value' => number_format($counts['Out of stock']) . ' / ' . number_format($counts['Low'])],
                ['icon' => 'cash', 'label' => 'Stock value at item price (listed)', 'value' => number_format($value, 2) . ' Tsh'],
            ],
            'parties' => [
                ['title' => 'Filters', 'icon' => 'sliders', 'rows' => [['Search', $search !== '' ? $search : 'None'], ['Type', $typeLabel], ['Category', $f['category_name'] ?? 'All'], ['Deleted', $trash], ['Order', $sortLabel]]],
                ['title' => $action, 'icon' => 'user', 'rows' => [['By', ($by->name ?? '-') . ($by && $by->roles->first() ? ' (' . $by->roles->first()->name . ')' : '')], ['Date', now()->format('d/m/Y')], ['Time', now()->format('H:i:s')]]],
            ],
            'table' => [
                'title' => 'Items', 'note' => $cut ?? 'The quantity is the stock on hand in the warehouses you can see, added together.', 'start' => 0,
                'cols' => [['label' => 'Code', 'a' => 'l'], ['label' => 'Item', 'a' => 'l'], ['label' => 'Type', 'a' => 'l'], ['label' => 'Category', 'a' => 'l'], ['label' => 'Unit', 'a' => 'l'], ['label' => 'Price (Tsh)', 'a' => 'r'], ['label' => 'Quantity', 'a' => 'r'], ['label' => 'Stock', 'a' => 'l']],
                'rows' => $rows,
            ],
            'lines' => [], 'total' => '', 'total_qty' => '',
            'notes' => null,
            'signatures' => [['role' => $action . ' By', 'name' => optional($by)->name, 'date' => now()->format('d/m/Y')]],
            'footer' => 'Items list',
        ]);
    }

    /** How many records one printed or PDF report lists; the CSV has them all. */
    public const WIMS_LOG_RECORDS_LIMIT = 1000;

    /** The archived records of one log (as the page lists them, with the search applied) as a WIMS document. */
    public static function wimsLogRecords(string $source, string $label, string $q, $agg, array $records, int $total, ?\App\Models\User $by = null, string $action = 'Printed'): array
    {
        $by_ = fn ($v) => \App\Support\Optimizer\Runner::bytes((int) $v);
        $d = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '-';
        $rows = [];
        foreach ($records as $r) {
            $rows[] = [['v' => $r['at'] ? \Illuminate\Support\Carbon::parse($r['at'])->format('d/m/Y H:i') : '-', 'a' => 'l', 'nw' => true], ['v' => (string) $r['id'], 'a' => 'l', 'nw' => true],
                ['v' => (string) $r['summary'], 'a' => 'l'], ['v' => $r['ref'], 'a' => 'l', 'nw' => true], ['v' => $by_($r['original']), 'a' => 'r', 'nw' => true], ['v' => $by_($r['compressed']), 'a' => 'r', 'nw' => true]];
        }
        $shown = count($records);
        $all = (int) ($agg->rows_n ?? 0);
        $original = (int) ($agg->original ?? 0);
        $stored = (int) ($agg->stored ?? 0);
        $period = $d($agg->date_from ?? null) . ' - ' . $d($agg->date_to ?? null);
        $cut = $total > $shown ? 'The first ' . number_format($shown) . ' of ' . number_format($total) . ' records are listed here. Narrow the search, or use Download CSV on the page to get all of them.' : null;

        return self::base([
            'accent' => '#0f766e', 'accent_dark' => '#115e59', 'accent_soft' => '#f0fdfa', 'accent_line' => '#99f6e4',
            'icon' => 'file', 'title' => 'WIMS LOGS ARCHIVED RECORDS', 'subtitle' => $label . ': the records that were moved out of the live database, kept in compressed archives',
            'reference' => strtoupper($source),
            'status' => ['label' => $q !== '' ? 'SEARCH: ' . strtoupper(\Illuminate\Support\Str::limit($q, 24)) : 'ALL RECORDS', 'class' => 'status-info'],
            'show_values' => false, 'compact' => true,
            'stats' => [
                ['icon' => 'package', 'label' => 'Records in this log', 'value' => number_format($all)],
                ['icon' => 'hash', 'label' => $q !== '' ? 'Records found' : 'Records listed', 'value' => number_format($total)],
                ['icon' => 'package', 'label' => 'Before > compressed', 'value' => $by_($original) . ' > ' . $by_($stored)],
                ['icon' => 'calendar', 'label' => 'Period archived', 'value' => $period],
            ],
            'parties' => [
                ['title' => 'Log', 'icon' => 'file', 'rows' => [['Log', $label], ['Search', $q !== '' ? $q : 'None (every record)'], ['Archives', (string) (int) ($agg->archives ?? 0)], ['Period', $period]]],
                ['title' => $action, 'icon' => 'user', 'rows' => [['By', ($by->name ?? '-') . ($by && $by->roles->first() ? ' (' . $by->roles->first()->name . ')' : '')], ['Date', now()->format('d/m/Y')], ['Time', now()->format('H:i:s')]]],
            ],
            'table' => [
                'title' => 'Archived records', 'note' => $cut ?? 'Nothing was lost: each record was written to its archive, read back and compared with the original before it was removed from the live table. Open a record in Settings > WIMS Logs > Read archived records to read it in full.', 'start' => 0,
                'cols' => [['label' => 'Date', 'a' => 'l'], ['label' => 'Record', 'a' => 'l'], ['label' => 'What it was', 'a' => 'l'], ['label' => 'Archive', 'a' => 'l'], ['label' => 'Before', 'a' => 'r'], ['label' => 'Compressed', 'a' => 'r']],
                'rows' => $rows,
            ],
            'lines' => [], 'total' => '', 'total_qty' => '',
            'notes' => 'The archive files are kept on the server and are never removed automatically. Business records (stock, items, requests, transfers, approvals) are never touched.',
            'signatures' => [['role' => $action . ' By', 'name' => optional($by)->name, 'date' => now()->format('d/m/Y')]],
            'footer' => 'WIMS Logs records ' . strtoupper($source),
        ]);
    }

    /** The BinCard of an item as a WIMS document: the picture, the item, its Last update and who printed it, and when. */
    public static function binCard(\App\Models\Item $item, string $imageSrc, ?\App\Models\User $by = null): array
    {
        $item->loadMissing(['unit:id,name,code', 'categories:id,name']);
        $updatedBy = $item->bincard_by ? optional(\App\Models\User::withoutGlobalScopes()->find($item->bincard_by))->name : null;

        return self::base([
            'accent' => '#0f766e', 'accent_dark' => '#115e59', 'accent_soft' => '#f0fdfa', 'accent_line' => '#99f6e4',
            'icon' => 'file', 'title' => 'BINCARD', 'subtitle' => 'The stock card kept with the goods, as photographed',
            'reference' => $item->code ?: ('#' . $item->id),
            'status' => ['label' => 'Last update ' . optional($item->bincard_at)->format('d/m/Y'), 'class' => 'status-info'],
            'show_values' => false, 'compact' => true,
            'stats' => [
                ['icon' => 'package', 'label' => 'Item', 'value' => \Illuminate\Support\Str::limit($item->name, 34)],
                ['icon' => 'hash', 'label' => 'Code', 'value' => $item->code ?: '-'],
                ['icon' => 'calendar', 'label' => 'Last update', 'value' => optional($item->bincard_at)->format('d/m/Y H:i') ?: '-'],
                ['icon' => 'user', 'label' => 'Updated by', 'value' => $updatedBy ?: '-'],
            ],
            'parties' => [
                ['title' => 'Item', 'icon' => 'package', 'rows' => [['Name', $item->name], ['Code', $item->code ?: '-'], ['Category', $item->categories->pluck('name')->implode(', ') ?: '-'], ['Unit', optional($item->unit)->name ?: '-']]],
                ['title' => 'Printed', 'icon' => 'user', 'rows' => [['Printed by', optional($by)->name ?: '-'], ['Date', now()->format('d/m/Y')], ['Time', now()->format('H:i:s')]]],
            ],
            'image' => ['title' => 'BinCard photo', 'src' => $imageSrc, 'max_h' => 480],
            'lines' => [], 'total' => '', 'total_qty' => '', 'notes' => null,
            'signatures' => [['role' => 'Printed By', 'name' => optional($by)->name, 'date' => now()->format('d/m/Y H:i')]],
            'footer' => 'BinCard ' . ($item->code ?: $item->id),
        ]);
    }

    /** The Print Barcodes page as a WIMS document: the labels exactly as chosen on the page (style, size, name, copies), who printed and when. */
    public static function itemLabels(array $labels, array $opts, int $itemCount, ?\App\Models\User $by = null, ?string $note = null): array
    {
        $styleName = $opts['style'] === 'qr' ? 'QR Code' : 'Barcode';
        $sizeName = ['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large'][$opts['size']] ?? 'Medium';

        return self::base([
            'accent' => '#1d4ed8', 'accent_dark' => '#1e3a8a', 'accent_soft' => '#eff6ff', 'accent_line' => '#bfdbfe',
            'icon' => 'hash', 'title' => 'ITEM LABELS', 'subtitle' => $styleName . ' labels for the stores',
            'reference' => now()->format('Ymd-His'),
            'status' => ['label' => count($labels) . ' ' . (count($labels) === 1 ? 'label' : 'labels'), 'class' => 'status-info'],
            'show_values' => false, 'compact' => true,
            'stats' => [
                ['icon' => 'hash', 'label' => 'Labels', 'value' => count($labels)],
                ['icon' => 'package', 'label' => 'Items', 'value' => $itemCount],
                ['icon' => 'file', 'label' => 'Style', 'value' => $styleName],
                ['icon' => 'sliders', 'label' => 'Size', 'value' => $sizeName],
            ],
            'parties' => [
                ['title' => 'Labels', 'icon' => 'package', 'rows' => [['Style', $styleName], ['Size', $sizeName], ['Item name', $opts['show_name'] ? 'Shown' : 'Hidden'], ['Copies per item', $opts['copies']]]],
                ['title' => 'Printed', 'icon' => 'user', 'rows' => [['Printed by', optional($by)->name ?: '-'], ['Date', now()->format('d/m/Y')], ['Time', now()->format('H:i:s')]]],
            ],
            'labels' => ['items' => $labels, 'size' => $opts['size'], 'width' => ['sm' => 150, 'md' => 196, 'lg' => 250][$opts['size']] ?? 196, 'style' => $opts['style'], 'show_name' => (bool) $opts['show_name']],
            'lines' => [], 'total' => '', 'total_qty' => '', 'notes' => $note,
            'signatures' => [['role' => 'Printed By', 'name' => optional($by)->name, 'date' => now()->format('d/m/Y H:i')]],
            'footer' => 'Item labels ' . now()->format('d/m/Y H:i'),
        ]);
    }

    public static function adjustment(Adjustment $adjustment): array
    {
        [$lines, $total, $totalQty] = self::valuedLines($adjustment->items);
        // what each line did to the stock: how much there was, the change and how much there is after (see App\Services\AdjustmentDifferences)
        $diff = \App\Services\AdjustmentDifferences::for($adjustment);
        $fmt = fn ($v) => $v === null ? '-' : self::qty($v);
        foreach ($adjustment->items->values() as $n => $item) {
            $d = $diff['rows'][$item->id] ?? null;
            if (isset($lines[$n]) && $d) {
                $lines[$n]['was'] = $fmt($d['was']);
                $lines[$n]['change'] = $d['change'] === null ? '-' : (($d['change'] > 0 ? '+' : ($d['change'] < 0 ? '-' : '')) . self::qty(abs($d['change'])));
                $lines[$n]['change_dir'] = $d['change'] === null ? 0 : ($d['change'] <=> 0);
                $lines[$n]['now'] = $fmt($d['now']);
            }
        }

        return self::base([
            'change_cols' => true, 'change_note' => $diff['basis'] === 'projected' ? 'Not approved yet: Was is the stock today and Now is what it will be once approved.' : ($diff['basis'] === 'none' ? 'Rejected: the stock was not changed.' : 'Was and Now are the stock of the warehouse before and after this adjustment.'),
            'accent' => '#7c3aed', 'accent_dark' => '#6d28d9', 'accent_soft' => '#f5f3ff', 'accent_line' => '#ddd6fe',
            'icon' => 'sliders', 'title' => 'STOCK ADJUSTMENT', 'subtitle' => 'Correction to recorded stock levels',
            'reference' => $adjustment->reference,
            'status' => match ($adjustment->status) {
                'Rejected' => ['label' => 'Rejected', 'class' => 'status-danger'],
                'Approved' => ['label' => 'Approved', 'class' => 'status-active'],
                default => ['label' => 'Pending Approval', 'class' => 'status-warn'],
            },
            'draft' => (bool) $adjustment->draft,
            'stats' => [
                ['icon' => 'calendar', 'label' => 'Date', 'value' => self::date($adjustment->date)],
                ['icon' => 'package', 'label' => 'Line Items', 'value' => count($lines)],
                ['icon' => 'hash', 'label' => 'Total Quantity', 'value' => self::qty($totalQty)],
                ['icon' => 'cash', 'label' => 'Total Value', 'value' => number_format($total, 2)],
            ],
            'parties' => [
                ['title' => 'Adjustment', 'icon' => 'sliders', 'rows' => [
                    ['Warehouse', optional($adjustment->warehouse)->name ?? '-'],
                    ['Type', $adjustment->type ?? '-'],
                ]],
                ['title' => 'Authorisation', 'icon' => 'user', 'rows' => [
                    ['Requested By', optional($adjustment->user)->name ?? '-'],
                    $adjustment->rejected_at
                        ? ['Rejected By', (optional($adjustment->rejecter)->name ?? '-') . ' - ' . self::date($adjustment->rejected_at)]
                        : ['Approved By', trim((optional($adjustment->approver)->name ?? '-') . ($adjustment->approved_by_role ? ' (' . $adjustment->approved_by_role . ')' : ''))],
                    ...($adjustment->rejected_at && $adjustment->rejection_reason ? [['Rejection Reason', $adjustment->rejection_reason]] : []),
                ]],
            ],
            'lines' => $lines, 'total' => number_format($total, 2), 'total_qty' => self::qty($totalQty),
            'notes' => $adjustment->details,
            'signatures' => [
                ['role' => 'Requested By', 'name' => optional($adjustment->user)->name, 'date' => self::date($adjustment->date)],
                $adjustment->rejected_at
                    ? ['role' => 'Rejected By', 'name' => optional($adjustment->rejecter)->name, 'date' => self::date($adjustment->rejected_at)]
                    : ['role' => 'Approved By' . ($adjustment->approved_by_role ? ' (' . $adjustment->approved_by_role . ')' : ''), 'name' => optional($adjustment->approver)->name, 'date' => $adjustment->approved_at ? self::date($adjustment->approved_at) : null],
            ],
            'footer' => 'Adjustment ' . $adjustment->reference,
        ]);
    }

    /** Delivery Note: always rendered live from its parent Transfer/Checkin, so edits to the parent show up automatically. */
    /** The parties of a Gate Pass / Delivery Note for an External Stock movement: our warehouse on one side, the External Personnel (and who collects or brings) on the other. */
    private static function externalParties($parent, bool $gate): array
    {
        $out = $parent->kind === 'withdrawal';
        $contact = optional($parent->contact);
        $person = ['title' => $out ? ($gate ? 'Released To (External Personnel)' : 'Delivered To (External Personnel)') : ($gate ? 'Arriving From (External Personnel)' : 'Delivered From (External Personnel)'), 'icon' => 'user', 'rows' => array_values(array_filter([
            ['Name', $contact->name ?? '-'],
            ['Phone', $contact->phone ?? '-'],
            ($parent->location ?: ($contact->location ?? null)) ? ['Location', $parent->location ?: $contact->location] : null,
            [$out ? 'Collected By' : 'Brought By', trim(($parent->collector_name ?: '-') . ($parent->collector_phone ? ' (' . $parent->collector_phone . ')' : ''))],
            $parent->collector_id_no ? ['ID / Licence No.', $parent->collector_id_no] : null,
        ]))];
        $store = ['title' => $out ? ($gate ? 'Released From' : 'Delivered From') : ($gate ? 'Delivering To' : 'Delivered To'), 'icon' => 'pin', 'rows' => [
            ['Warehouse', optional($parent->warehouse)->name ?? '-'],
            [$out ? 'Recorded By' : 'Received By', optional($parent->user)->name ?? '-'],
        ]];

        return $out ? [$store, $person] : [$person, $store];
    }

    public static function deliveryNote(DeliveryNote $note, $parent): array
    {
        $isTransfer = $note->source_type === DeliveryNote::SOURCE_TRANSFER;
        $isExternal = $note->source_type === DeliveryNote::SOURCE_EXTERNAL;

        $lines = [];
        $totalQty = 0.0;
        foreach ($parent->items as $line) {
            $totalQty += (float) $line->quantity;
            $lines[] = [
                'name' => optional($line->item)->name ?? '-',
                'code' => optional($line->item)->code,
                'unit' => optional($line->unit)->name ?? optional($line->unit)->code ?? '-',
                'qty' => self::qty($line->quantity),
            ];
        }

        if ($isExternal) {
            $parties = self::externalParties($parent, false);
        } elseif ($isTransfer) {
            $parties = [
                ['title' => 'Delivered From', 'icon' => 'pin', 'rows' => [
                    ['Warehouse', optional($parent->fromWarehouse)->name ?? '-'],
                    ['Dispatched By', optional($parent->user)->name ?? '-'],
                ]],
                ['title' => 'Delivered To', 'icon' => 'pin', 'rows' => [
                    ['Warehouse', optional($parent->toWarehouse)->name ?? '-'],
                    ['Received By', optional($parent->receivedBy)->name ?? 'Pending'],
                ]],
            ];
        } else {
            $parties = [
                ['title' => 'Delivered From (Supplier)', 'icon' => 'user', 'rows' => [
                    ['Name', optional($parent->contact)->name ?? '-'],
                    ['Phone', optional($parent->contact)->phone ?? '-'],
                    ['Email', optional($parent->contact)->email ?? '-'],
                ]],
                ['title' => 'Delivered To', 'icon' => 'pin', 'rows' => [
                    ['Warehouse', optional($parent->warehouse)->name ?? '-'],
                    ['Responsible', optional($parent->user)->name ?? '-'],
                ]],
            ];
        }

        return self::base([
            'accent' => '#2f9be6', 'accent_dark' => '#0b6fb8', 'accent_soft' => '#eef7ff', 'accent_line' => '#bfe0fb', 'ink_saver' => true,
            'icon' => 'truck', 'title' => 'DELIVERY NOTE', 'subtitle' => 'Goods delivered - for signature on receipt',
            'reference' => $note->reference,
            'status' => ['label' => 'Issued', 'class' => 'status-info'],
            'draft' => false,
            'parent' => ['label' => $isExternal ? 'External Stock' : ($isTransfer ? 'Transfer' : 'Check-in'), 'reference' => $parent->reference],
            'stats' => [
                ['icon' => 'calendar', 'label' => 'Date', 'value' => self::date($parent->date)],
                ['icon' => 'file', 'label' => $isExternal ? 'Parent External Stock' : ($isTransfer ? 'Parent Transfer' : 'Parent Check-in'), 'value' => $parent->reference],
                ['icon' => 'package', 'label' => 'Line Items', 'value' => count($lines)],
                ['icon' => 'hash', 'label' => 'Total Quantity', 'value' => self::qty($totalQty)],
            ],
            'parties' => $parties,
            'lines' => $lines, 'total_qty' => self::qty($totalQty),
            'show_values' => false, 'extra_cols' => true,
            'notes' => $isExternal ? $parent->purpose : $parent->details,
            'stamp_boxes' => true,
            // A third, separate block: whoever receives the items on site signs and dates here.
            'site_receipt' => true,
            'copies' => ['ORIGINAL', 'COPY'],
            'signatures' => [
                ['role' => 'Prepared By', 'name' => optional($note->createdBy)->name, 'date' => null],
                ['role' => 'Approved By', 'name' => null, 'date' => null],
            ],
            'footer' => 'Delivery Note ' . $note->reference . ' / ' . ($isExternal ? 'External Stock ' : ($isTransfer ? 'Transfer ' : 'Check-in ')) . $parent->reference,
        ]);
    }

    /**
     * Gate Pass: same live-from-parent idea as the Delivery Note, plus the driver and vehicle that carry
     * the goods, a tick box per item for the gate check, and a Gate Security / Security Guard sign-off.
     */
    public static function gatePass(GatePass $pass, $parent): array
    {
        $isTransfer = $pass->source_type === GatePass::SOURCE_TRANSFER;
        $isExternal = $pass->source_type === GatePass::SOURCE_EXTERNAL;
        $outgoing = $isExternal ? $parent->kind === 'withdrawal' : $isTransfer;

        $lines = [];
        $totalQty = 0.0;
        foreach ($parent->items as $line) {
            $totalQty += (float) $line->quantity;
            $lines[] = [
                'name' => optional($line->item)->name ?? '-',
                'code' => optional($line->item)->code,
                'unit' => optional($line->unit)->name ?? optional($line->unit)->code ?? '-',
                'qty' => self::qty($line->quantity),
            ];
        }

        if ($isExternal) {
            $parties = self::externalParties($parent, true);
        } elseif ($isTransfer) {
            $parties = [
                ['title' => 'Released From', 'icon' => 'pin', 'rows' => [
                    ['Warehouse', optional($parent->fromWarehouse)->name ?? '-'],
                    ['Dispatched By', optional($parent->user)->name ?? '-'],
                ]],
                ['title' => 'Destination', 'icon' => 'pin', 'rows' => [
                    ['Warehouse', optional($parent->toWarehouse)->name ?? '-'],
                    ['Received By', optional($parent->receivedBy)->name ?? 'Pending'],
                ]],
            ];
        } else {
            $parties = [
                ['title' => 'Arriving From (Supplier)', 'icon' => 'user', 'rows' => [
                    ['Name', optional($parent->contact)->name ?? '-'],
                    ['Phone', optional($parent->contact)->phone ?? '-'],
                    ['Email', optional($parent->contact)->email ?? '-'],
                ]],
                ['title' => 'Delivering To', 'icon' => 'pin', 'rows' => [
                    ['Warehouse', optional($parent->warehouse)->name ?? '-'],
                    ['Responsible', optional($parent->user)->name ?? '-'],
                ]],
            ];
        }

        $driver = $pass->driverDisplay();
        $vehicle = $pass->vehicleDisplay();
        $dash = fn ($v) => filled($v) ? $v : '-';

        $transport = [
            ['title' => 'Driver', 'icon' => 'user', 'rows' => [
                ['Name', $dash($driver['name'])],
                ['Phone', $dash($driver['phone'])],
            ]],
            ['title' => 'Vehicle', 'icon' => 'truck', 'rows' => array_values(array_filter([
                ['Registration No.', $dash($vehicle['plate'])],
                ['Make / Model', $dash($vehicle['details'])],
                filled($vehicle['type']) && $vehicle['type'] !== $vehicle['plate'] ? ['Vehicle', $vehicle['type']] : null,
            ]))],
        ];

        return self::base([
            'accent' => '#6d63e8', 'accent_dark' => '#4338ca', 'accent_soft' => '#f3f1ff', 'accent_line' => '#d3cef9', 'ink_saver' => true,
            'icon' => 'shield', 'title' => 'GATE PASS',
            'subtitle' => $outgoing ? 'Authorisation to release the goods below from the premises' : 'Authorisation to admit the goods below onto the premises',
            'reference' => $pass->reference,
            'status' => ['label' => 'Issued', 'class' => 'status-violet'],
            'draft' => false,
            'parent' => ['label' => $isExternal ? 'External Stock' : ($isTransfer ? 'Transfer' : 'Check-in'), 'reference' => $parent->reference],
            'stats' => [
                ['icon' => 'calendar', 'label' => 'Date', 'value' => self::date($parent->date)],
                ['icon' => 'transfer', 'label' => 'Movement', 'value' => $outgoing ? 'Outgoing' : 'Incoming'],
                ['icon' => 'package', 'label' => 'Line Items', 'value' => count($lines)],
                ['icon' => 'hash', 'label' => 'Total Quantity', 'value' => self::qty($totalQty)],
            ],
            'parties' => $parties,
            'extra_parties' => $transport,
            'lines' => $lines, 'total_qty' => self::qty($totalQty),
            'show_values' => false, 'extra_cols' => true, 'tick_col' => true,
            'notes' => $isExternal ? $parent->purpose : $parent->details,
            'stamp_boxes' => true,
            'signatures' => [
                ['role' => 'Prepared By', 'name' => optional($pass->createdBy)->name, 'date' => null],
                ['role' => 'Approved By', 'name' => null, 'date' => null],
            ],
            'gate_security' => true, 'compact' => true, 'copies' => ['ORIGINAL', 'COPY'],
            'footer' => 'Gate Pass ' . $pass->reference . ' / ' . ($isExternal ? 'External Stock ' : ($isTransfer ? 'Transfer ' : 'Check-in ')) . $parent->reference,
        ]);
    }
}
