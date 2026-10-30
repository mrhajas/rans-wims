<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\SubcontractAgreement as A;
use App\Models\SubcontractPayment;
use App\Models\SubcontractPaymentAllocation;
use App\Models\SubcontractPaymentRequest as R;
use App\Models\SubcontractRequestItem;
use App\Models\SubcontractWorkItem;
use App\Services\Subcontracts\SubcontractAlerts;
use App\Services\Subcontracts\SubcontractAmounts;
use App\Services\Subcontracts\SubcontractClaims;
use App\Services\Subcontracts\SubcontractFields;
use App\Services\Subcontracts\SubcontractLedger;
use App\Services\Subcontracts\SubcontractNotifier;
use App\Services\Subcontracts\SubcontractSettings;
use App\Support\ReferenceLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Payment requests. A request is asked, reviewed and approved here; it is PAID only through a Payment Voucher (the existing module), which is started from an approved
 * request and carries the amount and the link back. Nothing in this controller moves money.
 */
class PaymentRequestController extends Controller
{
    use SortsLists;

    use SubcontractHelpers;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function visible()
    {
        return R::query()->whereIn('agreement_id', $this->visibleAgreements()->select('id'));
    }

    private function find(int $id): R
    {
        return $this->visible()->findOrFail($id);
    }

    public function index(Request $request)
    {
        $this->scalarQuery($request);
        $this->need('read-subcontract-requests');
        $f = ['search' => trim((string) $request->query('search', '')), 'status' => (string) $request->query('status', ''), 'agreement_id' => (string) $request->query('agreement_id', ''), 'contact_id' => (string) $request->query('contact_id', ''),
            'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', '')];
        $like = fn (string $v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
        $q = $this->visible()->with(['agreement:id,reference,title,currency', 'contact:id,name', 'requester:id,name'])
            ->when(in_array($f['status'], R::STATUSES, true), fn ($q) => $q->where('status', $f['status']))->when(ctype_digit($f['agreement_id']) && $f['agreement_id'] !== '', fn ($q) => $q->where('agreement_id', (int) $f['agreement_id']))
            ->when(ctype_digit($f['contact_id']) && $f['contact_id'] !== '', fn ($q) => $q->where('contact_id', (int) $f['contact_id']))
            ->when($f['from'] !== '' && strtotime($f['from']), fn ($q) => $q->whereDate('request_date', '>=', $f['from']))->when($f['to'] !== '' && strtotime($f['to']), fn ($q) => $q->whereDate('request_date', '<=', $f['to']))
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', $like($f['search']))->orWhere('description', 'like', $like($f['search']))->orWhereHas('agreement', fn ($ag) => $ag->where('reference', 'like', $like($f['search']))->orWhere('title', 'like', $like($f['search'])))->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $like($f['search'])))))
            ;
        [$sort, $dir] = $this->sortOf($request, array_merge(['reference', 'contact', 'agreement', 'date', 'status'], $this->money() ? ['requested', 'approved'] : []));
        if ($sort !== '') {
            // ordered across ALL the matching requests, then the page is cut out of that order
            $rows = $this->sortedPage((clone $q)->get(), fn ($r, $c) => match ($c) {
                'reference' => $r->reference, 'contact' => optional($r->contact)->name, 'agreement' => optional($r->agreement)->reference, 'date' => optional($r->request_date)->toDateString(), 'status' => $r->status,
                'requested' => $r->requested_amount, 'approved' => $r->approved_amount, default => null,
            }, $sort, $dir, $request, 20);
        } else {
            $rows = (clone $q)->orderByDesc('id')->paginate(20)->withQueryString();
        }
        $f['sort'] = $sort;
        $f['dir'] = $sort !== '' ? $dir : '';
        $rows->through(fn ($r) => $this->row($r));
        $counts = $this->visible()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('Subcontracts/Requests/Index', ['rows' => $rows, 'filters' => $f, 'statuses' => collect(R::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => __($s)])->values(), 'counts' => $counts, 'contacts' => $this->subcontractors(),
            'money' => $this->money(), 'can' => ['create' => $this->can('create-subcontract-requests')]]);
    }

    public function create(Request $request)
    {
        $this->need('create-subcontract-requests');

        return Inertia::render('Subcontracts/Requests/Form', $this->formProps(null, $request->filled('agreement') ? (int) $request->query('agreement') : null));
    }

    /**
     * When a request asks for more than the balance left on the agreement (revised value less what is already paid or approved), the person is told, and
     * that the excess becomes a negative balance carried forward once the request is approved (an approver with the override right must accept it).
     */
    private function balanceNote(A $a, string $requested, ?int $exceptRequestId = null): ?string
    {
        $head = SubcontractLedger::headroomFor($a->id, $exceptRequestId);
        if (bccomp(bcsub($requested, $head, 2), '0', 2) <= 0) {
            return null;
        }
        if (! $this->money()) {
            return __('This request is more than the balance left on the agreement. If it is approved, the excess is carried forward as a negative balance.');
        }
        $fmt = fn ($v) => SubcontractAmounts::fmt($v, $a->currency);
        $after = bcsub($head, $requested, 2);

        return bccomp($head, '0', 2) <= 0
            ? __('There is no balance left on this agreement (:b). If this request is approved, :n is carried forward as a negative balance.', ['b' => $fmt($head), 'n' => $fmt($after)])
            : __('The balance left is :b. This request is :o more than the balance. If it is approved, the excess is carried forward as a negative balance of :n.', ['b' => $fmt($head), 'o' => $fmt(bcsub($requested, $head, 2)), 'n' => $fmt($after)]);
    }

    public function store(Request $request)
    {
        $this->need('create-subcontract-requests');
        [$d, $items, $a] = $this->validated($request, null);
        $r = ReferenceLock::run('subcontract_requests', fn () => DB::transaction(function () use ($d, $items, $a, $request) {
            $r = R::create($d + ['reference' => R::nextReference(), 'account_id' => $a->account_id, 'contact_id' => $a->contact_id, 'warehouse_id' => $a->warehouse_id, 'currency' => $a->currency, 'status' => R::DRAFT, 'requested_by_user_id' => $request->user()->id]);
            foreach ($items as $i) {
                SubcontractRequestItem::create(['request_id' => $r->id] + $i);
            }

            return $r;
        }));
        $this->saveFiles($r, $request);
        log_activity('Subcontract payment request ' . $r->reference . ' created', ['agreement' => $a->reference, 'requested' => (string) $r->requested_amount], $r, 'Subcontracts');

        return redirect()->route('subcontracts.requests.show', $r->id)->with('message', __(':r saved as a draft.', ['r' => $r->reference]) . ($this->balanceNote($a, (string) $r->requested_amount) ? ' ' . $this->balanceNote($a, (string) $r->requested_amount) : ''));
    }

    public function show(int $request)
    {
        $this->need('read-subcontract-requests');
        $r = $this->find($request)->load(['agreement.contact', 'agreement.warehouse', 'requester:id,name', 'items.workItem']);
        $a = $r->agreement;
        $money = $this->money();
        $this->sweepVoid($r);
        $rf = SubcontractLedger::requestFigures($r->id);
        $af = $money ? SubcontractLedger::forAgreement($a->id) : null;
        $payments = $r->payments()->get()->map(function ($p) use ($money) {
            $v = $p->payment_voucher_id ? DB::table('payment_vouchers')->where('id', $p->payment_voucher_id)->first(['id', 'pv_number', 'status', 'paid_at', 'deleted_at']) : null;
            $paid = SubcontractLedger::voucherIsPaid($v);

            return ['id' => $p->id, 'amount' => $money ? (string) $p->amount : null, 'amount_tzs' => $money ? (string) $p->amount_tzs : null, 'currency' => $p->currency, 'rate' => $p->exchange_rate ? (string) $p->exchange_rate : null, 'rate_date' => optional($p->rate_date)->toDateString(),
                'status' => $p->status, 'voucher_id' => $v->id ?? null, 'voucher' => $v->pv_number ?? null, 'voucher_status' => $v ? ($paid ? 'Paid' : $v->status) : ($p->status === 'cancelled' ? 'Cancelled' : 'Voucher not created yet'), 'paid' => $paid, 'paid_at' => $v && $v->paid_at ? substr((string) $v->paid_at, 0, 10) : null,
                'open' => $p->status === 'initiated' && ! $p->payment_voucher_id];
        });
        $left = $rf['left'];
        $me = auth()->user();
        $own = (int) $r->requested_by_user_id === (int) $me->id;

        $pending = in_array($r->status, [R::DRAFT, R::SUBMITTED, R::REVIEW, R::RETURNED], true);
        return Inertia::render('Subcontracts/Requests/Show', [
            'balance_note' => $pending ? $this->balanceNote($a, (string) $r->requested_amount, $r->id) : null,
            'fields' => SubcontractFields::forPage('request'), 'custom' => SubcontractFields::values('request', $r->extra_attributes),
            'r' => $this->row($r) + ['description' => $r->description, 'kind' => $r->kind, 'kind_label' => __(R::KINDS[$r->kind] ?? $r->kind), 'decision_remarks' => $r->decision_remarks, 'override' => (bool) $r->override, 'override_reason' => $r->override_reason, 'approved_at' => optional($r->approved_at)->toDateTimeString(),
                'agreement_status' => $a->status, 'agreement_title' => $a->title, 'project' => optional($a->warehouse)->name,
                'claim' => $r->claim_line_id ? $this->claimOf($r->claim_line_id) : null],
            'items' => $r->items->map(fn ($i) => ['id' => $i->id, 'work_item_id' => $i->work_item_id, 'work' => optional($i->workItem)->description, 'description' => $i->description, 'amount' => $this->m($i->amount)])->values(),
            'figures' => $money ? $rf : null, 'agreement_figures' => $af, 'payments' => $payments->values(), 'attachments' => $this->filesOf($r),
            'history' => $this->can('view-audit-subcontracts') ? $this->safeHistory(R::class, $r->id) : [], 'money' => $money, 'requestSettings' => ['override_allowed' => (bool) SubcontractSettings::get()['override_allowed']],
            'can' => [
                'edit' => in_array($r->status, [R::DRAFT, R::RETURNED], true) && ($this->can('create-subcontract-requests') && ($own || $this->can('approve-subcontract-requests'))), 'submit' => in_array($r->status, [R::DRAFT, R::RETURNED], true) && $this->can('submit-subcontract-requests'),
                'review' => $r->status === R::SUBMITTED && $this->can('review-subcontract-requests'), 'return' => in_array($r->status, [R::SUBMITTED, R::REVIEW], true) && ($this->can('review-subcontract-requests') || $this->can('approve-subcontract-requests')),
                'approve' => in_array($r->status, [R::SUBMITTED, R::REVIEW], true) && $this->can('approve-subcontract-requests'), 'reject' => in_array($r->status, [R::SUBMITTED, R::REVIEW], true) && $this->can('approve-subcontract-requests'),
                'override' => $this->can('override-subcontract-limits') && SubcontractSettings::get()['override_allowed'],
                'cancel' => (in_array($r->status, [R::DRAFT, R::SUBMITTED, R::RETURNED, R::REVIEW], true) && ($own || $this->can('approve-subcontract-requests'))) || ($r->status === R::APPROVED && $this->can('approve-subcontract-requests') && ! $r->payments()->where('status', '<>', 'cancelled')->exists()),
                'voucher' => $r->status === R::APPROVED && $this->can('initiate-subcontract-vouchers') && $a->isPayable() && bccomp($left, '0', 2) > 0 && ! $payments->contains(fn ($p) => $p['open']), 'delete' => $r->status === R::DRAFT && $own,
                'attach' => $this->can('create-subcontract-requests') && in_array($r->status, [R::DRAFT, R::RETURNED, R::SUBMITTED, R::REVIEW], true), 'documents' => $this->can('view-documents-subcontracts'), 'vouchers' => $this->can('read-payment-vouchers'),
            ],
            'currency_is_tzs' => $a->currency === 'TZS', 'today' => now()->toDateString(),
        ]);
    }

    public function edit(int $request)
    {
        $this->need('create-subcontract-requests');
        $r = $this->find($request);
        abort_unless((int) $r->requested_by_user_id === (int) auth()->id() || $this->can('approve-subcontract-requests') || auth()->user()->hasRole('Super Admin'), 403);
        abort_unless(in_array($r->status, [R::DRAFT, R::RETURNED], true), 422, __('Only a draft or returned request can be changed.'));

        return Inertia::render('Subcontracts/Requests/Form', $this->formProps($r, $r->agreement_id));
    }

    public function update(Request $request, int $requestId)
    {
        $this->need('create-subcontract-requests');
        $r = $this->find($requestId);
        abort_unless((int) $r->requested_by_user_id === (int) auth()->id() || $this->can('approve-subcontract-requests') || auth()->user()->hasRole('Super Admin'), 403);
        abort_unless(in_array($r->status, [R::DRAFT, R::RETURNED], true), 422, __('Only a draft or returned request can be changed.'));
        [$d, $items] = $this->validated($request, $r);
        DB::transaction(function () use ($r, $d, $items) {
            $r->update($d);
            $r->items()->delete();
            foreach ($items as $i) {
                SubcontractRequestItem::create(['request_id' => $r->id] + $i);
            }
        });
        $this->saveFiles($r, $request);
        log_activity('Subcontract payment request ' . $r->reference . ' updated', ['requested' => (string) $r->requested_amount], $r, 'Subcontracts');
        $note = $this->balanceNote($r->agreement()->withoutGlobalScopes()->first(), (string) $r->requested_amount);

        return redirect()->route('subcontracts.requests.show', $r->id)->with('message', __('Saved.') . ($note ? ' ' . $note : ''));
    }

    public function destroy(int $request)
    {
        $r = $this->find($request);
        abort_unless($r->status === R::DRAFT && ((int) $r->requested_by_user_id === (int) auth()->id() || $this->can('approve-subcontract-requests')), 403);
        DB::transaction(function () use ($r) {
            $r->items()->delete();
            $r->delete();
        });
        log_activity('Subcontract payment request ' . $r->reference . ' deleted', [], $r, 'Subcontracts');

        return redirect()->route('subcontracts.requests.index')->with('message', __(':r deleted.', ['r' => $r->reference]));
    }

    /** submit | review | return | approve | reject | cancel */
    public function transition(Request $request, int $requestId, string $action)
    {
        $r = $this->find($requestId);
        $me = $request->user();
        $reason = mb_substr(trim((string) $request->input('reason', '')), 0, 1000);
        $err = null;
        $event = null;
        $overrideNote = null;
        DB::transaction(function () use ($r, $me, $action, $reason, $request, &$err, &$event, &$overrideNote) {
            // the agreement is locked first, so two approvals of the same agreement cannot both use the last of its remaining entitlement
            $a = A::withoutGlobalScopes()->whereKey($r->agreement_id)->lockForUpdate()->first();
            $r = R::query()->whereKey($r->id)->lockForUpdate()->first();
            $from = $r->status;
            switch ($action) {
                case 'submit':
                    $this->need('submit-subcontract-requests');
                    if (! in_array($from, [R::DRAFT, R::RETURNED], true)) { $err = __('Only a draft or returned request can be submitted.'); return; }
                    if (! $a->isPayable()) { $err = __('The agreement is :s: payments cannot be requested.', ['s' => strtolower(__($a->status))]); return; }
                    if (bccomp((string) $r->requested_amount, '0', 2) <= 0) { $err = __('Enter the amount requested.'); return; }
                    $r->forceFill(['status' => R::SUBMITTED, 'decision_remarks' => null, 'submitted_by_user_id' => $me->id, 'submitted_at' => now()])->save();
                    $event = 'request_review';
                    break;
                case 'review':
                    $this->need('review-subcontract-requests');
                    if ($from !== R::SUBMITTED) { $err = __('Only a submitted request can be taken into review.'); return; }
                    if ($this->selfDecision($r->requested_by_user_id) || $this->selfDecision($r->submitted_by_user_id)) { $err = __('You made or submitted this request, so someone else must review it.'); return; }
                    $r->forceFill(['status' => R::REVIEW, 'reviewed_by_user_id' => $me->id, 'reviewed_at' => now()])->save();
                    break;
                case 'return':
                    $this->need('review-subcontract-requests', 'approve-subcontract-requests');
                    if (! in_array($from, [R::SUBMITTED, R::REVIEW], true)) { $err = __('Only a submitted request can be returned.'); return; }
                    if ($reason === '') { $err = __('Say what must be corrected.'); return; }
                    $r->forceFill(['status' => R::RETURNED, 'decision_remarks' => $reason])->save();
                    $event = 'request_returned';
                    break;
                case 'reject':
                    $this->need('approve-subcontract-requests');
                    if (! in_array($from, [R::SUBMITTED, R::REVIEW], true)) { $err = __('Only a submitted request can be rejected.'); return; }
                    if ($reason === '') { $err = __('Say why it is rejected.'); return; }
                    $r->forceFill(['status' => R::REJECTED, 'decision_remarks' => $reason])->save();
                    $event = 'request_decided';
                    break;
                case 'approve':
                    $this->need('approve-subcontract-requests');
                    if (! in_array($from, [R::SUBMITTED, R::REVIEW], true)) { $err = __('Only a submitted request can be approved.'); return; }
                    if ($this->selfDecision($r->requested_by_user_id) || $this->selfDecision($r->submitted_by_user_id)) { $err = __('You made or submitted this request, so someone else must approve it.'); return; }
                    if (! $a->isPayable()) { $err = __('The agreement is :s: payments cannot be approved.', ['s' => strtolower(__($a->status))]); return; }
                    if ($request->filled('approved_amount') && ! preg_match('/^\d{1,14}(\.\d{1,4})?$/', (string) $request->input('approved_amount'))) { $err = __('The approved amount must be a number.'); return; }
                    $amount = SubcontractAmounts::money($request->filled('approved_amount') ? (string) $request->input('approved_amount') : (string) $r->requested_amount);
                    if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, (string) $r->requested_amount, 2) > 0) { $err = __('The approved amount must be above zero and not above the amount requested.'); return; }
                    $head = SubcontractLedger::headroomFor($a->id, $r->id);
                    $over = bcsub($amount, bcadd($head, ltrim(SubcontractAmounts::money((string) SubcontractSettings::get()['tolerance']), '-'), 2), 2); // a tolerance that is not a plain amount counts as 0.00, never as an error
                    if (bccomp($over, '0', 2) > 0) {
                        $why = mb_substr(trim((string) $request->input('override_reason', '')), 0, 1000);
                        $mayOverride = $this->can('override-subcontract-limits') && SubcontractSettings::get()['override_allowed'];
                        if (! $mayOverride || $why === '') {
                            $err = __('This is :o more than the contract still allows (remaining :h). An override with a reason, by someone allowed to give one, is needed; the excess is then carried forward as a negative balance.', ['o' => SubcontractAmounts::fmt($over, $a->currency), 'h' => SubcontractAmounts::fmt($head, $a->currency)]);
                            $overrideNote = ['refused' => true, 'over' => $over];

                            return;
                        }
                        $r->forceFill(['override' => true, 'override_reason' => $why, 'override_by_user_id' => $me->id]);
                        $overrideNote = ['refused' => false, 'over' => $over, 'reason' => $why];
                    }
                    $r->forceFill(['status' => R::APPROVED, 'approved_amount' => $amount, 'approved_by_user_id' => $me->id, 'approved_at' => now(), 'decision_remarks' => $reason ?: null])->save();
                    $event = 'request_decided';
                    break;
                case 'cancel':
                    $own = (int) $r->requested_by_user_id === (int) $me->id;
                    if (! ($own || $this->can('approve-subcontract-requests'))) { $err = __('You may not cancel this request.'); return; }
                    if ($from === R::APPROVED) {
                        if (! $this->can('approve-subcontract-requests')) { $err = __('You may not cancel an approved request.'); return; }
                        if ($r->payments()->where('status', '<>', 'cancelled')->exists()) { $err = __('A payment was started for this request: cancel the voucher first.'); return; }
                    } elseif (! in_array($from, [R::DRAFT, R::SUBMITTED, R::REVIEW, R::RETURNED], true)) { $err = __('This request is already :s.', ['s' => strtolower(__($from))]); return; }
                    if ($reason === '') { $err = __('Say why it is cancelled.'); return; }
                    $r->forceFill(['status' => R::CANCELLED, 'decision_remarks' => $reason])->save();
                    if ($from === R::APPROVED && $r->claim_line_id) {
                        SubcontractClaims::onRequestCancelled($r, $me, $reason); // the claim line this request came from follows it (Cancelled), so its amount is open to request again
                    }
                    break;
                default:
                    abort(404);
            }
            log_activity('Subcontract payment request ' . $r->reference . ' ' . $action, ['from' => $from, 'to' => $r->status, 'approved_amount' => $r->approved_amount ? (string) $r->approved_amount : null, 'override' => $overrideNote && ! $overrideNote['refused'] ? ['by' => $me->id, 'over' => $overrideNote['over'], 'reason' => $overrideNote['reason']] : null, 'reason' => $reason ?: null], $r, 'Subcontracts');
        });
        if ($err) {
            if ($overrideNote && $overrideNote['refused']) {
                log_activity('Subcontract overpayment refused', ['request' => $r->reference, 'over' => $overrideNote['over']], $r, 'Subcontracts');
                SubcontractNotifier::fire('limit_exceeded', 'request', $r, $me, __('Approval of :r refused: :o above the remaining contract value.', ['r' => $r->reference, 'o' => $overrideNote['over']]));
                SubcontractAlerts::raise('control', 'overpayment_refused', 'medium', __('Approval of :r was refused', ['r' => $r->reference]), __(':o above the remaining contract value; tried by :u.', ['o' => $overrideNote['over'], 'u' => $me->name]), ['agreement_id' => $r->agreement_id, 'contact_id' => $r->contact_id, 'subject_type' => 'request', 'subject_id' => $r->id, 'currency' => $r->currency, 'trigger' => 'event', 'dedupe' => 'refused-' . $r->id]);
            }

            return back()->with('error', e($err));
        }
        $r->refresh();
        if ($event) {
            SubcontractNotifier::fire($event, 'request', $r, $me, $r->decision_remarks ?: $r->status, ['amount' => (string) ($r->approved_amount ?? $r->requested_amount)]);
        }
        if ($overrideNote && ! $overrideNote['refused']) {
            SubcontractNotifier::fire('limit_exceeded', 'request', $r, $me, __(':r approved with an override (:o above the remaining value): :why', ['r' => $r->reference, 'o' => $overrideNote['over'], 'why' => $overrideNote['reason']]));
            SubcontractAlerts::raise('control', 'override_used', 'high', __(':r was approved above the contract value', ['r' => $r->reference]), __(':o above the remaining value, approved by :u with an override: :why', ['o' => $overrideNote['over'], 'u' => $me->name, 'why' => $overrideNote['reason']]), ['agreement_id' => $r->agreement_id, 'contact_id' => $r->contact_id, 'subject_type' => 'request', 'subject_id' => $r->id, 'amount' => (string) ($r->approved_amount ?? $r->requested_amount), 'currency' => $r->currency, 'trigger' => 'event', 'dedupe' => 'override-' . $r->id]);
        }

        $note = $action === 'submit' ? $this->balanceNote(A::withoutGlobalScopes()->find($r->agreement_id), (string) $r->requested_amount) : null;

        return back()->with('message', __(':r is now :s.', ['r' => $r->reference, 's' => strtolower(__($r->status))]) . ($note ? ' ' . $note : ''));
    }

    /**
     * Starts the Payment Voucher for an approved request: one open payment at a time per request, never more than what is left on it. The voucher itself is made on the
     * existing Payment Voucher form (this redirects there with the amount and the link); nothing is paid until that voucher is authorised and marked paid.
     */
    public function initiateVoucher(Request $request, int $requestId)
    {
        $this->need('initiate-subcontract-vouchers');
        $r = $this->find($requestId);
        $data = $request->validate(['amount' => 'required|numeric|regex:/^\d+(\.\d+)?$/|min:0.01|max:99999999999999', 'exchange_rate' => 'nullable|numeric|regex:/^\d+(\.\d+)?$/|min:0.000001|max:99999999', 'rate_date' => 'nullable|date']);
        $payment = DB::transaction(function () use ($r, $data) {
            $a = A::withoutGlobalScopes()->whereKey($r->agreement_id)->lockForUpdate()->first();
            $r = R::query()->whereKey($r->id)->lockForUpdate()->first();
            if ($r->status !== R::APPROVED) {
                throw ValidationException::withMessages(['amount' => __('Only an approved request can be paid.')]);
            }
            if (! $a->isPayable()) {
                throw ValidationException::withMessages(['amount' => __('The agreement is :s: it cannot be paid.', ['s' => strtolower(__($a->status))])]);
            }
            $this->sweepVoid($r);
            if (SubcontractPayment::where('request_id', $r->id)->where('status', 'initiated')->whereNull('payment_voucher_id')->exists()) {
                throw ValidationException::withMessages(['amount' => __('A voucher was already started for this request. Finish it or cancel it first.')]);
            }
            $left = SubcontractLedger::requestFigures($r->id)['left'];
            $amount = SubcontractAmounts::money((string) $data['amount']);
            if (bccomp($amount, $left, 2) > 0) {
                throw ValidationException::withMessages(['amount' => __('Only :l is left to pay on this request.', ['l' => SubcontractAmounts::fmt($left, $a->currency)])]);
            }
            $rate = null;
            $tzs = $amount;
            if ($a->currency !== 'TZS') {
                if (empty($data['exchange_rate']) || empty($data['rate_date'])) {
                    throw ValidationException::withMessages(['exchange_rate' => __('The agreement is in :c: enter the exchange rate to TZS and its date.', ['c' => $a->currency])]);
                }
                $rate = bcadd((string) $data['exchange_rate'], '0', 6);
                $tzs = SubcontractAmounts::round2(bcmul($amount, $rate, 6));
                if (bccomp($tzs, '9999999999999.99', 2) > 0) {
                    throw ValidationException::withMessages(['amount' => __('The amount in TZS is too large.')]);
                }
            }
            $p = SubcontractPayment::create(['request_id' => $r->id, 'agreement_id' => $a->id, 'amount' => $amount, 'currency' => $a->currency, 'exchange_rate' => $rate, 'rate_date' => $rate ? $data['rate_date'] : null, 'amount_tzs' => $tzs, 'status' => 'initiated', 'created_by_user_id' => auth()->id()]);
            $this->allocate($p, $r, $amount);

            return $p;
        });
        log_activity('Subcontract payment started for ' . $r->reference, ['payment' => $payment->id, 'amount' => (string) $payment->amount, 'amount_tzs' => (string) $payment->amount_tzs], $r, 'Subcontracts');

        return redirect()->route('payment-vouchers.create', ['subcontract_payment' => $payment->id]);
    }

    public function cancelPayment(int $requestId, int $payment)
    {
        $this->need('initiate-subcontract-vouchers');
        $r = $this->find($requestId);
        $p = SubcontractPayment::where('request_id', $r->id)->findOrFail($payment);
        DB::transaction(function () use ($p) {
            $locked = SubcontractPayment::query()->whereKey($p->id)->lockForUpdate()->first();
            abort_unless($locked && $locked->status === 'initiated' && ! $locked->payment_voucher_id, 422, __('Only a payment without a voucher can be cancelled here. A voucher is cancelled in Payment Vouchers.'));
            $locked->update(['status' => 'cancelled']);
        });
        log_activity('Subcontract payment cancelled for ' . $r->reference, ['payment' => $p->id], $r, 'Subcontracts');

        return back()->with('message', __('The payment was cancelled.'));
    }

    public function attach(Request $request, int $requestId)
    {
        $this->need('create-subcontract-requests');
        $r = $this->find($requestId);
        abort_unless((int) $r->requested_by_user_id === (int) auth()->id() || $this->can('approve-subcontract-requests') || auth()->user()->hasRole('Super Admin'), 403);
        abort_unless(in_array($r->status, [R::DRAFT, R::RETURNED, R::SUBMITTED, R::REVIEW], true), 422, __('Documents can be added to a request only until it is decided.'));
        $request->validate(['attachments' => 'required|array|min:1|max:10', 'attachments.*' => 'file|max:8192|mimes:' . self::FILE_TYPES]);
        $n = $this->saveFiles($r, $request);
        log_activity('Subcontract payment request ' . $r->reference . ' document added', ['files' => $n], $r, 'Subcontracts');

        return back()->with('message', trans_choice(':n document attached.|:n documents attached.', $n, ['n' => $n]));
    }

    public function download(int $requestId, int $file)
    {
        return $this->downloadFile($this->find($requestId), $file, 'Subcontract payment request');
    }

    public function removeFile(int $requestId, int $file)
    {
        $this->need('create-subcontract-requests');
        $r = $this->find($requestId);
        abort_unless(in_array($r->status, [R::DRAFT, R::RETURNED], true), 422);
        $this->removeFileOf($r, $file, 'Subcontract payment request');

        return back()->with('message', __('Document removed.'));
    }

    // -----------------------------------------------------------------------------------------------------------------

    /** A payment whose voucher was rejected, cancelled or deleted is void: it frees the request for a new voucher. */
    private function sweepVoid(R $r): void
    {
        foreach (SubcontractPayment::where('request_id', $r->id)->where('status', 'voucher')->whereNotNull('payment_voucher_id')->get() as $p) {
            $v = DB::table('payment_vouchers')->where('id', $p->payment_voucher_id)->first(['status', 'deleted_at']);
            if (! $v || $v->deleted_at || in_array($v->status, ['Rejected', 'Cancelled'], true)) {
                $p->update(['status' => 'cancelled']);
                log_activity('Subcontract payment void: its voucher is no longer valid', ['payment' => $p->id, 'voucher' => $p->payment_voucher_id], $r, 'Subcontracts');
            }
        }
    }

    /** Spreads a payment over the work items of the request in proportion to what was requested (the one implementation is shared with the claim vouchers). */
    private function allocate(SubcontractPayment $p, R $r, string $amount): void
    {
        SubcontractClaims::allocate($p, $r, $amount);
    }

    /** The Contract Payment Request (claim) a payment request came from, for its page. */
    private function claimOf(int $lineId): ?array
    {
        $l = \App\Models\SubcontractClaimLine::query()->with('claim:id,reference,status')->find($lineId);

        return $l && $l->claim ? ['id' => $l->claim->id, 'reference' => $l->claim->reference, 'status' => $l->claim->status, 'line_id' => $l->id, 'url' => $this->can('read-subcontract-claims') ? route('subcontracts.claims.show', $l->claim->id) : null] : null;
    }

    public function row(R $r): array
    {
        return ['id' => $r->id, 'reference' => $r->reference, 'agreement_id' => $r->agreement_id, 'agreement' => optional($r->agreement)->reference, 'contact_id' => $r->contact_id, 'contact' => optional($r->contact)->name, 'date' => optional($r->request_date)->toDateString(),
            'status' => $r->status, 'status_label' => __($r->status), 'currency' => $r->currency, 'requested' => $this->m($r->requested_amount), 'approved' => $r->approved_amount !== null ? $this->m($r->approved_amount) : null, 'by' => optional($r->requester)->name, 'kind' => $r->kind];
    }

    private function formProps(?R $r, ?int $agreementId): array
    {
        $agreements = $this->visibleAgreements()->whereIn('status', A::PAYABLE)->with('contact:id,name')->orderByDesc('id')->limit(500)->get();
        $opts = $agreements->map(fn ($a) => ['value' => $a->id, 'label' => $a->reference . ' - ' . optional($a->contact)->name . ' - ' . $a->title, 'currency' => $a->currency])->values();
        $sel = $agreementId ? $agreements->firstWhere('id', $agreementId) : null;
        $items = $sel ? SubcontractWorkItem::where('agreement_id', $sel->id)->where('status', 'active')->get()->map(fn ($i) => ['value' => $i->id, 'label' => mb_substr($i->description, 0, 90) . ($this->money() ? ' (' . number_format((float) $i->amount, 2) . ')' : '')])->values() : [];

        return ['r' => $r ? ['id' => $r->id, 'reference' => $r->reference, 'agreement_id' => $r->agreement_id, 'request_date' => optional($r->request_date)->toDateString(), 'requested_amount' => (string) $r->requested_amount, 'kind' => $r->kind, 'description' => $r->description,
            'items' => $r->items()->get()->map(fn ($i) => ['work_item_id' => $i->work_item_id, 'description' => $i->description, 'amount' => (string) $i->amount])->values(), 'custom' => SubcontractFields::values('request', $r->extra_attributes)] : null, 'fields' => SubcontractFields::forPage('request'),
            'agreements' => $opts, 'work_items' => $items, 'agreement_id' => $agreementId, 'kinds' => collect(R::KINDS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(),
            'figures' => $sel && $this->money() ? SubcontractLedger::forAgreement($sel->id) : null, 'currency' => $sel->currency ?? null, 'money' => $this->money(), 'today' => now()->toDateString(), 'attachments' => $r ? $this->filesOf($r) : []];
    }

    /** @return array{0:array,1:array,2:A} */
    private function validated(Request $request, ?R $existing): array
    {
        $d = SubcontractFields::validate($request, 'request', ['agreement_id' => 'required|integer', 'request_date' => 'required|date', 'kind' => 'required|in:' . implode(',', array_keys(R::KINDS)), 'requested_amount' => 'nullable|numeric|regex:/^-?\d+(\.\d+)?$/|min:0|max:99999999999999', 'description' => 'nullable|string|max:3000',
            'items' => 'nullable|array|max:300', 'items.*.work_item_id' => 'nullable|integer', 'items.*.description' => 'nullable|string|max:255', 'items.*.amount' => 'nullable|numeric|regex:/^-?\d+(\.\d+)?$/|min:0.01|max:99999999999999'] + $this->fileRules(), $existing ? ($existing->extra_attributes ?: []) : null);
        $a = $this->agreementOrFail((int) $d['agreement_id']);
        $errors = [];
        if ($existing && (int) $existing->agreement_id !== (int) $a->id) {
            $errors['agreement_id'] = __('The agreement of a request cannot be changed.');
        }
        if (! $a->isPayable()) {
            $errors['agreement_id'] = __('The agreement is :s: payments cannot be requested.', ['s' => strtolower(__($a->status))]);
        }
        $items = [];
        $sum = '0.00';
        foreach ((array) ($d['items'] ?? []) as $i => $row) {
            if (($row['amount'] ?? '') === '' || $row['amount'] === null) {
                continue;
            }
            if (! empty($row['work_item_id']) && ! SubcontractWorkItem::where('agreement_id', $a->id)->whereKey($row['work_item_id'])->exists()) {
                $errors["items.$i.work_item_id"] = __('Choose a work item of this agreement.');
                continue;
            }
            $amt = SubcontractAmounts::money((string) $row['amount']);
            $sum = bcadd($sum, $amt, 2);
            $items[] = ['work_item_id' => $row['work_item_id'] ?? null, 'description' => isset($row['description']) ? trim((string) $row['description']) : null, 'amount' => $amt];
        }
        if (bccomp($sum, '9999999999999.99', 2) > 0) {
            $errors['items'] = __('The total of the lines is too large.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $requested = $items ? $sum : SubcontractAmounts::money((string) ($d['requested_amount'] ?? '0'));
        if (bccomp($requested, '0', 2) <= 0) {
            throw ValidationException::withMessages(['requested_amount' => __('Enter the amount requested, or the work items with their amounts.')]);
        }
        unset($d['attachments'], $d['items'], $d['requested_amount']);
        $d['requested_amount'] = $requested;
        if (array_key_exists('description', $d)) {
            $d['description'] = isset($d['description']) && trim($d['description']) !== '' ? trim($d['description']) : null;
        }

        return [$d, $items, $a];
    }
}
