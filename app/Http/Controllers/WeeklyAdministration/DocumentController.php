<?php

namespace App\Http\Controllers\WeeklyAdministration;

use App\Http\Controllers\Controller;
use App\Mail\ReportMail;
use App\Models\AdminDocument;
use App\Models\AdminDocumentSend;
use App\Models\AdminItem;
use App\Models\User;
use App\Services\Administration\AdminDocumentService;
use App\Services\Administration\AdminNotifier;
use App\Support\PrintQrCode;
use App\Support\ReferenceLock;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

/**
 * Administration: the weekly document in three stages (Requested, Approved, Authorized). It is made from the template of standing items or from zero, has an
 * optional Charity section pulled from the Charity list, can be duplicated to the next week (with the password of the person doing it) and sent by email.
 * Every action is logged; every document has a QR code, a print and a PDF.
 */
class DocumentController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]); // every action is checked below, by name
    }

    private function need(string $ability): void
    {
        abort_unless(auth()->user()->can($ability), 403);
    }

    private function visible()
    {
        return AdminDocument::query()->visibleTo(auth()->user());
    }

    /** A query value as plain text: an array (?search[]=x) or anything else odd becomes empty instead of an error. */
    private function qs(Request $r, string $k): string
    {
        $v = $r->query($k, '');

        return is_string($v) ? trim($v) : '';
    }

    /** The staff member / contact of a filter: the combined dropdown value ("s:5", "c:7") or the plain staff_id / contact_id. */
    private function personIds(array $f): array
    {
        $p = \App\Services\Administration\AdminCategories::parsePerson($f['person'] ?? '');
        $sid = $p['staff_id'] ?: (ctype_digit((string) ($f['staff_id'] ?? '')) && ($f['staff_id'] ?? '') !== '' ? (int) $f['staff_id'] : null);
        $cid = $p['contact_id'] ?: (ctype_digit((string) ($f['contact_id'] ?? '')) && ($f['contact_id'] ?? '') !== '' ? (int) $f['contact_id'] : null);

        return ['staff_id' => $sid, 'contact_id' => $sid ? null : $cid];
    }

    private function authorizeView(AdminDocument $d): void
    {
        abort_unless($this->visible()->whereKey($d->id)->exists(), 404);
    }

    // ---------------------------------------------------------------------------------------------------- list

    public function index(Request $request)
    {
        $this->need('read-weekly-administration');
        $f = ['search' => $this->qs($request, 'search'), 'status' => $this->qs($request, 'status'), 'from' => $this->qs($request, 'from'), 'to' => $this->qs($request, 'to'),
            'beneficiary_id' => $this->qs($request, 'beneficiary_id'), 'item_id' => $this->qs($request, 'item_id'), 'charity' => $this->qs($request, 'charity'), 'mine' => $this->qs($request, 'mine'), 'pay' => $this->qs($request, 'pay'), 'category' => $this->qs($request, 'category'), 'staff_id' => $this->qs($request, 'staff_id'), 'contact_id' => $this->qs($request, 'contact_id'), 'person' => $this->qs($request, 'person'), 'hq' => $this->qs($request, 'hq')];
        $pid = $this->personIds($f);
        $like = fn (string $v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
        $me = $request->user();
        $base = $this->visible()
            ->when(in_array($f['status'], AdminDocument::STATUSES, true), fn ($q) => $q->where('status', $f['status']))
            ->when($f['from'] !== '' && strtotime($f['from']), fn ($q) => $q->whereDate('doc_date', '>=', $f['from']))->when($f['to'] !== '' && strtotime($f['to']), fn ($q) => $q->whereDate('doc_date', '<=', $f['to']))
            ->when(ctype_digit($f['beneficiary_id']) && $f['beneficiary_id'] !== '', fn ($q) => $q->where('beneficiary_user_id', (int) $f['beneficiary_id']))
            ->when(ctype_digit($f['item_id']) && $f['item_id'] !== '', fn ($q) => $q->whereHas('lines', fn ($l) => $l->where('admin_item_id', (int) $f['item_id'])))
            ->when($f['charity'] === '1', fn ($q) => $q->where('include_charity', true))->when($f['charity'] === '0', fn ($q) => $q->where('include_charity', false))
            ->when(in_array($f['pay'], ['Unpaid', 'Partially Paid', 'Paid'], true), fn ($q) => $q->where('status', AdminDocument::AUTHORIZED)->where('payment_status', $f['pay']))
            ->when($f['category'] !== '' || $pid['staff_id'] || $pid['contact_id'], fn ($q) => $q->whereHas('lines', fn ($l) => $l->when($f['category'] !== '', fn ($x) => $x->where('category', $f['category']))->when($pid['staff_id'], fn ($x, $v) => $x->where('staff_id', $v))->when($pid['contact_id'], fn ($x, $v) => $x->where('contact_id', $v))))
            ->when($f['hq'] !== '', fn ($q) => $q->where('hq', $f['hq']))
            ->when($f['mine'] === '1', fn ($q) => $q->where(fn ($w) => $w->where('user_id', $me->id)->orWhere('beneficiary_user_id', $me->id)))
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', $like($f['search']))->orWhere('beneficiary_name', 'like', $like($f['search']))->orWhere('notes', 'like', $like($f['search']))
                ->orWhereHas('lines', fn ($l) => $l->where('description', 'like', $like($f['search']))->orWhere('beneficiary', 'like', $like($f['search'])))
                ->orWhereHas('charityLines', fn ($l) => $l->where('description', 'like', $like($f['search'])))));
        $rows = (clone $base)->with(['user:id,name', 'approvedBy:id,name', 'authorizedBy:id,name', 'charityLines:id,admin_document_id,name,amount'])->withCount(['lines', 'charityLines'])->orderByDesc('doc_date')->orderByDesc('id')->paginate(20)->withQueryString();
        $rows->through(fn ($d) => $this->row($d));
        $counts = $this->visible()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        // the money of what is listed: what is authorized is spent; what is waiting is still to be decided
        $money = (clone $base)->when($f['status'] === '', fn ($q) => $q->whereIn('status', [AdminDocument::REQUESTED, AdminDocument::APPROVED, AdminDocument::AUTHORIZED]))
            ->selectRaw('status, SUM(items_total) i, SUM(charity_total) c, SUM(total_amount) t, SUM(paid_total) p')->groupBy('status')->get()->keyBy('status');
        $sum = fn ($statuses, $col) => number_format((float) collect($statuses)->sum(fn ($s) => (float) optional($money->get($s))->{$col}), 2, '.', '');

        return Inertia::render('WeeklyAdministration/Index', [
            'rows' => $rows, 'filters' => $f, 'statuses' => collect(AdminDocument::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => __($s)])->values(),
            'users' => $this->userOptions(), 'items' => AdminItem::query()->orderBy('name')->get(['id', 'name'])->map(fn ($i) => ['value' => $i->id, 'label' => $i->name]),
            'categories' => \App\Services\Administration\AdminCategories::list(), 'people' => \App\Services\Administration\AdminCategories::peopleOptions(true), 'hqs' => AdminDocument::hqs(),
            'payStats' => ['Unpaid' => $this->visible()->where('status', AdminDocument::AUTHORIZED)->where('payment_status', 'Unpaid')->count(), 'Partially Paid' => $this->visible()->where('status', AdminDocument::AUTHORIZED)->where('payment_status', 'Partially Paid')->count(), 'Paid' => $this->visible()->where('status', AdminDocument::AUTHORIZED)->where('payment_status', 'Paid')->count()],
            'stats' => ['total' => (int) $counts->sum(), 'requested' => (int) ($counts[AdminDocument::REQUESTED] ?? 0), 'approved' => (int) ($counts[AdminDocument::APPROVED] ?? 0), 'authorized' => (int) ($counts[AdminDocument::AUTHORIZED] ?? 0),
                'rejected' => (int) ($counts[AdminDocument::REJECTED] ?? 0), 'cancelled' => (int) ($counts[AdminDocument::CANCELLED] ?? 0)],
            'totals' => ['currency' => AdminDocument::currency(), 'all' => $sum(array_keys($money->all()), 't'), 'authorized' => $sum([AdminDocument::AUTHORIZED], 't'), 'waiting' => $sum([AdminDocument::REQUESTED, AdminDocument::APPROVED], 't'), 'charity' => $sum(array_keys($money->all()), 'c'), 'paid' => $sum([AdminDocument::AUTHORIZED], 'p'), 'to_pay' => number_format(max(0, (float) $sum([AdminDocument::AUTHORIZED], 't') - (float) $sum([AdminDocument::AUTHORIZED], 'p')), 2, '.', '')],
            'can' => $this->can($me),
        ]);
    }

    private function can(User $me): array
    {
        return ['create' => $me->can('create-weekly-administration'), 'print' => $me->can('print-weekly-administration'), 'send' => $me->can('send-weekly-administration'), 'duplicate' => $me->can('duplicate-weekly-administration'),
            'charity' => $me->can('read-administration-charity'), 'items' => $me->can('manage-administration-items'), 'settings' => $me->can('manage-administration-settings')];
    }

    // ---------------------------------------------------------------------------------------------------- create / edit

    public function create(Request $request)
    {
        $this->need('create-weekly-administration');
        $me = $request->user();

        return Inertia::render('WeeklyAdministration/Form', $this->formProps(null) + ['mode' => in_array($request->query('mode'), ['template', 'blank'], true) ? $request->query('mode') : null]);
    }

    public function store(Request $request)
    {
        $this->need('create-weekly-administration');
        $d = $this->validated($request);
        $me = $request->user();
        $doc = ReferenceLock::run('admin_documents', fn () => DB::transaction(function () use ($d, $me) {
            $b = $this->beneficiaryOf($d, $me);
            $doc = AdminDocument::create(['reference' => AdminDocument::nextReference(), 'hq' => $d['hq'], 'status' => AdminDocument::REQUESTED, 'doc_date' => $d['doc_date'] ?? now()->toDateString(), 'department' => AdminDocument::departmentName(),
                'currency' => AdminDocument::currency(), 'beneficiary_user_id' => $b->id, 'beneficiary_name' => $b->name, 'include_charity' => (bool) $d['include_charity'], 'notes' => $d['notes'] ?? null, 'source' => $d['source'] ?? 'blank']);
            AdminDocumentService::saveLines($doc, $d['lines']);
            AdminDocumentService::saveCharity($doc, $d['include_charity'] ? $d['charity'] : []);

            return $doc->recalculate();
        }));
        log_activity('Administration ' . $doc->reference . ' requested', ['hq' => $doc->hq, 'date' => $doc->doc_date->toDateString(), 'lines' => $doc->lines()->count(), 'charity' => $doc->include_charity ? $doc->charityLines()->count() : 0, 'total' => $doc->total_amount, 'source' => $doc->source], $doc, 'Administration');
        AdminNotifier::fire('requested', $doc, $me);

        return redirect()->route('weekly-administration.show', $doc->id)->with('message', __(':r saved. It now waits for approval.', ['r' => $doc->reference]));
    }

    public function edit(AdminDocument $document)
    {
        $this->authorizeEdit($document);
        $document->load(['lines', 'charityLines']);

        return Inertia::render('WeeklyAdministration/Form', $this->formProps($document) + ['mode' => 'edit']);
    }

    public function update(Request $request, AdminDocument $document)
    {
        $this->authorizeEdit($document);
        $d = $this->validated($request);
        $me = $request->user();
        $wasRejected = false;
        DB::transaction(function () use ($document, $d, $me, &$wasRejected) {
            $doc = AdminDocument::withoutGlobalScopes()->lockForUpdate()->find($document->id);
            abort_unless(in_array($doc->status, [AdminDocument::REQUESTED, AdminDocument::REJECTED], true), 422, __('Only a document that is still waiting for approval can be changed.'));
            $wasRejected = $doc->status === AdminDocument::REJECTED;
            $b = $this->beneficiaryOf($d, $me, $doc);
            $doc->forceFill(['hq' => $d['hq'], 'doc_date' => $d['doc_date'] ?? $doc->doc_date, 'beneficiary_user_id' => $b->id, 'beneficiary_name' => $b->name, 'include_charity' => (bool) $d['include_charity'], 'notes' => $d['notes'] ?? null,
                'status' => AdminDocument::REQUESTED, 'rejected_by_user_id' => null, 'rejected_at' => null, 'rejection_reason' => null])->save();
            AdminDocumentService::saveLines($doc, $d['lines']);
            AdminDocumentService::saveCharity($doc, $d['include_charity'] ? $d['charity'] : []);
            $doc->recalculate();
        });
        $document->refresh();
        log_activity('Administration ' . $document->reference . ' updated', ['lines' => $document->lines()->count(), 'charity' => $document->include_charity ? $document->charityLines()->count() : 0, 'total' => $document->total_amount, 'resubmitted' => $wasRejected], $document, 'Administration');
        if ($wasRejected) {
            AdminNotifier::fire('requested', $document, $me);
        }

        return redirect()->route('weekly-administration.show', $document->id)->with('message', __('Saved. It still needs approval.'));
    }

    // ---------------------------------------------------------------------------------------------------- show

    public function show(AdminDocument $document)
    {
        $this->need('read-weekly-administration');
        $this->authorizeView($document);
        $document->load(['lines.staff:id,name', 'lines.contact:id,name', 'charityLines', 'user:id,name', 'beneficiary:id,name', 'approvedBy:id,name', 'authorizedBy:id,name', 'rejectedBy:id,name', 'duplicatedFrom:id,reference', 'sends.user:id,name']);
        $me = auth()->user();
        $a = $this->abilities($document, $me);

        return Inertia::render('WeeklyAdministration/Show', [
            'd' => $this->row($document) + [
                'content_hash' => $document->contentHash(), 'notes' => $document->notes, 'department' => $document->department, 'approval_remarks' => $document->approval_remarks, 'authorization_remarks' => $document->authorization_remarks, 'rejection_reason' => $document->rejection_reason,
                'cancel_reason' => $document->cancel_reason, 'rejected_by' => optional($document->rejectedBy)->name, 'rejected_at' => optional($document->rejected_at)->toDateTimeString(), 'cancelled_at' => optional($document->cancelled_at)->toDateTimeString(),
                'duplicated_from' => $document->duplicatedFrom ? ['id' => $document->duplicatedFrom->id, 'reference' => $document->duplicatedFrom->reference] : null, 'created_at' => $document->created_at->toDateTimeString(),
                'approved_at' => optional($document->approved_at)->toDateTimeString(), 'authorized_at' => optional($document->authorized_at)->toDateTimeString(),
                'lines' => $document->lines->values()->map(fn ($l, $i) => ['no' => $i + 1, 'description' => $l->description, 'beneficiary' => $l->beneficiary, 'category' => $l->category, 'person' => optional($l->staff)->name ?: optional($l->contact)->name, 'person_type' => $l->staff_id ? 'staff' : ($l->contact_id ? 'contact' : null), 'amount' => (string) $l->amount])->all(),
                'charity_lines' => $document->charityLines->values()->map(fn ($c, $i) => ['no' => $i + 1, 'description' => $c->description, 'amount' => (string) $c->amount, 'monthly_amount' => (string) $c->monthly_amount])->all(),
            ],
            'sends' => $document->sends->take(10)->map(fn ($s) => ['id' => $s->id, 'at' => $s->created_at->toDateTimeString(), 'by' => optional($s->user)->name, 'recipients' => (array) $s->recipients, 'super_admins' => $s->to_super_admins, 'status' => $s->status, 'error' => $s->error])->values(),
            'activity' => Activity::query()->where('subject_type', AdminDocument::class)->where('subject_id', $document->id)->orderByDesc('id')->limit(30)->get(['id', 'description', 'created_at', 'causer_id'])
                ->map(fn ($x) => ['id' => $x->id, 'text' => $x->description, 'at' => $x->created_at->toDateTimeString(), 'by' => optional(User::withoutGlobalScopes()->find($x->causer_id))->name])->values(),
            'can' => $a,
            'users' => $this->userOptions(),
            'pay' => $document->status === AdminDocument::AUTHORIZED || $document->payments()->exists() ? [
                'lines' => \App\Services\Administration\AdminPaymentService::lineTable($document),
                'payments' => $document->payments()->with(['voucher:id,pv_number,status,payment_voucher_batch_id', 'user:id,name'])->get()->map(fn ($p) => ['id' => $p->id, 'reference' => $p->reference, 'status' => $p->status, 'source' => $p->source, 'paid_on' => optional($p->paid_on)->toDateString(), 'requested_on' => optional($p->requested_on)->toDateString(),
                    'amount' => (string) $p->total_amount, 'to' => $p->paid_to_name, 'to_type' => $p->payee_type, 'method' => $p->method, 'payment_reference' => $p->payment_reference, 'notes' => $p->notes, 'by' => optional($p->user)->name,
                    'voucher' => $p->voucher ? ['id' => $p->voucher->id, 'pv_number' => $p->voucher->pv_number, 'status' => $p->voucher->status] : null, 'release_reason' => $p->release_reason, 'reverse_reason' => $p->reverse_reason, 'reversed_at' => optional($p->reversed_at)->toDateTimeString(),
                    'lines' => $p->lines()->count(), 'distribute' => $p->isDistribution(), 'batch' => optional($p->voucher)->payment_voucher_batch_id, 'can_reverse' => $p->status === 'Paid' && $p->source === 'direct'])->values(),
                'payees' => $a['pay'] || $a['voucher'] ? \App\Services\Administration\AdminPaymentService::payeeOptions() : [], 'methods' => \App\Services\Administration\AdminPaymentService::METHODS,
                'default_payee' => $document->beneficiary_user_id ? 'u:' . $document->beneficiary_user_id : '',
            ] : null,
        ]);
    }

    private function abilities(AdminDocument $d, User $me): array
    {
        $own = (int) $d->user_id === (int) $me->id;
        $sa = $me->hasRole('Super Admin');
        $req = $d->status === AdminDocument::REQUESTED;
        $appr = $d->status === AdminDocument::APPROVED;

        return [
            'edit' => in_array($d->status, [AdminDocument::REQUESTED, AdminDocument::REJECTED], true) && ($me->can('update-weekly-administration') || ($own && $me->can('create-weekly-administration'))),
            'approve' => $req && $me->can('approve-weekly-administration') && ((! $own && (int) $d->beneficiary_user_id !== (int) $me->id) || $sa),
            'authorize' => $appr && $me->can('authorize-weekly-administration') && ((int) $d->approved_by_user_id !== (int) $me->id || $sa) && ((! $own && (int) $d->beneficiary_user_id !== (int) $me->id) || $sa),
            'reject' => ($req && $me->can('approve-weekly-administration')) || ($appr && $me->can('authorize-weekly-administration')),
            'cancel' => (in_array($d->status, [AdminDocument::REQUESTED, AdminDocument::REJECTED], true) && ($own || $me->can('cancel-weekly-administration') || $me->can('approve-weekly-administration'))) || (in_array($d->status, [AdminDocument::APPROVED, AdminDocument::AUTHORIZED], true) && $me->can('cancel-weekly-administration')),
            'print' => $me->can('print-weekly-administration'), 'send' => $me->can('send-weekly-administration') && $d->status !== AdminDocument::CANCELLED,
            'duplicate' => $me->can('duplicate-weekly-administration') && $me->can('create-weekly-administration'),
            'pay' => $d->status === AdminDocument::AUTHORIZED && $me->can('mark-paid-weekly-administration'), 'voucher' => $d->status === AdminDocument::AUTHORIZED && $me->can('create-payment-voucher-from-administration'),
            'reverse' => $me->can('reverse-payments-weekly-administration'),
        ];
    }

    // ---------------------------------------------------------------------------------------------------- the three stages

    public function approve(Request $request, AdminDocument $document)
    {
        $this->need('approve-weekly-administration');
        $this->authorizeView($document);
        $me = $request->user();
        if (((int) $document->user_id === (int) $me->id || (int) $document->beneficiary_user_id === (int) $me->id) && ! $me->hasRole('Super Admin')) {
            return back()->with('error', __('You requested this document (or it is for you), so someone else must approve it.'));
        }
        $remarks = trim((string) $request->input('remarks', ''));
        $seen = (string) $request->input('seen', '');
        $stale = false;
        $done = DB::transaction(function () use ($document, $me, $remarks, $seen, &$stale) {
            $d = AdminDocument::withoutGlobalScopes()->lockForUpdate()->find($document->id);
            if ($d->status !== AdminDocument::REQUESTED) {
                return false;
            }
            if ($seen !== '' && ! hash_equals($d->contentHash(), $seen)) {
                $stale = true;

                return false;
            }
            $d->forceFill(['status' => AdminDocument::APPROVED, 'approved_by_user_id' => $me->id, 'approved_at' => now(), 'approval_remarks' => $remarks ?: null])->save();

            return true;
        });
        if (! $done) {
            return back()->with('error', $stale ? __('This document was changed after you opened it. Reload the page, read it again and then approve.') : __('This document is no longer waiting for approval.'));
        }
        $document->refresh();
        log_activity('Administration ' . $document->reference . ' approved', ['total' => $document->total_amount, 'remarks' => $remarks ?: null], $document, 'Administration');
        AdminNotifier::fire('approved', $document, $me);

        return back()->with('message', __(':r approved. It now waits for authorization.', ['r' => $document->reference]));
    }

    public function authorizeIt(Request $request, AdminDocument $document)
    {
        $this->need('authorize-weekly-administration');
        $this->authorizeView($document);
        $me = $request->user();
        $sa = $me->hasRole('Super Admin');
        if (((int) $document->user_id === (int) $me->id || (int) $document->beneficiary_user_id === (int) $me->id) && ! $sa) {
            return back()->with('error', __('You requested this document (or it is for you), so someone else must authorize it.'));
        }
        if ((int) $document->approved_by_user_id === (int) $me->id && ! $sa) {
            return back()->with('error', __('You approved this document, so someone else must authorize it.'));
        }
        $remarks = trim((string) $request->input('remarks', ''));
        $seen = (string) $request->input('seen', '');
        $stale = false;
        $done = DB::transaction(function () use ($document, $me, $remarks, $seen, &$stale) {
            $d = AdminDocument::withoutGlobalScopes()->lockForUpdate()->find($document->id);
            if ($d->status !== AdminDocument::APPROVED) {
                return false;
            }
            if ($seen !== '' && ! hash_equals($d->contentHash(), $seen)) {
                $stale = true;

                return false;
            }
            $d->forceFill(['status' => AdminDocument::AUTHORIZED, 'authorized_by_user_id' => $me->id, 'authorized_at' => now(), 'authorization_remarks' => $remarks ?: null])->save();

            return true;
        });
        if (! $done) {
            return back()->with('error', $stale ? __('This document was changed after you opened it. Reload the page, read it again and then authorize.') : __('This document is not waiting for authorization.'));
        }
        $document->refresh();
        log_activity('Administration ' . $document->reference . ' authorized', ['total' => $document->total_amount, 'remarks' => $remarks ?: null], $document, 'Administration');
        AdminNotifier::fire('authorized', $document, $me);

        return back()->with('message', __(':r authorized. All three stages are done.', ['r' => $document->reference]));
    }

    public function reject(Request $request, AdminDocument $document)
    {
        $this->authorizeView($document);
        $me = $request->user();
        $reason = trim($request->validate(['reason' => 'required|string|max:1000'])['reason']);
        $error = null;
        DB::transaction(function () use ($document, $me, $reason, &$error) {
            $d = AdminDocument::withoutGlobalScopes()->lockForUpdate()->find($document->id);
            $ok = ($d->status === AdminDocument::REQUESTED && $me->can('approve-weekly-administration')) || ($d->status === AdminDocument::APPROVED && $me->can('authorize-weekly-administration'));
            if (! $ok) {
                $error = __('You may not reject this document now.');

                return;
            }
            $d->forceFill(['status' => AdminDocument::REJECTED, 'rejected_by_user_id' => $me->id, 'rejected_at' => now(), 'rejection_reason' => $reason, 'approved_by_user_id' => null, 'approved_at' => null, 'approval_remarks' => null])->save();
        });
        if ($error) {
            return back()->with('error', e($error));
        }
        $document->refresh();
        log_activity('Administration ' . $document->reference . ' rejected', ['reason' => $reason], $document, 'Administration');
        AdminNotifier::fire('rejected', $document, $me, $reason);

        return back()->with('message', __(':r rejected. The person who requested it can correct it and send it again.', ['r' => $document->reference]));
    }

    public function cancel(Request $request, AdminDocument $document)
    {
        $this->authorizeView($document);
        $me = $request->user();
        $reason = trim($request->validate(['reason' => 'required|string|max:1000'])['reason']);
        $own = (int) $document->user_id === (int) $me->id;
        $error = null;
        DB::transaction(function () use ($document, $me, $reason, $own, &$error) {
            $d = AdminDocument::withoutGlobalScopes()->lockForUpdate()->find($document->id);
            if (\App\Models\AdminDocumentPayment::query()->where('admin_document_id', $d->id)->whereIn('status', [\App\Models\AdminDocumentPayment::PAID, \App\Models\AdminDocumentPayment::PENDING_VOUCHER])->exists()) {
                $error = __('This document has payments or a Payment Voucher waiting. Reverse the payments or release the voucher first.');

                return;
            }
            if (in_array($d->status, [AdminDocument::REQUESTED, AdminDocument::REJECTED], true)) {
                if (! ($own || $me->can('cancel-weekly-administration') || $me->can('approve-weekly-administration'))) {
                    $error = __('You may not cancel this document.');

                    return;
                }
            } elseif (in_array($d->status, [AdminDocument::APPROVED, AdminDocument::AUTHORIZED], true)) {
                if (! $me->can('cancel-weekly-administration')) {
                    $error = __('You may not cancel an approved document.');

                    return;
                }
            } else {
                $error = __('This document is already cancelled.');

                return;
            }
            $d->forceFill(['status' => AdminDocument::CANCELLED, 'cancelled_by_user_id' => $me->id, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        });
        if ($error) {
            return back()->with('error', e($error));
        }
        $document->refresh();
        log_activity('Administration ' . $document->reference . ' cancelled', ['reason' => $reason], $document, 'Administration');
        AdminNotifier::fire('cancelled', $document, $me, $reason);

        return back()->with('message', __(':r cancelled.', ['r' => $document->reference]));
    }

    // ---------------------------------------------------------------------------------------------------- duplicate, charity pull, send

    /** The next week's document with the same lines. Asks for the password of the person doing it (any signed-in person who may duplicate, not only a Super Admin). */
    public function duplicate(Request $request, AdminDocument $document)
    {
        $this->need('duplicate-weekly-administration');
        $this->need('create-weekly-administration');
        $this->authorizeView($document);
        $me = $request->user();
        $request->validate(['password' => 'required|string']);
        if (! Hash::check((string) $request->input('password'), (string) $me->password)) {
            log_activity('Administration ' . $document->reference . ' duplicate refused: wrong password', ['ip' => $request->ip()], $document, 'Administration');
            throw ValidationException::withMessages(['password' => __('That password is incorrect.')]);
        }
        $copy = ReferenceLock::run('admin_documents', fn () => AdminDocumentService::duplicate($document, $me));
        log_activity('Administration ' . $copy->reference . ' duplicated from ' . $document->reference, ['from' => $document->reference, 'date' => $copy->doc_date->toDateString(), 'total' => $copy->total_amount], $copy, 'Administration');
        log_activity('Administration ' . $document->reference . ' duplicated', ['to' => $copy->reference], $document, 'Administration');
        AdminNotifier::fire('duplicated', $copy, $me);
        AdminNotifier::fire('requested', $copy, $me);

        return redirect()->route('weekly-administration.show', $copy->id)->with('message', __(':r made from :s with the date moved to :d. Change what is needed and it waits for approval.', ['r' => $copy->reference, 's' => $document->reference, 'd' => $copy->doc_date->format('d M Y')]));
    }

    /** The Charity section as it would be pulled now (JSON): every active person of the Charity list with the monthly amount; the month is the one of the document. */
    public function charityPull(Request $request)
    {
        abort_unless($request->user()->can('read-administration-charity') && ($request->user()->can('create-weekly-administration') || $request->user()->can('update-weekly-administration')), 403);
        $date = $request->query('date') && strtotime((string) $request->query('date')) ? Carbon::parse((string) $request->query('date')) : now();
        $rows = AdminDocumentService::charityRows($date, $this->qs($request, 'hq') ?: null);
        log_activity('Administration charity pulled', ['people' => count($rows), 'month' => $date->format('Y-m'), 'hq' => $request->query('hq')], null, 'Administration Charity');

        // the same month's charity may already be in another document of this headquarters: say so (it is allowed, but easy to do twice by mistake)
        $hq = $this->qs($request, 'hq');
        $already = AdminDocument::query()->visibleTo($request->user())->where('include_charity', true)->whereNotIn('status', [AdminDocument::CANCELLED, AdminDocument::REJECTED])->when($hq !== '', fn ($q) => $q->where('hq', $hq))
            ->whereYear('doc_date', $date->year)->whereMonth('doc_date', $date->month)->when($request->query('except'), fn ($q, $x) => $q->whereKeyNot((int) $x))->get(['reference', 'status'])->map(fn ($x) => ['reference' => $x->reference, 'status' => $x->status])->values();

        return response()->json(['rows' => $rows, 'month' => $date->translatedFormat('F Y'), 'already' => $already]);
    }

    public function send(Request $request, AdminDocument $document)
    {
        $this->need('send-weekly-administration');
        $this->authorizeView($document);
        abort_if($document->status === AdminDocument::CANCELLED, 422, __('A cancelled document cannot be sent.'));
        $data = $request->validate(['emails' => 'nullable|string|max:1500', 'to_super_admins' => 'nullable|boolean', 'message' => 'nullable|string|max:500']);
        $list = collect(preg_split('/[,;\s]+/', (string) ($data['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))->map(fn ($e) => mb_strtolower(trim($e)))->unique()->values();
        foreach ($list as $e) {
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['emails' => __(':e is not a valid email address.', ['e' => $e])]);
            }
        }
        if ($list->count() > 15) {
            throw ValidationException::withMessages(['emails' => __('Send to 15 addresses or fewer at a time.')]);
        }
        $toSupers = $request->boolean('to_super_admins');
        $all = $list->merge($toSupers ? User::role('Super Admin')->where('is_active', true)->whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower((string) $e)) : collect())->filter()->unique()->values();
        if ($all->isEmpty()) {
            throw ValidationException::withMessages(['emails' => __('Type at least one email address or choose Super Admins.')]);
        }
        $document->load(['lines', 'charityLines', 'user:id,name', 'approvedBy:id,name', 'authorizedBy:id,name']);
        $pdf = $this->pdf($document)->output();
        $file = 'administration-' . $document->reference . '.pdf';
        $title = __('Administration :r (:hq) - :d', ['r' => $document->reference, 'hq' => $document->hq, 'd' => $document->doc_date->format('d M Y')]);
        $failed = [];
        foreach ($all as $email) {
            try {
                Mail::to($email)->send(new ReportMail($title, $pdf, $file, optional($request->user()->account)->name));
            } catch (\Throwable $e) {
                $failed[] = $email;
                \Log::warning('Administration document email failed', ['document' => $document->id, 'email' => $email, 'error' => $e->getMessage()]);
            }
        }
        $sent = $all->diff($failed)->values();
        AdminDocumentSend::create(['admin_document_id' => $document->id, 'user_id' => $request->user()->id, 'recipients' => $all->all(), 'to_super_admins' => $toSupers, 'status' => $failed ? ($sent->isEmpty() ? 'Failed' : 'Partly') : 'Sent', 'error' => $failed ? __('Not delivered to: :l', ['l' => implode(', ', $failed)]) : null]);
        if ($sent->isNotEmpty()) {
            $document->forceFill(['sent_count' => (int) $document->sent_count + 1, 'last_sent_at' => now()])->save();
        }
        log_activity('Administration ' . $document->reference . ' sent by email', ['recipients' => $all->all(), 'super_admins' => $toSupers, 'failed' => $failed, 'format' => 'pdf'], $document, 'Administration');
        if ($sent->isNotEmpty()) {
            AdminNotifier::fire('sent', $document, $request->user(), __('Sent to :l', ['l' => $sent->implode(', ')]));
        }
        if ($sent->isEmpty()) {
            return back()->with('error', __('The email could not be sent. Check the mail settings and try again.'));
        }

        return back()->with('message', $failed ? __('Sent to :n address(es). Not delivered to: :l', ['n' => $sent->count(), 'l' => implode(', ', $failed)]) : __('Sent to :n address(es).', ['n' => $sent->count()]));
    }

    // ---------------------------------------------------------------------------------------------------- print / PDF

    public function print(AdminDocument $document)
    {
        return $this->document($document, false);
    }

    public function pdfDownload(AdminDocument $document)
    {
        return $this->document($document, true);
    }

    private function document(AdminDocument $document, bool $download)
    {
        $this->need('read-weekly-administration');
        $this->need('print-weekly-administration');
        $this->authorizeView($document);
        $document->load(['lines', 'charityLines', 'user:id,name', 'approvedBy:id,name', 'authorizedBy:id,name']);
        log_activity('Administration ' . $document->reference . ($download ? ' downloaded' : ' printed'), ['format' => 'pdf', 'status' => $document->status], $document, 'Administration');
        $pdf = $this->pdf($document);
        $name = 'administration-' . $document->reference . '.pdf';

        return $download ? $pdf->download($name) : $pdf->stream($name);
    }

    private function pdf(AdminDocument $document)
    {
        return Pdf::loadView('weekly-administration.print', [
            'd' => $document, 'isPdf' => true, 'companyName' => \App\Models\PaymentVoucher::COMPANY_NAME, 'qr' => PrintQrCode::dataUri(route('weekly-administration.show', $document->id)), 'printedBy' => auth()->user()->name,
        ])->setPaper('a4', 'portrait');
    }

    // ---------------------------------------------------------------------------------------------------- helpers

    private function authorizeEdit(AdminDocument $d): void
    {
        $me = auth()->user();
        $this->authorizeView($d);
        abort_unless(in_array($d->status, [AdminDocument::REQUESTED, AdminDocument::REJECTED], true) && ($me->can('update-weekly-administration') || ((int) $d->user_id === (int) $me->id && $me->can('create-weekly-administration'))), 403);
    }

    private function userOptions()
    {
        return User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->values();
    }

    private function beneficiaryOf(array $d, User $me, ?AdminDocument $existing = null): User
    {
        if (! empty($d['beneficiary_user_id'])) {
            $u = User::query()->where('is_active', true)->find((int) $d['beneficiary_user_id']);
            if ($u) {
                return $u;
            }
            if ($existing && (int) $existing->beneficiary_user_id === (int) $d['beneficiary_user_id'] && $existing->beneficiary) {
                return $existing->beneficiary;
            }
        }

        return $me;
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'hq' => 'required|string|max:60', 'doc_date' => 'nullable|date', 'beneficiary_user_id' => 'nullable|integer|exists:users,id', 'notes' => 'nullable|string|max:2000', 'include_charity' => 'nullable|boolean', 'source' => 'nullable|in:template,blank',
            'lines' => 'nullable|array|max:300', 'lines.*.description' => 'nullable|string|max:255', 'lines.*.beneficiary' => 'nullable|string|max:190', 'lines.*.beneficiary_user_id' => 'nullable|integer', 'lines.*.admin_item_id' => 'nullable|integer', 'lines.*.category' => 'nullable|string|max:60', 'lines.*.staff_id' => 'nullable|integer', 'lines.*.contact_id' => 'nullable|integer',
            'lines.*.amount' => 'nullable|numeric|min:0|max:999999999999',
            'charity' => 'nullable|array|max:300', 'charity.*.name' => 'nullable|string|max:190', 'charity.*.description' => 'nullable|string|max:255', 'charity.*.admin_charity_person_id' => 'nullable|integer',
            'charity.*.amount' => 'nullable|numeric|min:0|max:999999999999', 'charity.*.monthly_amount' => 'nullable|numeric|min:0',
        ]);
        $hq = trim((string) $d['hq']);
        $known = collect(AdminDocument::hqs())->first(fn ($x) => mb_strtolower($x) === mb_strtolower($hq));
        if (! $known) {
            throw ValidationException::withMessages(['hq' => __('Choose the headquarters of this document: :l.', ['l' => implode(', ', AdminDocument::hqs())])]);
        }
        $d['hq'] = $known;
        $existing = $request->route('document');
        $hadCharity = $existing instanceof AdminDocument && $existing->include_charity;
        if (! empty($d['include_charity']) && ! $hadCharity && ! $request->user()->can('read-administration-charity')) {
            throw ValidationException::withMessages(['include_charity' => __('Adding the Charity section needs the permission to see the Charity list.')]);
        }
        $d['include_charity'] = (bool) ($d['include_charity'] ?? false);
        $d['lines'] = array_values(array_filter((array) ($d['lines'] ?? []), fn ($l) => trim((string) ($l['description'] ?? '')) !== ''));
        $d['charity'] = array_values(array_filter((array) ($d['charity'] ?? []), fn ($c) => trim((string) ($c['description'] ?? $c['name'] ?? '')) !== ''));
        if (! $d['lines'] && ! ($d['include_charity'] && $d['charity'])) {
            throw ValidationException::withMessages(['lines' => __('Add at least one line.')]);
        }
        // the totals are kept with two decimals in a column of 13 whole digits: a document can never add up to more than that
        $cents = fn ($rows) => array_sum(array_map(fn ($r) => (int) round(((float) ($r['amount'] ?? 0)) * 100), $rows));
        if ($cents($d['lines']) + ($d['include_charity'] ? $cents($d['charity']) : 0) > 999999999999999) {
            throw ValidationException::withMessages(['lines' => __('The total of the document is too large.')]);
        }

        return $d;
    }

    private function formProps(?AdminDocument $doc): array
    {
        $me = auth()->user();
        $lines = $doc ? $doc->lines->map(fn ($l) => ['admin_item_id' => $l->admin_item_id, 'description' => $l->description, 'beneficiary' => (string) $l->beneficiary, 'beneficiary_user_id' => $l->beneficiary_user_id, 'category' => $l->category, 'staff_id' => $l->staff_id, 'contact_id' => $l->contact_id, 'amount' => (string) $l->amount])->values()->all() : [];
        $charity = $doc ? $doc->charityLines->map(fn ($c) => ['admin_charity_person_id' => $c->admin_charity_person_id, 'name' => $c->name, 'description' => $c->description, 'amount' => (string) $c->amount, 'monthly_amount' => (string) $c->monthly_amount])->values()->all() : [];

        return [
            'doc' => $doc ? ['id' => $doc->id, 'hq' => $doc->hq, 'reference' => $doc->reference, 'status' => $doc->status, 'doc_date' => $doc->doc_date->toDateString(), 'beneficiary_user_id' => $doc->beneficiary_user_id, 'beneficiary_name' => $doc->beneficiary_name, 'include_charity' => (bool) $doc->include_charity,
                'notes' => $doc->notes, 'rejection_reason' => $doc->rejection_reason, 'lines' => $lines, 'charity' => $charity] : null,
            'template' => AdminDocumentService::templateLines(), 'users' => $this->userOptions(), 'me' => ['id' => $me->id, 'name' => $me->name], 'today' => now()->toDateString(),
            'itemNames' => AdminItem::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'default_beneficiary', 'default_amount', 'category', 'staff_id', 'contact_id'])->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'beneficiary' => $i->default_beneficiary, 'amount' => (string) $i->default_amount, 'category' => $i->category, 'staff_id' => $i->staff_id, 'contact_id' => $i->contact_id]),
            'categories' => \App\Services\Administration\AdminCategories::list(), 'hqs' => AdminDocument::hqs(),
            'people' => \App\Services\Administration\AdminCategories::peopleOptions(),
            'charityPeople' => (int) \App\Models\AdminCharityPerson::query()->where('is_active', true)->count(), 'department' => AdminDocument::departmentName(), 'currency' => AdminDocument::currency(),
            'can' => ['charity' => $me->can('read-administration-charity'), 'items' => $me->can('manage-administration-items')],
        ];
    }

    private function row(AdminDocument $d): array
    {
        return ['id' => $d->id, 'reference' => $d->reference, 'hq' => $d->hq, 'status' => $d->status, 'status_label' => __($d->status), 'doc_date' => $d->doc_date->toDateString(), 'currency' => $d->currency, 'beneficiary' => $d->beneficiary_name, 'include_charity' => (bool) $d->include_charity,
            'items_total' => (string) $d->items_total, 'charity_total' => (string) $d->charity_total, 'charity_people' => $d->include_charity && $d->relationLoaded('charityLines') ? $d->charityLines->take(40)->map(fn ($c) => ['name' => $c->name, 'amount' => (string) $c->amount])->values()->all() : [], 'total_amount' => (string) $d->total_amount, 'lines_count' => (int) ($d->lines_count ?? $d->lines()->count()), 'charity_count' => (int) ($d->charity_lines_count ?? $d->charityLines()->count()),
            'payment_status' => $d->status === AdminDocument::AUTHORIZED ? $d->payment_status : null, 'paid_total' => (string) $d->paid_total, 'reserved_total' => (string) $d->reserved_total, 'balance' => number_format(max(0, (float) $d->total_amount - (float) $d->paid_total), 2, '.', ''),
            'by' => optional($d->user)->name, 'approved_by' => optional($d->approvedBy)->name, 'authorized_by' => optional($d->authorizedBy)->name, 'source' => $d->source, 'sent_count' => (int) $d->sent_count, 'last_sent_at' => optional($d->last_sent_at)->toDateTimeString()];
    }
}
