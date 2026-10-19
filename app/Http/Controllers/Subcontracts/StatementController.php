<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\Subcontracts\SubcontractReports;
use App\Services\Subcontracts\SubcontractSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** The account statement of a subcontractor or one agreement, on screen and as print / PDF / Excel (the same WIMS report layout). */
class StatementController extends Controller
{
    use SubcontractHelpers, ExportsReports;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function filters(Request $request, ?int $contact, ?int $agreement): array
    {
        $this->scalarQuery($request);

        return ['contact_id' => $contact, 'agreement_id' => $agreement] + $request->only(['warehouse_id', 'currency', 'from', 'to', 'kind']);
    }

    private function guard(): void
    {
        $this->need('read-subcontract-reports', 'print-subcontract-reports', 'read-subcontracts');
        $this->need('view-financials-subcontracts');
    }

    public function contact(Request $request, int $contact)
    {
        $this->guard();
        $c = Contact::query()->where('category', 'SubContractors')->findOrFail($contact);

        return $this->page($request, $this->filters($request, $c->id, null), ['kind' => 'contact', 'id' => $c->id, 'title' => $c->name]);
    }

    public function agreement(Request $request, int $agreement)
    {
        $this->guard();
        $a = $this->agreementOrFail($agreement);

        return $this->page($request, $this->filters($request, null, $a->id), ['kind' => 'agreement', 'id' => $a->id, 'title' => $a->reference . ' - ' . $a->title]);
    }

    private function page(Request $request, array $f, array $who)
    {
        $r = SubcontractReports::build($request->user(), 'statement', $f, 1000);

        return Inertia::render('Subcontracts/Statement', ['who' => $who, 'report' => collect($r)->except('raw')->all(), 'filters' => $request->only(['warehouse_id', 'currency', 'from', 'to', 'kind']), 'type_keys' => array_flip(['contract' => __('Contract'), 'variation' => __('Variation'), 'request' => __('Request approved'), 'legacy_payment' => __('Payment (historical)'), 'payment' => __('Payment'), 'voucher' => __('Voucher')]),
            'currencies' => collect(SubcontractSettings::get()['currencies'])->map(fn ($c) => ['value' => $c, 'label' => $c])->values(), 'projects' => $this->projects(), 'can' => ['print' => $this->can('print-subcontract-reports'), 'open' => ['agreement' => $this->can('read-subcontract-agreements'), 'request' => $this->can('read-subcontract-requests'), 'variation' => $this->can('read-subcontract-variations'), 'voucher' => $this->can('read-payment-vouchers')]]]);
    }

    private function exportable(Request $request, string $kind, int $id, string $format): array
    {
        $this->guard();
        $this->need('print-subcontract-reports');
        if ($kind === 'contact') {
            Contact::query()->where('category', 'SubContractors')->findOrFail($id);
            $f = $this->filters($request, $id, null);
        } else {
            $this->agreementOrFail($id);
            $f = $this->filters($request, null, $id);
        }
        $r = SubcontractReports::build($request->user(), 'statement', $f, in_array($format, ['pdf', 'print'], true) ? SubcontractReports::PDF_LIMIT : SubcontractReports::LIMIT, in_array($format, ['pdf', 'print'], true));
        $this->logExport($r, $format, $kind . ':' . $id);

        return $r;
    }

    public function print(Request $request, string $kind, int $id)
    {
        return $this->pdfOf($this->exportable($request, $kind, $id, 'print'))->stream('subcontract-statement-' . $kind . '-' . $id . '.pdf');
    }

    public function pdf(Request $request, string $kind, int $id)
    {
        return $this->pdfOf($this->exportable($request, $kind, $id, 'pdf'))->download('subcontract-statement-' . $kind . '-' . $id . '.pdf');
    }

    public function xlsx(Request $request, string $kind, int $id)
    {
        return $this->sheet($this->exportable($request, $kind, $id, 'xlsx'), 'xlsx');
    }
}
