<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\SubcontractClaim as C;
use App\Models\SubcontractClaimLine as L;
use App\Models\SubcontractPayment as P;
use App\Models\SubcontractPaymentRequest as R;
use App\Services\Subcontracts\SubcontractAmounts;
use App\Services\Subcontracts\SubcontractClaims;
use App\Services\Subcontracts\SubcontractLedger;
use App\Services\Subcontracts\SubcontractReports;
use App\Services\Subcontracts\SubcontractSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Contract Payment Requests (claims): one request to pay a subcontractor for several completed agreements, decided line by line, paid by ONE Payment Voucher that allocates to
 * each agreement. Every rule lives in App\Services\Subcontracts\SubcontractClaims; this controller only checks abilities, shapes the pages and calls it.
 */
class ClaimController extends Controller
{
    use ExportsReports;
    use SortsLists;
    use SubcontractHelpers;

    public function __construct()
    {
        $this->setAbilities([]); // every action is checked by name below
    }

    // ---- visibility ----------------------------------------------------------------------------------------------------------------------

    /** Claims the user may open: their own, or those whose agreements they may all see (Super Admins and people who see everything: all). */
    private function visible()
    {
        $u = auth()->user();
        $q = C::query();
        if ($u->hasRole('Super Admin') || $u->view_all) {
            return $q;
        }
        $ok = $this->visibleAgreements()->select('id');

        return $q->where(fn ($w) => $w->where('requested_by_user_id', $u->id)->orWhere(fn ($x) => $x
            ->whereExists(fn ($e) => $e->from('subcontract_claim_lines as vl')->whereColumn('vl.claim_id', 'subcontract_claims.id')->whereNull('vl.deleted_at')->whereIn('vl.agreement_id', $ok))
            ->whereNotExists(fn ($e) => $e->from('subcontract_claim_lines as nl')->whereColumn('nl.claim_id', 'subcontract_claims.id')->whereNull('nl.deleted_at')->where('nl.status', '<>', L::CANCELLED)->whereNotIn('nl.agreement_id', $ok))));
    }

    private function find(int $id): C
    {
        return $this->visible()->findOrFail($id);
    }

    private function owner(C $c): bool
    {
        $u = auth()->user();

        return (int) $c->requested_by_user_id === (int) $u->id || $u->hasRole('Super Admin');
    }

    /** The maker-checker rule as the pages show it: the person who made or submitted the claim may not decide it (a Super Admin may; it is logged). */
    private function selfBlocked(C $c): bool
    {
        return $this->selfDecision($c->requested_by_user_id) || $this->selfDecision($c->submitted_by_user_id);
    }

    // ---- list ----------------------------------------------------------------------------------------------------------------------------

    public function index(Request $request)
    {
        $this->scalarQuery($request);
        $this->need('read-subcontract-claims');
        $f = ['search' => trim((string) $request->query('search', '')), 'status' => (string) $request->query('status', ''), 'contact_id' => (string) $request->query('contact_id', ''), 'warehouse_id' => (string) $request->query('warehouse_id', ''),
            'currency' => (string) $request->query('currency', ''), 'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', '')];
        $like = fn (string $v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
        $q = $this->visible()->with(['contact:id,name', 'warehouse:id,name', 'requester:id,name'])->withCount(['lines as lines_count' => fn ($l) => $l->where('status', '<>', L::CANCELLED), 'lines as pending_count' => fn ($l) => $l->where('status', L::PENDING)])
            ->when(in_array($f['status'], C::STATUSES, true), fn ($q) => $q->where('status', $f['status']))->when(ctype_digit($f['contact_id']) && $f['contact_id'] !== '', fn ($q) => $q->where('contact_id', (int) $f['contact_id']))
            ->when(ctype_digit($f['warehouse_id']) && $f['warehouse_id'] !== '', fn ($q) => $q->where('warehouse_id', (int) $f['warehouse_id']))->when($f['currency'] !== '', fn ($q) => $q->where('currency', mb_substr($f['currency'], 0, 3)))
            ->when($f['from'] !== '' && strtotime($f['from']), fn ($q) => $q->whereDate('request_date', '>=', $f['from']))->when($f['to'] !== '' && strtotime($f['to']), fn ($q) => $q->whereDate('request_date', '<=', $f['to']))
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', $like($f['search']))->orWhere('description', 'like', $like($f['search']))->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $like($f['search'])))
                ->orWhereIn('id', L::query()->whereIn('agreement_id', \App\Models\SubcontractAgreement::query()->where('reference', 'like', $like($f['search']))->orWhere('title', 'like', $like($f['search']))->select('id'))->select('claim_id'))));
        [$sort, $dir] = $this->sortOf($request, array_merge(['reference', 'contact', 'project', 'date', 'status', 'lines'], $this->money() ? ['requested', 'approved'] : []));
        if ($sort !== '') {
            $rows = $this->sortedPage((clone $q)->get(), fn ($c, $col) => match ($col) {
                'reference' => $c->reference, 'contact' => optional($c->contact)->name, 'project' => optional($c->warehouse)->name, 'date' => optional($c->request_date)->toDateString(), 'status' => $c->status, 'lines' => $c->lines_count,
                'requested' => $c->requested_total, 'approved' => $c->approved_total, default => null,
            }, $sort, $dir, $request, 20);
        } else {
            $rows = (clone $q)->orderByDesc('id')->paginate(20)->withQueryString();
        }
        $f['sort'] = $sort;
        $f['dir'] = $sort !== '' ? $dir : '';
        $fig = SubcontractClaims::figures($rows->getCollection()->pluck('id')->all());
        $rows->through(fn ($c) => $this->row($c, $fig['claims'][$c->id] ?? null));
        $counts = $this->visible()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $by = $this->money() ? $this->visible()->whereNotIn('status', [C::DRAFT, C::CANCELLED])->selectRaw('currency, SUM(requested_total) requested, SUM(approved_total) approved, COUNT(*) n')->groupBy('currency')->get()
            ->mapWithKeys(fn ($r) => [$r->currency => ['requested' => SubcontractAmounts::money((string) $r->requested), 'approved' => SubcontractAmounts::money((string) $r->approved), 'claims' => (int) $r->n]])->all() : null;

        return Inertia::render('Subcontracts/Claims/Index', ['rows' => $rows, 'filters' => $f, 'statuses' => collect(C::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => __($s)])->values(), 'counts' => $counts,
            'stats' => ['waiting' => $this->visible()->whereExists(fn ($e) => $e->from('subcontract_claim_lines as wl')->whereColumn('wl.claim_id', 'subcontract_claims.id')->whereNull('wl.deleted_at')->where('wl.status', L::PENDING))->count(), 'by_currency' => $by],
            'contacts' => $this->subcontractors(), 'projects' => $this->projects(), 'currencies' => collect(SubcontractSettings::get()['currencies'])->map(fn ($c) => ['value' => $c, 'label' => $c])->values(), 'money' => $this->money(),
            'can' => ['create' => $this->can('create-subcontract-claims'), 'decide' => $this->can('decide-subcontract-claims'), 'print' => $this->can('print-subcontract-reports')]]);
    }

    public function row(C $c, ?array $fig = null): array
    {
        return ['id' => $c->id, 'reference' => $c->reference, 'contact_id' => $c->contact_id, 'contact' => optional($c->contact)->name, 'warehouse_id' => $c->warehouse_id, 'project' => optional($c->warehouse)->name, 'currency' => $c->currency, 'date' => optional($c->request_date)->toDateString(),
            'status' => $c->status, 'status_label' => __($c->status), 'lines' => (int) ($c->lines_count ?? $c->lines()->where('status', '<>', L::CANCELLED)->count()), 'pending' => (int) ($c->pending_count ?? 0), 'requested' => $this->m($c->requested_total), 'approved' => $this->m($c->approved_total),
            'paid' => $this->m($fig['paid'] ?? null), 'left_to_pay' => $this->m($fig['left'] ?? null), 'progress' => $fig['progress'] ?? null, 'by' => optional($c->requester)->name, 'url' => route('subcontracts.claims.show', $c->id)];
    }

    // ---- create / edit a draft -------------------------------------------------------------------------------------------------------------

    private function formProps(?C $c, Request $request): array
    {
        $lines = $c ? $c->lines()->get() : collect();
        $money = $this->money();
        $agrs = DB::table('subcontract_agreements')->whereIn('id', $lines->pluck('agreement_id')->all() ?: [0])->get(['id', 'reference', 'title'])->keyBy('id');

        return ['claim' => $c ? ['id' => $c->id, 'reference' => $c->reference, 'status' => $c->status, 'contact_id' => $c->contact_id, 'warehouse_id' => $c->warehouse_id, 'currency' => $c->currency, 'request_date' => optional($c->request_date)->toDateString(), 'description' => $c->description,
            'lines' => $lines->map(fn ($l) => ['agreement_id' => $l->agreement_id, 'reference' => $agrs[$l->agreement_id]->reference ?? null, 'title' => $agrs[$l->agreement_id]->title ?? null, 'amount' => $money ? (string) $l->requested_amount : null, 'note' => $l->note])->values()] : null,
            'contacts' => $this->subcontractors(), 'projects' => $this->projects(), 'eligible_url' => route('subcontracts.claims.eligible'), 'money' => $money, 'today' => now()->toDateString(), 'attachments' => $c ? $this->filesOf($c) : [],
            'preset' => ['contact_id' => $request->filled('contact_id') ? (int) $request->query('contact_id') : null, 'warehouse_id' => $request->filled('warehouse_id') ? (int) $request->query('warehouse_id') : null],
            'claimSettings' => ['require_verification' => SubcontractClaims::requiresVerification(), 'require_evidence' => SubcontractClaims::requiresEvidence(), 'allow_partial' => SubcontractClaims::allowsPartial()]];
    }

    public function create(Request $request)
    {
        $this->need('create-subcontract-claims');

        return Inertia::render('Subcontracts/Claims/Form', $this->formProps(null, $request));
    }

    public function edit(Request $request, int $claim)
    {
        $this->need('create-subcontract-claims');
        $c = $this->find($claim);
        abort_unless($this->owner($c), 403);
        abort_unless($c->status === C::DRAFT, 422, __('Only a draft claim can be changed.'));

        return Inertia::render('Subcontracts/Claims/Form', $this->formProps($c, $request));
    }

    /** The agreements that can be claimed for a subcontractor (and project): the rows of the claim form's table. JSON. */
    public function eligible(Request $request)
    {
        $this->need('create-subcontract-claims', 'read-subcontract-claims');
        $d = $request->validate(['contact_id' => 'required|integer', 'warehouse_id' => 'nullable|integer', 'currency' => 'nullable|string|size:3', 'all' => 'nullable|boolean']);
        $rows = SubcontractClaims::eligibleAgreements((int) $d['contact_id'], ! empty($d['warehouse_id']) ? (int) $d['warehouse_id'] : null, $request->user(), (bool) ($d['all'] ?? false), $d['currency'] ?? null);
        // the original amount is shown next to the revised value in the claim form's table (money viewers only)
        $orig = $this->money() ? DB::table('subcontract_agreements')->whereIn('id', array_column($rows, 'agreement_id') ?: [0])->pluck('original_amount', 'id') : collect();
        $rows = array_map(fn ($r) => $r + ['original' => $this->money() ? SubcontractAmounts::money((string) ($orig[$r['agreement_id']] ?? '0')) : null], $rows);

        return response()->json(['rows' => $rows, 'currencies' => collect($rows)->pluck('currency')->unique()->values(), 'money' => $this->money(), 'settings' => ['require_verification' => SubcontractClaims::requiresVerification(), 'require_evidence' => SubcontractClaims::requiresEvidence(), 'allow_partial' => SubcontractClaims::allowsPartial()]]);
    }

    private function rules(): array
    {
        return ['contact_id' => 'required|integer', 'warehouse_id' => 'nullable|integer|exists:warehouses,id', 'request_date' => 'required|date', 'description' => 'nullable|string|max:3000',
            'lines' => 'required|array|min:1|max:200', 'lines.*.agreement_id' => 'required|integer', 'lines.*.amount' => 'nullable', 'lines.*.note' => 'nullable|string|max:1000'] + $this->fileRules();
    }

    public function store(Request $request)
    {
        $this->need('create-subcontract-claims');
        $d = $request->validate($this->rules());
        $c = SubcontractClaims::saveDraft(null, $d, $request->user());
        $this->saveFiles($c, $request);
        log_activity('Subcontract claim ' . $c->reference . ' created', ['contact' => $c->contact_id, 'project' => optional($c->warehouse)->name, 'currency' => $c->currency, 'requested_total' => (string) $c->requested_total, 'lines' => $c->lines()->count()], $c, 'Subcontracts');

        return redirect()->route('subcontracts.claims.show', $c->id)->with('message', __(':r saved as a draft.', ['r' => $c->reference]));
    }

    public function update(Request $request, int $claim)
    {
        $this->need('create-subcontract-claims');
        $c = $this->find($claim);
        abort_unless($this->owner($c), 403);
        $d = $request->validate($this->rules());
        $c = SubcontractClaims::saveDraft($c, $d, $request->user());
        $this->saveFiles($c, $request);
        log_activity('Subcontract claim ' . $c->reference . ' updated', ['requested_total' => (string) $c->requested_total, 'lines' => $c->lines()->count()], $c, 'Subcontracts');

        return redirect()->route('subcontracts.claims.show', $c->id)->with('message', __('Saved.'));
    }

    public function destroy(int $claim)
    {
        $this->need('create-subcontract-claims');
        $c = $this->find($claim);
        abort_unless($this->owner($c), 403);
        SubcontractClaims::deleteDraft($c);
        log_activity('Subcontract claim ' . $c->reference . ' deleted', [], $c, 'Subcontracts');

        return redirect()->route('subcontracts.claims.index')->with('message', __(':r deleted.', ['r' => $c->reference]));
    }

    // ---- one claim -----------------------------------------------------------------------------------------------------------------------

    public function show(int $claim)
    {
        $this->need('read-subcontract-claims');
        $c = $this->find($claim)->load(['contact', 'warehouse', 'requester:id,name']);
        $money = $this->money();
        SubcontractClaims::sweepVoid($c->id);
        $lines = $c->lines()->with(['agreement:id,reference,title,currency,status,warehouse_id', 'decider:id,name'])->get();
        $fig = SubcontractClaims::figures([$c->id]);
        $agFig = $money ? SubcontractLedger::forAgreements($lines->pluck('agreement_id')->all()) : [];
        $reqs = R::query()->whereIn('id', $lines->pluck('request_id')->filter()->all() ?: [0])->get(['id', 'reference', 'status'])->keyBy('id');
        $payments = P::query()->where('claim_id', $c->id)->orderBy('id')->get();
        $vouchers = DB::table('payment_vouchers')->whereIn('id', $payments->pluck('payment_voucher_id')->filter()->unique()->all() ?: [0])->get(['id', 'pv_number', 'status', 'paid_at', 'deleted_at'])->keyBy('id');
        $me = auth()->user();
        $blocked = $this->selfBlocked($c);
        $own = $this->owner($c);
        $canDecide = $this->can('decide-subcontract-claims');
        $canReview = $this->can('review-subcontract-claims');
        $overrideOk = $this->can('override-subcontract-limits') && (bool) SubcontractSettings::get()['override_allowed'];
        $open = SubcontractClaims::openPayments($c->id)->get();
        $payable = $this->can('initiate-subcontract-vouchers') && $open->isEmpty() && in_array($c->status, [C::APPROVED, C::PARTIAL], true);

        $lineRows = $lines->map(function ($l) use ($money, $fig, $agFig, $reqs, $payments, $vouchers, $blocked, $own, $canDecide, $canReview, $payable, $c) {
            $rf = $fig['lines'][$l->id] ?? null;
            $decidable = in_array($l->status, [L::PENDING, L::DEFERRED], true) && ! $blocked;
            $myPays = $payments->where('claim_line_id', $l->id);
            $af = $agFig[$l->agreement_id] ?? null;
            $hist = $l->snapshot['history'] ?? [];

            return ['id' => $l->id, 'agreement_id' => $l->agreement_id, 'agreement' => optional($l->agreement)->reference, 'title' => optional($l->agreement)->title, 'agreement_status' => optional($l->agreement)->status, 'agreement_url' => route('subcontracts.agreements.show', $l->agreement_id),
                'status' => $l->status, 'status_label' => __($l->status), 'requested' => $this->m($l->requested_amount), 'approved' => $this->m($l->approved_amount), 'decision_reason' => $l->decision_reason, 'decided_by' => optional($l->decider)->name, 'decided_at' => optional($l->decided_at)->toDateTimeString(),
                'note' => $l->note, 'override' => (bool) $l->override, 'override_reason' => $l->override_reason, 'request_id' => $l->request_id, 'request' => optional($reqs[$l->request_id] ?? null)->reference, 'request_status' => optional($reqs[$l->request_id] ?? null)->status,
                'request_url' => $l->request_id ? route('subcontracts.requests.show', $l->request_id) : null, 'snapshot' => $money ? \Illuminate\Support\Arr::only($l->snapshot ?? [], ['revised', 'paid_total', 'pending', 'approved_unpaid', 'approved_work', 'remaining_eligible', 'at']) : null, 'history' => $hist,
                'figures' => $money && $af ? ['revised' => $af['revised'], 'paid_total' => $af['paid_total'], 'approved_unpaid' => $af['approved_unpaid'], 'headroom' => $af['headroom'], 'remaining_eligible' => $af['remaining_eligible'], 'remaining_eligible_signed' => $af['remaining_eligible_signed'], 'commitment_left' => $af['commitment_left']] : null,
                'payment' => $rf ? ['approved' => $this->m($rf['approved']), 'paid' => $this->m($rf['paid']), 'in_process' => $this->m($rf['in_process']), 'left' => $this->m($rf['left']), 'progress' => $rf['progress']] : null,
                'vouchers' => $myPays->map(function ($p) use ($vouchers, $money) {
                    $v = $p->payment_voucher_id ? ($vouchers[$p->payment_voucher_id] ?? null) : null;
                    $paid = SubcontractLedger::voucherIsPaid($v);

                    return ['payment_id' => $p->id, 'voucher_id' => $v->id ?? null, 'voucher' => $v->pv_number ?? null, 'voucher_status' => $v ? ($paid ? 'Paid' : ($v->deleted_at ? 'Deleted' : $v->status)) : ($p->status === 'cancelled' ? 'Cancelled' : 'Voucher not created yet'), 'paid' => $paid,
                        'paid_at' => $v && $v->paid_at ? substr((string) $v->paid_at, 0, 10) : null, 'amount' => $money ? (string) $p->amount : null, 'amount_tzs' => $money ? (string) $p->amount_tzs : null, 'cancelled' => $p->status === 'cancelled'];
                })->values(),
                'can' => ['approve' => $decidable && $canDecide, 'reject' => $decidable && $canDecide, 'defer' => $l->status === L::PENDING && ! $blocked && $canDecide, 'return' => $decidable && ($canDecide || $canReview),
                    'resubmit' => $l->status === L::RETURNED && $own && $this->can('submit-subcontract-claims'), 'cancel' => in_array($l->status, [L::PENDING, L::RETURNED, L::DEFERRED], true) && ($own || $canDecide), 'reverse' => $l->status === L::APPROVED && $canDecide,
                    'pay' => $payable && $l->status === L::APPROVED && $rf && bccomp($rf['left'], '0', 2) > 0 && optional($l->agreement)->status !== null && in_array($l->agreement->status, \App\Models\SubcontractAgreement::PAYABLE, true)],
                'self_blocked' => $blocked];
        })->values();

        $voucherRows = $payments->where('status', '<>', 'cancelled')->whereNotNull('payment_voucher_id')->groupBy('payment_voucher_id')->map(function ($set, $vid) use ($vouchers, $money) {
            $v = $vouchers[$vid] ?? null;
            $paid = SubcontractLedger::voucherIsPaid($v);

            return ['id' => (int) $vid, 'pv_number' => $v->pv_number ?? null, 'status' => $v ? ($paid ? 'Paid' : ($v->deleted_at ? 'Deleted' : $v->status)) : 'Deleted', 'paid' => $paid, 'paid_at' => $v && $v->paid_at ? substr((string) $v->paid_at, 0, 10) : null,
                'total_tzs' => $money ? $set->reduce(fn ($t, $p) => bcadd($t, (string) $p->amount_tzs, 2), '0.00') : null, 'url' => $this->can('read-payment-vouchers') ? route('payment-vouchers.show', $vid) : null,
                'allocations' => SubcontractClaims::allocationsOfVoucher((int) $vid, $money)];
        })->values();

        return Inertia::render('Subcontracts/Claims/Show', [
            'claim' => $this->row($c, $fig['claims'][$c->id] ?? null) + ['description' => $c->description, 'submitted_by' => optional(\App\Models\User::find($c->submitted_by_user_id))->name, 'submitted_at' => optional($c->submitted_at)->toDateTimeString(),
                'reviewed_by' => optional(\App\Models\User::find($c->reviewed_by_user_id))->name, 'reviewed_at' => optional($c->reviewed_at)->toDateTimeString(), 'in_process' => $this->m($fig['claims'][$c->id]['in_process'] ?? null), 'contact_url' => $this->can('read-subcontracts') ? route('subcontracts.register.show', $c->contact_id) : null],
            'lines' => $lineRows, 'vouchers' => $voucherRows, 'open_payment' => $open->isEmpty() ? null : ['count' => $open->count(), 'total_tzs' => $money ? $open->reduce(fn ($t, $p) => bcadd($t, (string) $p->amount_tzs, 2), '0.00') : null, 'voucher_url' => route('payment-vouchers.create', ['subcontract_claim' => $c->id])],
            'attachments' => $this->filesOf($c), 'history' => $this->can('view-audit-subcontracts') ? $this->safeHistory(C::class, $c->id) : [], 'money' => $money, 'claimSettings' => ['override_allowed' => (bool) SubcontractSettings::get()['override_allowed'], 'allow_partial' => SubcontractClaims::allowsPartial()],
            'currency_is_tzs' => $c->currency === 'TZS', 'today' => now()->toDateString(), 'actions' => SubcontractClaims::LINE_ACTIONS,
            'can' => ['edit' => $c->status === C::DRAFT && $own && $this->can('create-subcontract-claims'), 'delete' => $c->status === C::DRAFT && $own && $this->can('create-subcontract-claims'), 'submit' => $c->status === C::DRAFT && $own && $this->can('submit-subcontract-claims'),
                'cancel' => $lines->contains(fn ($l) => in_array($l->status, [L::DRAFT, L::PENDING, L::RETURNED, L::DEFERRED], true)) && ($own || $canDecide) && ($this->can('create-subcontract-claims') || $canDecide), 'review' => in_array($c->status, [C::SUBMITTED, C::REVIEW, C::PARTIAL], true) && $canReview && ! $blocked,
                'decide' => $canDecide && ! $blocked, 'override' => $overrideOk, 'voucher' => $payable && $lineRows->contains(fn ($l) => $l['can']['pay']), 'cancel_payment' => $this->can('initiate-subcontract-vouchers') && $open->isNotEmpty(), 'attach' => $this->can('create-subcontract-claims') && $c->status === C::DRAFT,
                'documents' => $this->can('view-documents-subcontracts'), 'print' => $this->can('print-subcontract-reports'), 'vouchers' => $this->can('read-payment-vouchers')],
        ]);
    }

    // ---- workflow ------------------------------------------------------------------------------------------------------------------------

    public function submit(int $claim)
    {
        $this->need('submit-subcontract-claims');
        $c = $this->find($claim);
        abort_unless($this->owner($c), 403);
        $c = SubcontractClaims::submit($c->id, auth()->user());

        return back()->with('message', __(':r is now :s.', ['r' => $c->reference, 's' => strtolower(__($c->status))]));
    }

    public function cancel(Request $request, int $claim)
    {
        $this->need('create-subcontract-claims', 'decide-subcontract-claims');
        $c = $this->find($claim);
        abort_unless($this->owner($c) || $this->can('decide-subcontract-claims'), 403);
        $c = SubcontractClaims::cancel($c->id, $request->user(), (string) $request->input('reason', ''));

        return back()->with('message', __(':r is now :s.', ['r' => $c->reference, 's' => strtolower(__($c->status))]));
    }

    public function review(int $claim)
    {
        $this->need('review-subcontract-claims');
        $c = SubcontractClaims::review($this->find($claim)->id, auth()->user());

        return back()->with('message', __(':r is taken into review.', ['r' => $c->reference]));
    }

    /** Several line decisions at once (approve some, reject others): all or nothing. Body: decisions[] = {line_id, action, amount?, reason?, override_reason?}. */
    public function decide(Request $request, int $claim)
    {
        $this->need('decide-subcontract-claims', 'review-subcontract-claims');
        $c = $this->find($claim);
        $d = $request->validate(['decisions' => 'required|array|min:1|max:200', 'decisions.*.line_id' => 'required|integer', 'decisions.*.action' => 'required|in:' . implode(',', SubcontractClaims::LINE_ACTIONS), 'decisions.*.amount' => 'nullable',
            'decisions.*.reason' => 'nullable|string|max:1000', 'decisions.*.override_reason' => 'nullable|string|max:1000']);
        foreach ($d['decisions'] as $x) {
            // return needs the review OR the decide ability; approve / reject / defer need decide
            abort_unless($x['action'] === 'return' ? ($this->can('decide-subcontract-claims') || $this->can('review-subcontract-claims')) : $this->can('decide-subcontract-claims'), 403);
        }
        $r = SubcontractClaims::decide($c->id, $d['decisions'], $request->user());

        return back()->with('message', __(':r is now :s.', ['r' => $r['claim']->reference, 's' => strtolower(__($r['claim']->status))]));
    }

    /** One line: approve | reject | return | defer. Body: amount?, reason?, override_reason?. */
    public function lineDecide(Request $request, int $claim, int $line, string $action)
    {
        $this->need('decide-subcontract-claims', 'review-subcontract-claims');
        abort_unless($action === 'return' ? ($this->can('decide-subcontract-claims') || $this->can('review-subcontract-claims')) : $this->can('decide-subcontract-claims'), 403);
        $c = $this->find($claim);
        $d = $request->validate(['amount' => 'nullable', 'reason' => 'nullable|string|max:1000', 'override_reason' => 'nullable|string|max:1000']);
        $r = SubcontractClaims::decide($c->id, [['line_id' => $line, 'action' => $action] + $d], $request->user());

        return back()->with('message', __(':r is now :s.', ['r' => $r['claim']->reference, 's' => strtolower(__($r['claim']->status))]));
    }

    public function lineResubmit(Request $request, int $claim, int $line)
    {
        $this->need('submit-subcontract-claims');
        $c = $this->find($claim);
        abort_unless($this->owner($c), 403);
        $d = $request->validate(['amount' => 'nullable', 'note' => 'nullable|string|max:1000']);
        $c = SubcontractClaims::resubmitLine($c->id, $line, $d['amount'] ?? null, $d['note'] ?? null, $request->user());

        return back()->with('message', __('The line was corrected and sent again.'));
    }

    public function lineCancel(Request $request, int $claim, int $line)
    {
        $this->need('create-subcontract-claims', 'decide-subcontract-claims');
        $c = $this->find($claim);
        abort_unless($this->owner($c) || $this->can('decide-subcontract-claims'), 403);
        SubcontractClaims::cancelLine($c->id, $line, $request->user(), (string) $request->input('reason', ''));

        return back()->with('message', __('The line was cancelled.'));
    }

    public function lineReverse(Request $request, int $claim, int $line)
    {
        $this->need('decide-subcontract-claims');
        $c = $this->find($claim);
        SubcontractClaims::reverseLine($c->id, $line, $request->user(), (string) $request->input('reason', ''));

        return back()->with('message', __('The approval of the line was taken back.'));
    }

    // ---- paying --------------------------------------------------------------------------------------------------------------------------

    /** Start ONE Payment Voucher for several approved lines. Body: lines = {line_id: amount|''}, exchange_rate?, rate_date?. Redirects to the voucher form pre-filled. */
    public function startVoucher(Request $request, int $claim)
    {
        $this->need('initiate-subcontract-vouchers');
        $this->need('read-subcontract-claims');
        $c = $this->find($claim);
        $d = $request->validate(['lines' => 'required|array|min:1|max:200', 'lines.*' => 'nullable', 'exchange_rate' => 'nullable|numeric|regex:/^\d+(\.\d+)?$/|min:0.000001|max:99999999', 'rate_date' => 'nullable|date']);
        $r = SubcontractClaims::startVoucher($c->id, $d['lines'], isset($d['exchange_rate']) ? (string) $d['exchange_rate'] : null, $d['rate_date'] ?? null, $request->user());

        return redirect()->route('payment-vouchers.create', ['subcontract_claim' => $r['claim']->id]);
    }

    public function cancelPayments(int $claim)
    {
        $this->need('initiate-subcontract-vouchers');
        $this->need('read-subcontract-claims');
        $c = $this->find($claim);
        SubcontractClaims::cancelOpenPayments($c->id, auth()->user());

        return back()->with('message', __('The payment was cancelled.'));
    }

    // ---- documents ------------------------------------------------------------------------------------------------------------------------

    public function attach(Request $request, int $claim)
    {
        $this->need('create-subcontract-claims');
        $c = $this->find($claim);
        abort_unless($this->owner($c), 403);
        abort_unless($c->status === C::DRAFT, 422, __('Documents can be added to a claim only while it is a draft.'));
        $request->validate(['attachments' => 'required|array|min:1|max:10', 'attachments.*' => 'file|max:8192|mimes:' . self::FILE_TYPES]);
        $n = $this->saveFiles($c, $request);
        log_activity('Subcontract claim ' . $c->reference . ' document added', ['files' => $n], $c, 'Subcontracts');

        return back()->with('message', trans_choice(':n document attached.|:n documents attached.', $n, ['n' => $n]));
    }

    public function download(int $claim, int $file)
    {
        $this->need('read-subcontract-claims');

        return $this->downloadFile($this->find($claim), $file, 'Subcontract claim');
    }

    public function removeFile(int $claim, int $file)
    {
        $this->need('create-subcontract-claims');
        $c = $this->find($claim);
        abort_unless($this->owner($c) && $c->status === C::DRAFT, 403);
        $this->removeFileOf($c, $file, 'Subcontract claim');

        return back()->with('message', __('Document removed.'));
    }

    // ---- print ---------------------------------------------------------------------------------------------------------------------------

    private function printable(int $claim, string $format): array
    {
        $this->need('read-subcontract-claims');
        $this->need('print-subcontract-reports');
        $this->need('view-financials-subcontracts'); // the claim document is a financial document
        $c = $this->find($claim);
        $r = SubcontractReports::build(auth()->user(), 'claim_detail', ['claim_id' => $c->id], SubcontractReports::PDF_LIMIT, true);
        $this->logExport($r, $format, $c->reference);

        return [$r, $c];
    }

    public function print(int $claim)
    {
        [$r, $c] = $this->printable($claim, 'print');

        return $this->pdfOf($r)->stream('subcontract-claim-' . $c->reference . '.pdf');
    }

    public function pdf(int $claim)
    {
        [$r, $c] = $this->printable($claim, 'pdf');

        return $this->pdfOf($r)->download('subcontract-claim-' . $c->reference . '.pdf');
    }
}
