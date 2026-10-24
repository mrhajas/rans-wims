<?php

namespace App\Http\Controllers;

use App\Models\Adjustment;
use App\Models\SuperAdjustment;
use App\Services\ValueReport;
use App\Support\PrintDocuments;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Rap2hpoutre\FastExcel\FastExcel;

/**
 * The value report of a Super Adjustment or an Adjustment (App\Services\ValueReport): print, PDF and Excel. The route names contain print / pdf / export, so every
 * use is recorded in the Download / Print log, and each one is also written to the activity log of the record.
 */
class ValueReportController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    // ---- Super Adjustment
    public function superPrint(Request $request, SuperAdjustment $super_adjustment)
    {
        return view('print.document', $this->documentData($request, $this->superReport($request, $super_adjustment), $super_adjustment, false, 'printed'));
    }

    public function superPdf(Request $request, SuperAdjustment $super_adjustment)
    {
        return $this->pdfResponse($request, $this->superReport($request, $super_adjustment), $super_adjustment);
    }

    public function superExport(Request $request, SuperAdjustment $super_adjustment)
    {
        return $this->excel($request, $this->superReport($request, $super_adjustment), $super_adjustment);
    }

    // ---- Adjustment
    public function adjustmentPrint(Request $request, Adjustment $adjustment)
    {
        return view('print.document', $this->documentData($request, $this->adjustmentReport($request, $adjustment), $adjustment, false, 'printed'));
    }

    public function adjustmentPdf(Request $request, Adjustment $adjustment)
    {
        return $this->pdfResponse($request, $this->adjustmentReport($request, $adjustment), $adjustment);
    }

    public function adjustmentExport(Request $request, Adjustment $adjustment)
    {
        return $this->excel($request, $this->adjustmentReport($request, $adjustment), $adjustment);
    }

    private function superReport(Request $request, SuperAdjustment $sa): array
    {
        abort_unless($request->user()->can('read-super-adjustments') && $request->user()->can('print-super-adjustments'), 403);

        return ValueReport::forSuperAdjustment($sa);
    }

    private function adjustmentReport(Request $request, Adjustment $a): array
    {
        abort_unless($request->user()->can('read-adjustments') && $request->user()->can('print-adjustments'), 403);
        $r = ValueReport::forAdjustment($a);
        abort_unless($r['available'], 404);

        return $r;
    }

    private function documentData(Request $request, array $report, $record, bool $isPdf, string $what): array
    {
        $super = $record instanceof SuperAdjustment;
        $record->loadMissing($super ? ['user:id,name', 'approver:id,name'] : ['user:id,name', 'approver:id,name']);
        $doc = PrintDocuments::valueReport($report, $request->user(), optional($record->user)->name, optional($record->approver)->name);
        log_activity('Value report of ' . $record->reference . ' ' . $what, ['items' => $report['totals']['items'], 'difference_value' => $report['totals']['diff']], $record, $super ? 'SuperAdjustment' : 'Adjustment');

        return [
            'doc' => $doc, 'isPdf' => $isPdf, 'companyName' => optional($request->user()->account)->name,
            'qr' => PrintQrCode::dataUri(route($super ? 'super-adjustments.show' : 'adjustments.show', $record->id)),
            'pdfUrl' => route($super ? 'super-adjustments.value-report.pdf' : 'adjustments.value-report.pdf', $record->id),
        ];
    }

    private function pdfResponse(Request $request, array $report, $record)
    {
        $data = $this->documentData($request, $report, $record, true, 'downloaded as PDF');

        return Pdf::loadView('print.document', $data)->setPaper('a4', 'landscape')->download('value-report-' . $record->reference . '.pdf');
    }

    private function excel(Request $request, array $report, $record)
    {
        $this->documentData($request, $report, $record, true, 'downloaded as Excel');
        $L = $report['labels'];
        $rows = [];
        foreach ($report['rows'] as $i => $r) {
            $rows[] = [__('No') => $i + 1, __('Item') => $r['name'], __('Code') => $r['code'], __('Unit') => $r['unit'], __('Unit price') => $r['price'], $L['before'] => $r['before'], $L['before_total'] => $r['before_total'],
                $L['after'] => $r['after'], $L['after_total'] => $r['after_total'], __('Difference') => $r['diff'], __('Difference in value') => $r['diff_value']];
        }
        $t = $report['totals'];
        $rows[] = [__('No') => '', __('Item') => __('Totals'), __('Code') => '', __('Unit') => '', __('Unit price') => '', $L['before'] => $t['before_qty'], $L['before_total'] => $t['before'], $L['after'] => $t['after_qty'],
            $L['after_total'] => $t['after'], __('Difference') => $t['diff_qty'], __('Difference in value') => $t['diff']];

        return (new FastExcel(collect($rows)))->download('value-report-' . $record->reference . '.xlsx');
    }
}
