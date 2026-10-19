<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\SubcontractAgreement as A;
use App\Services\Subcontracts\SubcontractLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** The subcontractor register: a Contact in the SubContractors category is the master record; this page adds what we owe and what we have paid. */
class RegisterController extends Controller
{
    use SubcontractHelpers, SortsLists;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->scalarQuery($request);
        $this->need('read-subcontracts');
        $search = trim((string) $request->query('search', ''));
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
        $mine = $this->visibleAgreements()->get(['id', 'contact_id', 'status'])->groupBy('contact_id');
        $q = Contact::query()->where('category', 'SubContractors')->when($search !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like)->orWhere('location', 'like', $like)->orWhereIn('id', fn ($sub) => $sub->select('contact_id')->from('subcontract_agreements')->whereNull('deleted_at')->where(fn ($ag) => $ag->where('title', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('site', 'like', $like)))))->orderBy('name');
        $money = $this->money();
        // one set of figures for every subcontractor that matches the search: the rows of this page and the summary cards above them
        $matched = (clone $q)->pluck('id');
        $allIds = $matched->flatMap(fn ($id) => $mine->get($id, collect())->pluck('id'))->all();
        $fig = $money ? SubcontractLedger::forAgreements($allIds) : [];
        $statuses = $matched->flatMap(fn ($id) => $mine->get($id, collect())->pluck('status'))->countBy();
        $summary = ['subcontractors' => $matched->count(), 'agreements' => (int) $statuses->except(A::ARCHIVED)->sum(), 'payable' => (int) collect(A::PAYABLE)->sum(fn ($st) => $statuses[$st] ?? 0), 'archived' => (int) ($statuses[A::ARCHIVED] ?? 0),
            'figures' => $money ? SubcontractLedger::foldByCurrency($fig) : [], 'archived_figures' => $money ? SubcontractLedger::foldByCurrency($fig, true) : []];
        [$sort, $dir] = $this->sortOf($request, array_merge(['name', 'contact', 'agreements'], $money ? ['revised', 'paid', 'outstanding'] : []));
        if ($sort !== '') {
            // ordered across ALL the matching subcontractors, then the page is cut out of that order
            $sum = fn ($c, $k) => $mine->get($c->id, collect())->sum(fn ($a) => (float) ($fig[$a->id][$k] ?? 0));
            $page = $this->sortedPage((clone $q)->get(), fn ($c, $col) => match ($col) {
                'name' => $c->name, 'contact' => $c->phone ?: $c->email, 'agreements' => $mine->get($c->id, collect())->where('status', '<>', A::ARCHIVED)->count(),
                'revised' => $sum($c, 'revised'), 'paid' => $sum($c, 'paid_total'), 'outstanding' => $sum($c, 'commitment_left'), default => null,
            }, $sort, $dir, $request, 20);
        } else {
            $page = (clone $q)->paginate(20)->withQueryString();
        }
        $page->through(function ($c) use ($mine, $fig, $money) {
            $ags = $mine->get($c->id, collect());
            $by = $money ? SubcontractLedger::foldByCurrency(array_intersect_key($fig, array_flip($ags->pluck('id')->all()))) : [];

            return ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'location' => $c->location, 'agreements' => $ags->where('status', '<>', A::ARCHIVED)->count(), 'active' => $ags->whereIn('status', A::PAYABLE)->count(), 'archived' => $ags->where('status', A::ARCHIVED)->count(), 'figures' => $by,
                'archived_figures' => $money ? SubcontractLedger::foldByCurrency(array_intersect_key($fig, array_flip($ags->pluck('id')->all())), true) : []];
        });

        return Inertia::render('Subcontracts/Register/Index', ['summary' => $summary, 'rows' => $page, 'filters' => ['sort' => $sort, 'dir' => $sort !== '' ? $dir : '', 'search' => $search], 'search' => $search, 'money' => $money, 'newContactUrl' => $this->can('create-contacts') ? route('contacts.create', ['category' => 'SubContractors']) : null]);
    }

    public function show(int $contact)
    {
        $this->need('read-subcontracts');
        $c = Contact::query()->where('category', 'SubContractors')->findOrFail($contact);
        $money = $this->money();
        $ags = $this->visibleAgreements()->where('contact_id', $c->id)->with(['warehouse:id,name', 'administrator:id,name'])->orderByDesc('id')->get();
        $fig = $money ? SubcontractLedger::forAgreements($ags->pluck('id')->all()) : [];
        $ids = $ags->pluck('id');
        $requests = \App\Models\SubcontractPaymentRequest::query()->whereIn('agreement_id', $ids)->orderByDesc('id')->limit(30)->get();
        $variations = \App\Models\SubcontractVariation::query()->whereIn('agreement_id', $ids)->orderByDesc('id')->limit(30)->get();
        $vouchers = DB::table('subcontract_payments as p')->join('payment_vouchers as v', 'v.id', '=', 'p.payment_voucher_id')->whereIn('p.agreement_id', $ids)->whereNull('p.deleted_at')->where('p.status', '<>', 'cancelled')
            ->orderByDesc('p.id')->limit(30)->get(['v.id', 'v.pv_number', 'v.status', 'v.paid_at', 'p.amount_tzs', 'p.agreement_id']);
        $agRef = $ags->pluck('reference', 'id');
        $agCur = $ags->pluck('currency', 'id');

        return Inertia::render('Subcontracts/Register/Show', [
            'c' => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'location' => $c->location, 'details' => $c->details, 'status' => $c->deleted_at ? 'Inactive' : 'Active', 'contact_url' => $this->can('update-contacts') ? route('contacts.edit', $c->id) : null],
            'totals' => $money ? SubcontractLedger::foldByCurrency($fig) : null, 'archived_totals' => $money ? SubcontractLedger::foldByCurrency($fig, true) : null,
            'agreements' => $ags->map(fn ($a) => ['id' => $a->id, 'reference' => $a->reference, 'title' => $a->title, 'status' => $a->status, 'project' => optional($a->warehouse)->name, 'site' => $a->site, 'currency' => $a->currency, 'figures' => $fig[$a->id] ?? null, 'administrator' => optional($a->administrator)->name,
                'expected_completion' => optional($a->expected_completion)->toDateString(), 'source' => $a->source])->values(),
            'requests' => $requests->map(fn ($r) => ['id' => $r->id, 'reference' => $r->reference, 'agreement' => $agRef[$r->agreement_id] ?? '', 'date' => optional($r->request_date)->toDateString(), 'status' => $r->status, 'currency' => $r->currency, 'requested' => $this->m($r->requested_amount), 'approved' => $this->m($r->approved_amount)])->values(),
            'variations' => $variations->map(fn ($v) => ['id' => $v->id, 'reference' => $v->reference, 'agreement' => $agRef[$v->agreement_id] ?? '', 'title' => $v->title, 'direction' => $v->direction, 'amount' => $this->m($v->amount), 'currency' => $agCur[$v->agreement_id] ?? 'TZS', 'status' => $v->status])->values(),
            'vouchers' => $money ? $vouchers->groupBy('id')->map(fn ($g) => ['id' => $g[0]->id, 'pv' => $g[0]->pv_number, 'status' => $g[0]->paid_at && $g[0]->status === 'Authorised' ? 'Paid' : $g[0]->status, 'amount' => $g->reduce(fn ($t, $v) => bcadd($t, (string) $v->amount_tzs, 2), '0.00'),
                'agreement' => $g->map(fn ($v) => $agRef[$v->agreement_id] ?? '')->unique()->implode(', '), 'paid_at' => $g[0]->paid_at ? substr((string) $g[0]->paid_at, 0, 10) : null])->values() : [], // one row per voucher (a contract payment voucher pays several agreements: its parts are added)
            'money' => $money, 'can' => ['create_agreement' => $this->can('create-subcontract-agreements'), 'statement' => $this->can('read-subcontract-reports') || $this->can('print-subcontract-reports'), 'print' => $this->can('print-subcontract-reports'), 'vouchers' => $this->can('read-payment-vouchers')],
        ]);
    }
}
