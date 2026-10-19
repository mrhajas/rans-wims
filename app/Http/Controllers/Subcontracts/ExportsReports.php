<?php

namespace App\Http\Controllers\Subcontracts;

use Barryvdh\DomPDF\Facade\Pdf;
use Rap2hpoutre\FastExcel\FastExcel;

/** Print, PDF, Excel and CSV of a report in the WIMS report layout (the same view as every other report). Every export is written to the activity log. */
trait ExportsReports
{
    protected function logExport(array $r, string $format, ?string $what = null): void
    {
        log_activity('Subcontract report exported', ['type' => $r['type'], 'format' => $format, 'rows' => $r['total_rows'], 'reference' => $r['reference'], 'filters' => $r['filters'], 'what' => $what], null, 'Subcontracts');
    }

    protected function pdfOf(array $r)
    {
        $landscape = count($r['columns']) > 6;
        $pdf = Pdf::loadView('signatories.report-print', ['report' => $r, 'landscape' => $landscape, 'isPdf' => true, 'autoPrint' => false, 'companyName' => preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) optional(auth()->user()->account)->name), 'printedBy' => auth()->user()->name, 'printedAt' => now()->format('d M Y, H:i')])
            ->setPaper('a4', $landscape ? 'landscape' : 'portrait');
        $pdf->render();
        $d = $pdf->getDomPDF();
        $c = $d->getCanvas();
        $c->page_text($c->get_width() - 95, $c->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $d->getFontMetrics()->getFont('Helvetica'), 8.5, [0.61, 0.64, 0.69]);

        return $pdf;
    }

    protected function sheet(array $r, string $ext)
    {
        $file = 'subcontracts-' . $r['type'] . '-' . now()->format('Ymd-His') . '.' . $ext;
        $safe = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && ! is_numeric($v) ? "'" . $v : $v;
        if ($r['raw'] === []) {
            return response()->streamDownload(function () use ($r) {
                $o = fopen('php://output', 'w');
                fputcsv($o, array_column($r['columns'], 'label'));
                fclose($o);
            }, preg_replace('/\.xlsx$/', '.csv', $file), ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $gen = function () use ($r, $safe) {
            foreach ($r['raw'] as $row) {
                $o = [];
                foreach ($r['columns'] as $c) {
                    $v = $row[$c['key']] ?? '';
                    $o[$c['label']] = ($c['type'] ?? '') === 'money' && $v !== '' && $v !== null ? (float) $v : $safe(is_scalar($v) || $v === null ? $v : (string) $v);
                }
                yield $o;
            }
        };
        $resp = (new FastExcel($gen()))->download($file);
        if ($ext === 'csv') {
            $resp->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        }

        return $resp;
    }
}
