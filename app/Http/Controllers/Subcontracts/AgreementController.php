<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\SubcontractAgreement as A;
use App\Models\SubcontractPaymentRequest as R;
use App\Models\SubcontractVariation;
use App\Models\User;
use App\Services\Subcontracts\SubcontractClaims;
use App\Services\Subcontracts\SubcontractLedger;
use App\Services\Subcontracts\SubcontractNotifier;
use App\Services\Subcontracts\SubcontractAmounts;
use App\Services\Subcontracts\SubcontractFields;
use App\Services\Subcontracts\SubcontractSettings;
use App\Support\ReferenceLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Subcontract agreements: the register, one agreement with its work schedule, and the approval path
 * (Draft > Submitted > Under Review > Approved; then Active, Suspended, Completed, Terminated). The original amount is frozen once the agreement leaves Draft.
 */
class AgreementController extends Controller
{
    use SubcontractHelpers;
    use SortsLists;

    public function __construct()
    {
        $this->setAbilities([]); // every action is checked by name below
    }

    public function index(Request $request)
    {
        $this->scalarQuery($request);
        $this->need('read-subcontract-agreements');
        $f = ['search' => trim((string) $request->query('search', '')), 'contact_id' => (string) $request->query('contact_id', ''), 'warehouse_id' => (string) $request->query('warehouse_id', ''), 'status' => (string) $request->query('status', ''),
            'currency' => (string) $request->query('currency', ''), 'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', '')];
        $like = fn (string $v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
        $q = $this->visibleAgreements()->with(['contact:id,name', 'warehouse:id,name', 'administrator:id,name'])
            ->when(in_array($f['status'], A::STATUSES, true), fn ($x) => $x->where('status', $f['status']), fn ($x) => $x->where('status', '<>', A::ARCHIVED)) // "All" leaves out the archive; its own tab shows it
            ->when(ctype_digit($f['contact_id']) && $f['contact_id'] !== '', fn ($x) => $x->where('contact_id', (int) $f['contact_id']))
            ->when(ctype_digit($f['warehouse_id']) && $f['warehouse_id'] !== '', fn ($x) => $x->where('warehouse_id', (int) $f['warehouse_id']))
            ->when(in_array($f['currency'], SubcontractSettings::get()['currencies'], true), fn ($x) => $x->where('currency', $f['currency']))
            ->when($f['from'] !== '' && strtotime($f['from']), fn ($x) => $x->whereDate('agreement_date', '>=', $f['from']))->when($f['to'] !== '' && strtotime($f['to']), fn ($x) => $x->whereDate('agreement_date', '<=', $f['to']))
            ->when($f['search'] !== '', fn ($x) => $x->where(fn ($w) => $w->where('reference', 'like', $like($f['search']))->orWhere('title', 'like', $like($f['search']))->orWhere('site', 'like', $like($f['search']))->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $like($f['search'])))));
        // the figures of every agreement that matches the filters (the page and the summary cards above it)
        $matched = (clone $q)->reorder()->get(['id', 'status']);
        $fig = $this->money() ? SubcontractLedger::forAgreements($matched->pluck('id')->all()) : [];
        $summary = ['count' => $matched->count(), 'payable' => $matched->whereIn('status', A::PAYABLE)->count(), 'figures' => $this->money() ? SubcontractLedger::foldByCurrency($fig, $f['status'] === A::ARCHIVED) : []];
        [$sort, $dir] = $this->sortOf($request, array_merge(['reference', 'contact', 'project', 'status'], $this->money() ? ['original', 'revised', 'paid', 'outstanding'] : []));
        if ($sort !== '') {
            // ordered across ALL the matching agreements, then the page is cut out of that order
            $all = (clone $q)->reorder()->get();
            $labels = [];
            if ($sort === 'project') {
                $m = $this->mappedProjectNames($all->pluck('id')->all());
                $labels = $all->mapWithKeys(fn ($a) => [$a->id => $this->projectLabel($a, $m[$a->id] ?? [])])->all();
            }
            $rows = $this->sortedPage($all, fn ($a, $c) => match ($c) {
                'reference' => $a->reference, 'contact' => optional($a->contact)->name, 'project' => $labels[$a->id] ?? null, 'status' => $a->status,
                'original' => $fig[$a->id]['original'] ?? null, 'revised' => $fig[$a->id]['revised'] ?? null, 'paid' => $fig[$a->id]['paid_total'] ?? null, 'outstanding' => $fig[$a->id]['commitment_left'] ?? null, default => null,
            }, $sort, $dir, $request, 20);
        } else {
            $rows = (clone $q)->orderByDesc('id')->paginate(20)->withQueryString();
        }
        $f['sort'] = $sort;
        $f['dir'] = $sort !== '' ? $dir : '';
        $mapped = $this->mappedProjectNames($rows->getCollection()->pluck('id')->all());
        $rows->through(fn ($a) => [...$this->row($a, $fig[$a->id] ?? null), 'project' => $this->projectLabel($a, $mapped[$a->id] ?? [])]);
        $counts = $this->visibleAgreements()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('Subcontracts/Agreements/Index', [
            'summary' => $summary, 'list_fields' => SubcontractFields::listFields('agreement'),
            'rows' => $rows, 'filters' => $f, 'statuses' => collect(A::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => __($s)])->values(), 'contacts' => $this->subcontractors(), 'projects' => $this->projects(),
            'currencies' => collect(SubcontractSettings::get()['currencies'])->map(fn ($c) => ['value' => $c, 'label' => $c])->values(), 'counts' => $counts, 'money' => $this->money(),
            'can' => ['create' => $this->can('create-subcontract-agreements'), 'print' => $this->can('print-subcontract-reports')],
        ]);
    }

    public function create(Request $request)
    {
        $this->need('create-subcontract-agreements');

        return Inertia::render('Subcontracts/Agreements/Form', $this->formProps(null) + ['preset' => ['contact_id' => $request->filled('contact_id') ? (int) $request->query('contact_id') : null]]);
    }

    public function store(Request $request)
    {
        $this->need('create-subcontract-agreements');
        $d = $this->validated($request, null);
        $a = ReferenceLock::run('subcontract_agreements', fn () => A::create($d + ['reference' => A::nextReference(), 'status' => A::DRAFT, 'user_id' => $request->user()->id, 'source' => 'manual']));
        $this->saveFiles($a, $request);
        log_activity('Subcontract agreement ' . $a->reference . ' created', ['contact' => $a->contact_id, 'original_amount' => $a->original_amount, 'currency' => $a->currency], $a, 'Subcontracts');

        return redirect()->route('subcontracts.agreements.show', $a->id)->with('message', __(':r saved as a draft.', ['r' => $a->reference]));
    }

    public function show(int $agreement)
    {
        $this->need('read-subcontract-agreements');
        $a = $this->agreementOrFail($agreement);
        $a->load(['contact', 'warehouse', 'administrator:id,name', 'creator:id,name']);
        $money = $this->money();
        $fig = $money ? SubcontractLedger::forAgreement($a->id) : null;
        $me = auth()->user();

        $approvedVar = DB::table('subcontract_variations')->where('agreement_id', $a->id)->whereNull('deleted_at')->pluck('status', 'id');
        $items = $a->items()->get();
        $grp = $a->groups()->get();
        $gw = DB::table('warehouses')->whereIn('id', $grp->pluck('warehouse_id')->filter()->unique()->all() ?: [0])->pluck('name', 'id'); // Project Name Mapping: a group shows the name of its Project(s) / Warehouse(s)
        $groups = $grp->map(function ($g) use ($items, $money, $approvedVar, $gw) {
            $its = $items->where('group_id', $g->id)->values();

            return ['id' => $g->id, 'name' => $gw[$g->warehouse_id] ?? $g->name, 'excel_name' => $g->name, 'project_id' => $g->warehouse_id, 'kind' => $g->kind, 'warehouse_id' => $g->warehouse_id, 'total' => $money ? number_format((float) $its->filter(fn ($i) => $this->counts($i, $approvedVar))->sum('amount'), 2, '.', '') : null,
                'items' => $its->map(fn ($i) => $this->itemRow($i, $money, $approvedVar))->values()];
        })->values();
        $loose = $items->whereNull('group_id')->values()->map(fn ($i) => $this->itemRow($i, $money, $approvedVar));

        $pays = $a->payments()->with('request:id,reference')->limit(200)->get();
        $vouchers = DB::table('payment_vouchers')->whereIn('id', $pays->pluck('payment_voucher_id')->filter()->unique()->values()->all() ?: [0])->get(['id', 'pv_number', 'status', 'paid_at', 'deleted_at'])->keyBy('id'); // one query for every voucher of the page
        $payments = $pays->map(function ($p) use ($money, $vouchers) {
            $v = $p->payment_voucher_id ? ($vouchers[$p->payment_voucher_id] ?? null) : null;
            $paid = SubcontractLedger::voucherIsPaid($v);

            return ['id' => $p->id, 'request' => optional($p->request)->reference, 'request_id' => $p->request_id, 'amount' => $money ? (string) $p->amount : null, 'currency' => $p->currency, 'voucher_id' => $v->id ?? null, 'voucher' => $v->pv_number ?? null,
                'voucher_status' => $v ? ($paid ? 'Paid' : $v->status) : ($p->status === 'cancelled' ? 'Cancelled' : 'Voucher not created yet'), 'paid' => $paid, 'paid_at' => $v && $v->paid_at ? substr((string) $v->paid_at, 0, 10) : null];
        });
        $reqs = $a->requests()->limit(200)->get();
        $rfs = SubcontractLedger::requestFiguresMany($reqs->pluck('id')->all()); // the paid / in-process figures of every request in two queries
        $hist = DB::table('subcontract_historical_payments')->where('agreement_id', $a->id)->whereNull('deleted_at')->selectRaw("kind, COUNT(*) n, SUM(amount) s, MIN(payment_date) d1, MAX(payment_date) d2")->groupBy('kind')->get()->keyBy('kind');

        return Inertia::render('Subcontracts/Agreements/Show', [
            'fields' => SubcontractFields::forPage('agreement'), 'custom' => SubcontractFields::values('agreement', $a->extra_attributes), 'item_fields' => SubcontractFields::forPage('work_item'), 'variation_fields' => SubcontractFields::forPage('variation'),
            'a' => ['project' => $this->projectLabel($a, $grp->map(fn ($g) => $gw[$g->warehouse_id] ?? null)->filter()->unique()->values()->all()), 'projects' => $this->projectList($a, $grp->map(fn ($g) => $gw[$g->warehouse_id] ?? null)->filter()->unique()->values()->all())] + $this->row($a, $fig) + ['scope' => $a->scope, 'payment_terms' => $a->payment_terms, 'advance_terms' => $a->advance_terms, 'retention_percent' => (string) $a->retention_percent, 'vat_treatment' => $a->vat_treatment, 'remarks' => $a->remarks,
                'start_date' => optional($a->start_date)->toDateString(), 'actual_completion' => optional($a->actual_completion)->toDateString(), 'decision_remarks' => $a->decision_remarks, 'source' => $a->source, 'as_at_date' => optional($a->as_at_date)->toDateString(),
                'created_by' => optional($a->creator)->name, 'contact_phone' => optional($a->contact)->phone, 'contact_email' => optional($a->contact)->email, 'contact_location' => optional($a->contact)->location, 'site' => $a->site,
                'agreement_type_label' => __(A::TYPES[$a->agreement_type] ?? $a->agreement_type),
                'archived_note' => $a->status === A::ARCHIVED ? trim(($a->extra_attributes['archived']['reason'] ?? '') . ' ' . (! empty($a->extra_attributes['archived']['at']) ? __('Archived on :d.', ['d' => substr($a->extra_attributes['archived']['at'], 0, 10)]) : '')) : null],
            'figures' => $fig, 'groups' => $groups, 'loose' => $loose,
            'variations' => $a->variations()->limit(200)->get()->map(fn ($v) => ['id' => $v->id, 'reference' => $v->reference, 'title' => $v->title, 'direction' => $v->direction, 'amount' => $money ? (string) $v->amount : null, 'status' => $v->status])->values(),
            'requests' => $reqs->map(function ($r) use ($money, $rfs) {
                $rf = $rfs[$r->id];

                return ['id' => $r->id, 'reference' => $r->reference, 'date' => optional($r->request_date)->toDateString(), 'status' => $r->status, 'requested' => $money ? (string) $r->requested_amount : null, 'approved' => $money && $r->approved_amount !== null ? (string) $r->approved_amount : null,
                    'paid' => $money ? $rf['paid'] : null];
            })->values(),
            'payments' => $payments->values(), 'legacy' => ['has_reconciliation' => \Illuminate\Support\Facades\DB::table('subcontract_reconciliations')->where('agreement_id', $a->id)->whereNull('deleted_at')->exists(), 'counted' => $money ? ($hist['opening_paid']->s ?? '0.00') : null, 'counted_n' => (int) ($hist['opening_paid']->n ?? 0), 'dated_n' => (int) ($hist['dated_evidence']->n ?? 0), 'dated_sum' => $money ? ($hist['dated_evidence']->s ?? '0.00') : null,
                'dated_from' => $hist['dated_evidence']->d1 ?? null, 'dated_to' => $hist['dated_evidence']->d2 ?? null],
            'attachments' => $this->filesOf($a),
            'completion' => $this->completionBlock($a, $money), 'claims' => $this->can('read-subcontract-claims') ? SubcontractClaims::claimsOfAgreement($a->id, $money) : [],
            'history' => $this->can('view-audit-subcontracts') ? $this->safeHistory(A::class, $a->id) : [],
            'units' => array_keys(SubcontractSettings::get()['units']), 'categories' => SubcontractSettings::get()['categories'], 'projects' => $this->projects(), 'money' => $money,
            'group_kinds' => collect(\App\Models\SubcontractWorkGroup::KINDS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(), 'pricing' => collect(\App\Models\SubcontractWorkItem::PRICING)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(),
            'can' => $this->abilities($a),
        ]);
    }

    public function edit(int $agreement)
    {
        $a = $this->agreementOrFail($agreement);
        $this->need('update-subcontract-agreements', 'manage-subcontract-agreements');

        return Inertia::render('Subcontracts/Agreements/Form', $this->formProps($a));
    }

    public function update(Request $request, int $agreement)
    {
        $a = $this->agreementOrFail($agreement);
        $this->need('update-subcontract-agreements', 'manage-subcontract-agreements');
        abort_if($a->status === A::ARCHIVED, 422, __('An archived agreement is read-only. Restore it first.'));
        $d = $this->validated($request, $a);
        DB::transaction(function () use (&$a, $d, $agreement) {
            // locked, so an approval that happens at the same moment cannot be overwritten with a stale view of the status
            $a = A::withoutGlobalScopes()->whereKey($agreement)->lockForUpdate()->first();
            if ($a->status !== A::DRAFT) {
                // after Draft the money, the subcontractor and the currency are fixed; the administration details can still be corrected
                $d = collect($d)->only(['title', 'scope', 'site', 'agreement_date', 'start_date', 'expected_completion', 'administrator_user_id', 'payment_terms', 'advance_terms', 'vat_treatment', 'remarks', 'extra_attributes'])->all();
            }
            $a->update($d);
        });
        $this->saveFiles($a, $request);
        log_activity('Subcontract agreement ' . $a->reference . ' updated', ['fields' => array_keys($d)], $a, 'Subcontracts');

        return redirect()->route('subcontracts.agreements.show', $a->id)->with('message', __('Saved.'));
    }

    public function destroy(int $agreement)
    {
        $a = $this->agreementOrFail($agreement);
        $this->need('delete-subcontract-agreements');
        abort_unless($a->status === A::DRAFT, 422, __('Only a draft agreement can be deleted.'));
        abort_if($a->requests()->exists() || $a->historicalPayments()->exists(), 422, __('This agreement has payment records.'));
        DB::transaction(function () use ($a) {
            $a->items()->delete();
            $a->groups()->delete();
            $a->variations()->delete();
            $a->delete();
        });
        log_activity('Subcontract agreement ' . $a->reference . ' deleted', [], $a, 'Subcontracts');

        return redirect()->route('subcontracts.agreements.index')->with('message', __(':r deleted.', ['r' => $a->reference]));
    }

    /**
     * submit | review | approve | reject | suspend | activate | complete | terminate | archive | restore | verify-completion | unverify-completion
     * complete takes an optional note (`note` or `reason`) and an optional `actual_completion` date (today when empty); verify / unverify take `reason` as the note.
     * Completing or verifying NEVER creates a payment and never touches a paid figure: it only decides whether the agreement can be put on a Contract Payment Request (claim).
     */
    public function transition(Request $request, int $agreement, string $action)
    {
        $a = $this->agreementOrFail($agreement);
        $me = $request->user();
        $reason = mb_substr(trim((string) $request->input('reason', '')), 0, 1000);
        $err = null;
        $event = null;
        $logMore = [];
        DB::transaction(function () use ($a, $me, $action, $reason, $request, &$err, &$event, &$logMore) {
            $a = A::withoutGlobalScopes()->whereKey($a->id)->lockForUpdate()->first();
            $from = $a->status;
            switch ($action) {
                case 'submit':
                    $this->need('submit-subcontract-agreements');
                    if ($from !== A::DRAFT) { $err = __('Only a draft can be submitted.'); return; }
                    if (! $a->contact_id || trim((string) $a->title) === '') { $err = __('The subcontractor and the title are needed.'); return; }
                    if (bccomp((string) $a->original_amount, '0', 2) <= 0) { $err = __('Enter the original agreement amount first.'); return; }
                    $a->forceFill(['status' => A::SUBMITTED, 'submitted_by_user_id' => $me->id, 'submitted_at' => now(), 'decision_remarks' => null])->save();
                    $event = 'agreement_review';
                    break;
                case 'review':
                    $this->need('review-subcontract-agreements');
                    if ($from !== A::SUBMITTED) { $err = __('Only a submitted agreement can be taken into review.'); return; }
                    if ($this->selfDecision($a->submitted_by_user_id)) { $err = __('You submitted this agreement, so someone else must review it.'); return; }
                    $a->forceFill(['status' => A::REVIEW, 'reviewed_by_user_id' => $me->id, 'reviewed_at' => now()])->save();
                    break;
                case 'approve':
                    $this->need('approve-subcontract-agreements');
                    if (! in_array($from, [A::SUBMITTED, A::REVIEW], true)) { $err = __('Only a submitted agreement can be approved.'); return; }
                    if ($this->selfDecision($a->submitted_by_user_id) || $this->selfDecision($a->user_id)) { $err = __('You created or submitted this agreement, so someone else must approve it.'); return; }
                    $a->forceFill(['status' => A::APPROVED, 'approved_by_user_id' => $me->id, 'approved_at' => now(), 'decision_remarks' => $reason ?: null])->save();
                    $event = 'agreement_decided';
                    break;
                case 'reject':
                    $this->need('approve-subcontract-agreements', 'review-subcontract-agreements');
                    if (! in_array($from, [A::SUBMITTED, A::REVIEW], true)) { $err = __('Only a submitted agreement can be rejected.'); return; }
                    if ($reason === '') { $err = __('Say why it is rejected.'); return; }
                    $a->forceFill(['status' => A::DRAFT, 'decision_remarks' => __('Rejected') . ': ' . $reason])->save();
                    $event = 'agreement_decided';
                    break;
                case 'suspend':
                    $this->need('manage-subcontract-agreements');
                    if (! in_array($from, [A::APPROVED, A::ACTIVE], true)) { $err = __('Only an approved or active agreement can be suspended.'); return; }
                    $a->forceFill(['status' => A::SUSPENDED, 'decision_remarks' => $reason ?: null])->save();
                    break;
                case 'activate':
                    $this->need('manage-subcontract-agreements');
                    if (! in_array($from, [A::APPROVED, A::SUSPENDED], true)) { $err = __('Only an approved or suspended agreement can be made active.'); return; }
                    $a->forceFill(['status' => A::ACTIVE, 'start_date' => $a->start_date ?: now()->toDateString()])->save();
                    break;
                case 'complete':
                    $this->need('manage-subcontract-agreements');
                    if (! in_array($from, [A::APPROVED, A::ACTIVE], true)) { $err = __('Only an approved or active agreement can be completed.'); return; }
                    $when = trim((string) $request->input('actual_completion', ''));
                    if ($when !== '' && (! strtotime($when) || strtotime($when) > strtotime('today'))) { $err = __('The completion date must be a real date, today or earlier.'); return; }
                    $note = mb_substr(trim((string) $request->input('note', $reason)), 0, 2000);
                    $a->forceFill(['status' => A::COMPLETED, 'actual_completion' => $when !== '' ? date('Y-m-d', strtotime($when)) : ($a->actual_completion ? $a->actual_completion->toDateString() : now()->toDateString()),
                        'completed_by_user_id' => $me->id, 'completed_at' => now(), 'completion_note' => $note ?: null, 'completion_verified_by_user_id' => null, 'completion_verified_at' => null, 'completion_verify_note' => null])->save();
                    $logMore = ['completion_note' => $note ?: null];
                    break;
                case 'verify-completion':
                    $this->need('verify-subcontract-completion');
                    if ($from !== A::COMPLETED) { $err = __('Only a completed agreement can have its completion verified.'); return; }
                    if ($a->completion_verified_at) { $err = __('The completion is already verified.'); return; }
                    if ($this->selfDecision($a->completed_by_user_id)) { $err = __('You completed this agreement, so someone else must verify the completion.'); return; }
                    if (SubcontractClaims::requiresEvidence() && $a->attachments()->count() < 1) { $err = __('Attach at least one document to the agreement as evidence of completion before verifying it.'); return; }
                    $a->forceFill(['completion_verified_by_user_id' => $me->id, 'completion_verified_at' => now(), 'completion_verify_note' => $reason ?: null])->save();
                    $logMore = ['verified_by' => $me->id, 'completed_by' => $a->completed_by_user_id, 'self_verified' => (int) $a->completed_by_user_id === (int) $me->id ? true : null, 'evidence' => SubcontractClaims::evidenceCounts([$a->id])[$a->id] ?? 0];
                    break;
                case 'unverify-completion':
                    $this->need('verify-subcontract-completion');
                    if ($from !== A::COMPLETED || ! $a->completion_verified_at) { $err = __('Only a verified completion can be withdrawn.'); return; }
                    if ($reason === '') { $err = __('Say why the verification is withdrawn.'); return; }
                    if (\Illuminate\Support\Facades\Schema::hasTable('subcontract_claim_lines') && DB::table('subcontract_claim_lines as l')->join('subcontract_claims as c', 'c.id', '=', 'l.claim_id')->where('l.agreement_id', $a->id)->whereNull('l.deleted_at')->whereNull('c.deleted_at')->whereIn('l.status', ['Pending', 'Returned', 'Deferred', 'Approved'])->exists()) {
                        $err = __('This agreement is on a payment request that is waiting or approved: cancel or take back those lines first.'); return;
                    }
                    $a->forceFill(['completion_verified_by_user_id' => null, 'completion_verified_at' => null, 'completion_verify_note' => null])->save();
                    break;
                case 'terminate':
                    $this->need('manage-subcontract-agreements');
                    if (! in_array($from, [A::APPROVED, A::ACTIVE, A::SUSPENDED], true)) { $err = __('This agreement cannot be terminated from its status.'); return; }
                    if ($reason === '') { $err = __('Say why it is terminated.'); return; }
                    $a->forceFill(['status' => A::TERMINATED, 'decision_remarks' => $reason, 'actual_completion' => now()->toDateString()])->save();
                    break;
                case 'archive':
                    $this->need('manage-subcontract-agreements');
                    if (! in_array($from, [A::APPROVED, A::ACTIVE, A::SUSPENDED, A::COMPLETED, A::TERMINATED], true)) { $err = __('This agreement cannot be archived from its status.'); return; }
                    $fig = SubcontractLedger::forAgreement($a->id);
                    $open = $a->requests()->whereIn('status', [R::DRAFT, R::SUBMITTED, R::REVIEW, R::RETURNED])->exists() || $a->variations()->whereIn('status', ['Draft', 'Submitted', 'Reviewed'])->exists();
                    if ($open || bccomp($fig['approved_unpaid'], '0', 2) > 0 || bccomp($fig['in_process'], '0', 2) > 0) { $err = __('Finish or cancel its open requests, variations and vouchers before archiving it.'); return; }
                    $extra = $a->extra_attributes ? $a->extra_attributes->toArray() : [];
                    $extra['archived'] = ['from' => $from, 'at' => now()->toDateTimeString(), 'by' => $me->id, 'reason' => $reason ?: null];
                    $a->forceFill(['status' => A::ARCHIVED, 'extra_attributes' => $extra])->save();
                    break;
                case 'restore':
                    $this->need('manage-subcontract-agreements');
                    if ($from !== A::ARCHIVED) { $err = __('Only an archived agreement can be restored.'); return; }
                    $extra = $a->extra_attributes ? $a->extra_attributes->toArray() : [];
                    $back = $extra['archived']['from'] ?? A::COMPLETED;
                    unset($extra['archived']);
                    $a->forceFill(['status' => in_array($back, [A::APPROVED, A::ACTIVE, A::SUSPENDED, A::COMPLETED, A::TERMINATED], true) ? $back : A::COMPLETED, 'extra_attributes' => $extra ?: null])->save();
                    break;
                default:
                    abort(404);
            }
            log_activity('Subcontract agreement ' . $a->reference . ' ' . $action, ['from' => $from, 'to' => $a->status, 'reason' => $reason ?: null] + array_filter($logMore, fn ($v) => $v !== null), $a, 'Subcontracts');
        });
        if ($err) {
            return back()->with('error', e($err));
        }
        $a->refresh();
        if ($event) {
            SubcontractNotifier::fire($event, 'agreement', $a, $me, $a->decision_remarks ?: $a->status);
        }

        return back()->with('message', __(':r is now :s.', ['r' => $a->reference, 's' => strtolower(__($a->status))]));
    }

    public function attach(Request $request, int $agreement)
    {
        $a = $this->agreementOrFail($agreement);
        $this->need('update-subcontract-agreements', 'manage-subcontract-agreements', 'create-subcontract-agreements');
        $request->validate(['attachments' => 'required|array|min:1|max:10', 'attachments.*' => 'file|max:8192|mimes:' . self::FILE_TYPES]);
        $n = $this->saveFiles($a, $request);
        log_activity('Subcontract agreement ' . $a->reference . ' document added', ['files' => $n], $a, 'Subcontracts');

        return back()->with('message', trans_choice(':n document attached.|:n documents attached.', $n, ['n' => $n]));
    }

    public function download(int $agreement, int $file)
    {
        return $this->downloadFile($this->agreementOrFail($agreement), $file, 'Subcontract agreement');
    }

    public function removeFile(int $agreement, int $file)
    {
        $a = $this->agreementOrFail($agreement);
        $this->need('update-subcontract-agreements', 'manage-subcontract-agreements');
        $this->removeFileOf($a, $file, 'Subcontract agreement');

        return back()->with('message', __('Document removed.'));
    }

    // -----------------------------------------------------------------------------------------------------------------

    private function counts($item, $varStatus): bool
    {
        return $item->status === 'active' && (! $item->variation_id || ($varStatus[$item->variation_id] ?? null) === SubcontractVariation::APPROVED);
    }

    private function itemRow($i, bool $money, $varStatus): array
    {
        return ['custom' => SubcontractFields::values('work_item', $i->extra_attributes), 'id' => $i->id, 'group_id' => $i->group_id, 'line_no' => $i->line_no, 'description' => $i->description, 'category' => $i->category, 'beneficiary' => $i->beneficiary_name, 'unit' => $i->unit, 'quantity' => $i->quantity !== null ? (string) $i->quantity : null,
            'rate' => $money && $i->rate !== null ? (string) $i->rate : null, 'pricing_method' => $i->pricing_method, 'amount' => $money ? (string) $i->amount : null, 'item_type' => $i->item_type, 'status' => $i->status, 'remarks' => $i->remarks, 'variation_id' => $i->variation_id,
            'pending_variation' => $i->variation_id && ($varStatus[$i->variation_id] ?? null) !== SubcontractVariation::APPROVED];
    }

    private function abilities(A $a): array
    {
        $draft = $a->status === A::DRAFT;
        $me = auth()->user();

        return [
            'edit' => $a->status !== A::ARCHIVED && ($this->can('update-subcontract-agreements') && $draft || $this->can('manage-subcontract-agreements')), 'edit_schedule' => $draft && ($this->can('create-subcontract-agreements') || $this->can('update-subcontract-agreements')),
            'submit' => $draft && $this->can('submit-subcontract-agreements'), 'review' => $a->status === A::SUBMITTED && $this->can('review-subcontract-agreements'),
            'approve' => in_array($a->status, [A::SUBMITTED, A::REVIEW], true) && $this->can('approve-subcontract-agreements'), 'reject' => in_array($a->status, [A::SUBMITTED, A::REVIEW], true) && ($this->can('approve-subcontract-agreements') || $this->can('review-subcontract-agreements')),
            'manage' => $a->status !== A::ARCHIVED && $this->can('manage-subcontract-agreements'), 'archive' => in_array($a->status, [A::APPROVED, A::ACTIVE, A::SUSPENDED, A::COMPLETED, A::TERMINATED], true) && $this->can('manage-subcontract-agreements'),
            'restore' => $a->status === A::ARCHIVED && $this->can('manage-subcontract-agreements'), 'delete' => $draft && $this->can('delete-subcontract-agreements'),
            'attach' => $this->can('update-subcontract-agreements') || $this->can('manage-subcontract-agreements') || $this->can('create-subcontract-agreements'),
            'variation' => $a->isPayable() && $this->can('create-subcontract-variations'), 'request' => $a->isPayable() && $this->can('create-subcontract-requests'),
            'complete' => in_array($a->status, [A::APPROVED, A::ACTIVE], true) && $this->can('manage-subcontract-agreements'), 'verify_completion' => $this->mayVerify($a), 'unverify_completion' => $a->status === A::COMPLETED && (bool) $a->completion_verified_at && $this->can('verify-subcontract-completion'),
            'claim' => $a->status === A::COMPLETED && $this->can('create-subcontract-claims'), 'claims' => $this->can('read-subcontract-claims'),
            'opening' => $this->money() && ($this->can('read-opening-subcontracts') || $this->can('manage-opening-subcontracts')), 'documents' => $this->can('view-documents-subcontracts'), 'statement' => $this->can('read-subcontract-reports') || $this->can('print-subcontract-reports'), 'print' => $this->can('print-subcontract-reports'),
        ];
    }

    /** May this person verify the completion now: right ability, a completed and not yet verified agreement, not their own completion (a Super Admin may), the evidence the settings ask for is there. */
    private function mayVerify(A $a): bool
    {
        return $a->status === A::COMPLETED && ! $a->completion_verified_at && $this->can('verify-subcontract-completion') && ! $this->selfDecision($a->completed_by_user_id)
            && (! SubcontractClaims::requiresEvidence() || $a->attachments()->count() > 0);
    }

    /** The completion state of an agreement for its page: who completed it and when, who verified it, the evidence, and whether it can be claimed (payment requested). */
    private function completionBlock(A $a, bool $money): array
    {
        $ev = SubcontractClaims::evidenceCounts([$a->id])[$a->id] ?? 0;
        $block = $a->status === A::COMPLETED ? SubcontractClaims::blockReason($a, $ev) : 'not_completed';
        $names = DB::table('users')->whereIn('id', array_filter([$a->completed_by_user_id, $a->completion_verified_by_user_id]) ?: [0])->pluck('name', 'id');
        $state = $a->status !== A::COMPLETED ? 'not_completed' : ($a->completion_verified_at ? 'verified' : 'awaiting_verification');
        $why = null;
        if ($a->status === A::COMPLETED && ! $a->completion_verified_at && $this->can('verify-subcontract-completion')) {
            $why = $this->selfDecision($a->completed_by_user_id) ? __('You completed this agreement, so someone else must verify the completion.') : (SubcontractClaims::requiresEvidence() && $ev < 1 ? __('Attach at least one document to the agreement as evidence of completion before verifying it.') : null);
        }

        return ['state' => $state, 'state_label' => ['not_completed' => __('Not completed'), 'awaiting_verification' => __('Completed, waiting for verification'), 'verified' => __('Completed and verified')][$state],
            'actual_completion' => optional($a->actual_completion)->toDateString(), 'completed_by' => $names[$a->completed_by_user_id] ?? null, 'completed_at' => optional($a->completed_at)->toDateTimeString(), 'note' => $a->completion_note,
            'verified' => (bool) $a->completion_verified_at, 'verified_by' => $names[$a->completion_verified_by_user_id] ?? null, 'verified_at' => optional($a->completion_verified_at)->toDateTimeString(), 'verify_note' => $a->completion_verify_note,
            'evidence_count' => $ev, 'requires_verification' => SubcontractClaims::requiresVerification(), 'requires_evidence' => SubcontractClaims::requiresEvidence(),
            'eligible' => $block === null, 'blocked' => $block, 'blocked_message' => SubcontractClaims::blockMessage($block), 'verify_blocked_reason' => $why,
            'remaining_eligible' => $money && $a->status === A::COMPLETED ? SubcontractLedger::forAgreement($a->id)['remaining_eligible'] ?? null : null,
            'work_groups_note' => __('The agreement is the unit that is paid: work groups are not paid on their own.')];
    }

    /** The Project(s) / Warehouse(s) the work groups of these agreements were mapped to (Project Name Mapping): agreement id => distinct project names. */
    private function mappedProjectNames(array $ids): array
    {
        $out = [];
        if (! $ids) {
            return $out;
        }
        foreach (DB::table('subcontract_work_groups as g')->join('warehouses as w', 'w.id', '=', 'g.warehouse_id')->whereIn('g.agreement_id', $ids)->whereNull('g.deleted_at')->orderBy('g.agreement_id')->orderBy('g.sort_order')->orderBy('g.id')->get(['g.agreement_id', 'w.name']) as $r) {
            $out[$r->agreement_id][$r->name] = $r->name;
        }

        return array_map('array_values', $out);
    }

    /** What the Project column shows: the agreement's own project, or the projects of its mapped groups when there are several (or when it has none of its own). */
    /** the same projects as projectLabel(), one by one (the agreement page shows each as its own chip) */
    private function projectList(A $a, array $mapped): array
    {
        $own = optional($a->warehouse)->name;
        $all = count($mapped) >= 2 ? ($own && ! in_array($own, $mapped, true) ? array_merge([$own], $mapped) : $mapped) : array_filter([$own ?: ($mapped[0] ?? null)]);

        return array_values($all);
    }

    private function projectLabel(A $a, array $mapped): ?string
    {
        $own = optional($a->warehouse)->name;
        if (count($mapped) >= 2) {
            return implode(', ', $own && ! in_array($own, $mapped, true) ? array_merge([$own], $mapped) : $mapped);
        }

        return $own ?: ($mapped[0] ?? null);
    }

    public function row(A $a, ?array $f): array
    {
        return ['id' => $a->id, 'reference' => $a->reference, 'title' => $a->title, 'status' => $a->status, 'status_label' => __($a->status), 'contact_id' => $a->contact_id, 'contact' => optional($a->contact)->name, 'warehouse_id' => $a->warehouse_id, 'project' => optional($a->warehouse)->name,
            'site' => $a->site, 'currency' => $a->currency, 'agreement_type' => $a->agreement_type, 'agreement_date' => optional($a->agreement_date)->toDateString(), 'expected_completion' => optional($a->expected_completion)->toDateString(), 'administrator' => optional($a->administrator)->name,
            'administrator_user_id' => $a->administrator_user_id, 'figures' => $f, 'custom' => SubcontractFields::values('agreement', $a->extra_attributes)];
    }

    private function formProps(?A $a): array
    {
        $s = SubcontractSettings::get();

        return ['a' => $a ? ['id' => $a->id, 'reference' => $a->reference, 'status' => $a->status, 'contact_id' => $a->contact_id, 'warehouse_id' => $a->warehouse_id, 'site' => $a->site, 'title' => $a->title, 'scope' => $a->scope, 'agreement_type' => $a->agreement_type, 'currency' => $a->currency,
            'original_amount' => $this->money() ? (string) $a->original_amount : null, 'agreement_date' => optional($a->agreement_date)->toDateString(), 'start_date' => optional($a->start_date)->toDateString(), 'expected_completion' => optional($a->expected_completion)->toDateString(),
            'administrator_user_id' => $a->administrator_user_id, 'payment_terms' => $a->payment_terms, 'advance_terms' => $a->advance_terms, 'retention_percent' => (string) $a->retention_percent, 'vat_treatment' => $a->vat_treatment, 'remarks' => $a->remarks, 'custom' => SubcontractFields::values('agreement', $a->extra_attributes)] : null, 'fields' => SubcontractFields::forPage('agreement'),
            'contacts' => $this->subcontractors(), 'projects' => $this->projects(), 'currencies' => collect($s['currencies'])->map(fn ($c) => ['value' => $c, 'label' => $c])->values(),
            'types' => collect(A::TYPES)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(), 'staff' => User::query()->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            'newContactUrl' => route('contacts.create', ['category' => 'SubContractors']), 'default_retention' => $s['default_retention_percent'], 'money' => $this->money(), 'attachments' => $a ? $this->filesOf($a) : [], 'today' => now()->toDateString()];
    }

    private function validated(Request $request, ?A $existing): array
    {
        $s = SubcontractSettings::get();
        $d = SubcontractFields::validate($request, 'agreement', [
            'contact_id' => 'required|integer', 'warehouse_id' => 'nullable|integer|exists:warehouses,id', 'site' => 'nullable|string|max:190', 'title' => 'required|string|max:190', 'scope' => 'nullable|string|max:5000',
            'agreement_type' => 'required|in:' . implode(',', array_keys(A::TYPES)), 'currency' => 'required|in:' . implode(',', array_unique(array_merge($s['currencies'], $existing ? [$existing->currency] : []))),
            'original_amount' => 'nullable|numeric|regex:/^-?\d+(\.\d+)?$/|min:0|max:99999999999999', 'agreement_date' => 'nullable|date', 'start_date' => 'nullable|date', 'expected_completion' => 'nullable|date|after_or_equal:start_date',
            'administrator_user_id' => 'nullable|integer|exists:users,id', 'payment_terms' => 'nullable|string|max:3000', 'advance_terms' => 'nullable|string|max:3000', 'retention_percent' => 'nullable|numeric|regex:/^-?\d+(\.\d+)?$/|min:0|max:100',
            'vat_treatment' => 'nullable|string|max:30', 'remarks' => 'nullable|string|max:3000',
        ] + $this->fileRules(), $existing ? ($existing->extra_attributes ?: []) : null);
        $errors = [];
        if (! Contact::query()->where('id', $d['contact_id'])->where('category', 'SubContractors')->exists()) {
            $errors['contact_id'] = __('Choose a subcontractor from the SubContractors list in Contacts.');
        }
        $allowed = accessible_warehouse_ids();
        if (! empty($d['warehouse_id']) && ($allowed !== null && ! in_array((int) $d['warehouse_id'], $allowed, true))) {
            $errors['warehouse_id'] = __('You may not use this project.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        unset($d['attachments']);
        $d['original_amount'] = SubcontractAmounts::money((string) ($d['original_amount'] ?? '0'));
        if ($existing && ! $this->money()) {
            unset($d['original_amount']); // a person who may not see money cannot overwrite it with the blank field of the form
        }
        if (array_key_exists('retention_percent', $d) || ! $existing) {
            $d['retention_percent'] = $d['retention_percent'] ?? 0;
        }
        foreach (['site', 'scope', 'payment_terms', 'advance_terms', 'vat_treatment', 'remarks'] as $k) {
            if (array_key_exists($k, $d)) {
                $d[$k] = isset($d[$k]) && trim((string) $d[$k]) !== '' ? trim((string) $d[$k]) : null;
            }
        }

        return $d;
    }
}
