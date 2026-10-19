<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\SubcontractReconciliation as Rc;
use App\Services\Subcontracts\SubcontractLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Reconciliation of imported accounts: the workbook figures against what WIMS holds. A difference stays open until a finance administrator approves it with a note; it is never forced to zero. */
class ReconciliationController extends Controller
{
    use SubcontractHelpers, SortsLists;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->scalarQuery($request);
        $this->need('reconcile-subcontracts', 'approve-reconciliation-subcontracts');
        abort_unless($this->money(), 403, __('Reconciliation and the Excel import show contract and payment figures: you need the permission to see financial figures.'));
        $status = (string) $request->query('status', 'open');
        $search = trim((string) $request->query('search', ''));
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
        $rows = Rc::query()->whereIn('agreement_id', $this->visibleAgreements()->select('id'))->when(in_array($status, ['open', 'approved'], true), fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->whereIn('agreement_id', $this->visibleAgreements()->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('title', 'like', $like)->orWhere('site', 'like', $like)->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $like)))->select('id')))
            ->orderBy('id');
        [$sort, $dir] = $this->sortOf($request, ['agreement', 'contact', 'workbook', 'historical', 'difference', 'dated', 'status']);
        $filters = ['sort' => $sort, 'dir' => $sort !== '' ? $dir : '', 'search' => $search, 'status' => $status];
        if ($sort !== '') {
            // ordered across ALL the matching rows, then the page is cut out of that order
            $all = (clone $rows)->get();
            $agsAll = \App\Models\SubcontractAgreement::withoutGlobalScopes()->whereIn('id', $all->pluck('agreement_id'))->with('contact:id,name')->get()->keyBy('id');
            $rows = $this->sortedPage($all, fn ($r, $c) => match ($c) {
                'agreement' => optional($agsAll[$r->agreement_id] ?? null)->reference, 'contact' => optional(optional($agsAll[$r->agreement_id] ?? null)->contact)->name, 'workbook' => $r->source_paid,
                'historical' => $r->imported_paid, 'difference' => bcsub((string) ($r->imported_paid ?? '0'), (string) ($r->source_paid ?? '0'), 2), 'dated' => $r->dated_list_total, 'status' => $r->status, default => null,
            }, $sort, $dir, $request, 25);
        } else {
            $rows = $rows->paginate(25)->withQueryString();
        }
        $ags = \App\Models\SubcontractAgreement::withoutGlobalScopes()->whereIn('id', $rows->getCollection()->pluck('agreement_id'))->with('contact:id,name')->get()->keyBy('id');
        $fig = SubcontractLedger::forAgreements($rows->getCollection()->pluck('agreement_id')->all());
        $rows->through(fn ($r) => $this->row($r, $ags[$r->agreement_id] ?? null, $fig[$r->agreement_id] ?? null));

        return Inertia::render('Subcontracts/Reconciliation/Index', ['rows' => $rows, 'filters' => $filters, 'status' => $status, 'search' => $search, 'counts' => Rc::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'), 'can' => ['approve' => $this->can('approve-reconciliation-subcontracts')]]);
    }

    public function show(int $agreement)
    {
        $this->need('reconcile-subcontracts', 'approve-reconciliation-subcontracts');
        abort_unless($this->money(), 403, __('Reconciliation and the Excel import show contract and payment figures: you need the permission to see financial figures.'));
        $a = $this->agreementOrFail($agreement);
        $r = Rc::where('agreement_id', $a->id)->firstOrFail();
        $f = SubcontractLedger::forAgreement($a->id);
        $hist = DB::table('subcontract_historical_payments')->where('agreement_id', $a->id)->whereNull('deleted_at')->orderBy('kind')->orderBy('payment_date')->orderBy('id')->limit(500)->get(['id', 'kind', 'counted', 'payment_date', 'amount', 'currency', 'source_sheet', 'source_row', 'note', 'work_item_id']);

        return Inertia::render('Subcontracts/Reconciliation/Show', ['a' => ['id' => $a->id, 'reference' => $a->reference, 'title' => $a->title, 'contact' => optional($a->contact)->name, 'currency' => $a->currency, 'as_at' => optional($a->as_at_date)->toDateString()],
            'r' => $this->row($r, $a, $f), 'history' => $hist->map(fn ($h) => (array) $h)->values(), 'can' => ['approve' => $this->can('approve-reconciliation-subcontracts'), 'note' => $this->can('reconcile-subcontracts')]]);
    }

    public function note(Request $request, int $agreement)
    {
        $this->need('reconcile-subcontracts', 'approve-reconciliation-subcontracts');
        abort_unless($this->money(), 403, __('Reconciliation and the Excel import show contract and payment figures: you need the permission to see financial figures.'));
        $a = $this->agreementOrFail($agreement);
        $d = $request->validate(['note' => 'required|string|max:3000']);
        $r = Rc::where('agreement_id', $a->id)->firstOrFail();
        abort_unless($r->status === 'open', 422, __('This reconciliation is already approved.'));
        $r->update(['note' => trim($d['note'])]);
        log_activity('Subcontract reconciliation note on ' . $a->reference, ['note' => $r->note], $a, 'Subcontracts');

        return back()->with('message', __('Saved.'));
    }

    public function approve(Request $request, int $agreement)
    {
        $this->need('approve-reconciliation-subcontracts');
        abort_unless($this->money(), 403, __('Reconciliation and the Excel import show contract and payment figures: you need the permission to see financial figures.'));
        $a = $this->agreementOrFail($agreement);
        $d = $request->validate(['note' => 'required|string|max:3000']);
        $r = Rc::where('agreement_id', $a->id)->firstOrFail();
        abort_unless($r->status === 'open', 422, __('This reconciliation is already approved.'));
        $r->forceFill(['status' => 'approved', 'note' => trim($d['note']), 'approved_by_user_id' => $request->user()->id, 'approved_at' => now()])->save();
        log_activity('Subcontract reconciliation approved for ' . $a->reference, ['difference_note' => $r->note, 'source_paid' => (string) $r->source_paid, 'imported_paid' => (string) $r->imported_paid], $a, 'Subcontracts');

        return back()->with('message', __('The difference was approved with your note.'));
    }

    private function row(Rc $r, $a, ?array $f): array
    {
        $dpaid = bcsub((string) ($r->imported_paid ?? '0'), (string) ($r->source_paid ?? '0'), 2);
        $dcon = bcsub((string) ($r->imported_contract ?? '0'), (string) ($r->source_contract ?? '0'), 2);

        return ['id' => $r->id, 'agreement_id' => $r->agreement_id, 'agreement' => optional($a)->reference, 'title' => optional($a)->title, 'contact' => optional(optional($a)->contact)->name, 'currency' => optional($a)->currency, 'status' => $r->status, 'note' => $r->note,
            'source_contract' => $r->source_contract !== null ? (string) $r->source_contract : null, 'source_paid' => $r->source_paid !== null ? (string) $r->source_paid : null, 'source_balance' => $r->source_balance !== null ? (string) $r->source_balance : null, 'imported_contract' => (string) $r->imported_contract, 'imported_paid' => (string) $r->imported_paid,
            'dated_list' => (string) $r->dated_list_total, 'diff_contract' => $dcon, 'diff_paid' => $dpaid, 'dated_gap' => bcsub((string) $r->imported_paid, (string) ($r->dated_list_total ?? '0'), 2), 'wims_paid' => $f['paid_total'] ?? null, 'approved_at' => optional($r->approved_at)->toDateTimeString()];
    }
}
