<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\SubcontractAgreement as A;
use App\Services\Subcontracts\SubcontractReports;
use App\Services\Subcontracts\SubcontractSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** The subcontract reports in the WIMS report layout: screen preview, print, PDF, Excel and CSV; every export is logged. */
class ReportController extends Controller
{
    use SubcontractHelpers, ExportsReports;

    private const KEYS = ['contact_id', 'agreement_id', 'warehouse_id', 'status', 'currency', 'from', 'to', 'kind', 'request_id', 'variation_id', 'claim_id'];

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->scalarQuery($request);
        $this->need('read-subcontract-reports');
        $money = $this->money();
        $catalog = array_values(array_filter(SubcontractReports::catalog(), fn ($c) => (! $c['money'] || $money) && empty($c['hidden'])));
        $type = (string) $request->query('type', $catalog[0]['key'] ?? 'agreements');
        $filters = $request->only(self::KEYS);
        $keys = array_column($catalog, 'key');
        if ($request->boolean('preview') && in_array($type, $keys, true)) {
            $this->logOpen($type);

            return response()->json(collect(SubcontractReports::build($request->user(), $type, $filters, 300))->except('raw')->all());
        }

        return Inertia::render('Subcontracts/Reports', [
            'catalog' => $catalog, 'type' => in_array($type, $keys, true) ? $type : ($keys[0] ?? 'agreements'), 'filters' => $filters, 'contacts' => $this->subcontractors(), 'projects' => $this->projects(),
            'agreements' => $this->visibleAgreements()->orderByDesc('id')->limit(500)->get(['id', 'reference', 'title'])->map(fn ($a) => ['value' => $a->id, 'label' => $a->reference . ' - ' . $a->title]),
            'currencies' => collect(SubcontractSettings::get()['currencies'])->map(fn ($c) => ['value' => $c, 'label' => $c])->values(), 'can' => ['print' => $this->can('print-subcontract-reports'), 'open' => ['agreement' => $this->can('read-subcontract-agreements'), 'request' => $this->can('read-subcontract-requests'), 'variation' => $this->can('read-subcontract-variations'), 'claim' => $this->can('read-subcontract-claims'), 'contact' => $this->can('read-subcontracts'), 'voucher' => $this->can('read-payment-vouchers'),
                'import' => $this->can('import-subcontracts') || $this->can('confirm-import-subcontracts') || $this->can('reconcile-subcontracts')]],
        ]);
    }

    private function logOpen(string $type): void
    {
        // opening a financial report is not logged every time (the preview refreshes as filters change); exports are
    }

    private function exportable(Request $request, string $format): array
    {
        $this->need('read-subcontract-reports');
        $this->need('print-subcontract-reports');
        $this->scalarQuery($request);
        $type = (string) $request->query('type', '');
        abort_unless(SubcontractReports::exists($type), 404);
        $r = SubcontractReports::build($request->user(), $type, $request->only(self::KEYS), in_array($format, ['pdf', 'print'], true) ? SubcontractReports::PDF_LIMIT : SubcontractReports::LIMIT, in_array($format, ['pdf', 'print'], true));
        $this->logExport($r, $format);

        return $r;
    }

    private function name(Request $request, string $ext = 'pdf'): string
    {
        return 'subcontracts-' . preg_replace('/[^a-z_]/', '', is_string($request->query('type')) ? (string) $request->query('type') : 'report') . '-' . now()->format('Ymd-His') . '.' . $ext;
    }

    public function print(Request $request)
    {
        return $this->pdfOf($this->exportable($request, 'print'))->stream($this->name($request));
    }

    public function pdf(Request $request)
    {
        return $this->pdfOf($this->exportable($request, 'pdf'))->download($this->name($request));
    }

    public function csv(Request $request)
    {
        return $this->sheet($this->exportable($request, 'csv'), 'csv');
    }

    public function xlsx(Request $request)
    {
        return $this->sheet($this->exportable($request, 'xlsx'), 'xlsx');
    }
}
