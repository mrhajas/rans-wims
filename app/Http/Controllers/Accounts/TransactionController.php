<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctApproval;
use App\Models\Accounts\AcctAttachment;
use App\Models\Accounts\AcctAuditEvent;
use App\Models\Accounts\AcctCategory;
use App\Models\Accounts\AcctTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounts\AcctAudit;
use App\Services\Accounts\AcctPosting;
use App\Services\Accounts\AcctRates;
use App\Support\Accounts\AcctException;
use App\Support\Accounts\AcctPermissions;
use App\Support\Accounts\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/** The ledger: every money in and out. Create, submit, approve, post, reverse; attachments; history. All rules live in AcctPosting. */
class TransactionController extends AcctController
{
    public const METHODS = ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'mobile_money' => 'Mobile money', 'card' => 'Card', 'other' => 'Other'];

    public const DOC_TYPES = ['receipt' => 'Receipt', 'voucher' => 'Payment voucher', 'invoice' => 'Invoice', 'bank_confirmation' => 'Bank confirmation', 'approval' => 'Approval document', 'signed_cash_voucher' => 'Signed cash voucher', 'other' => 'Other'];

    public function index(Request $request)
    {
        $this->need('read-acct-transactions');
        $f = $this->filters($request);
        $q = $this->query($f);
        $sort = in_array($request->query('sort'), ['txn_date', 'amount', 'reference', 'status', 'id'], true) ? $request->query('sort') : 'id';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $rows = (clone $q)->with('account:id,name,currency,kind')->orderBy($sort, $dir)->orderByDesc('id')->paginate(20)->withQueryString();
        $creators = User::query()->whereIn('id', $rows->getCollection()->pluck('created_by')->filter()->unique())->pluck('name', 'id');
        $rows->getCollection()->transform(fn ($t) => $this->row($t, $creators));

        $sum = AcctTransaction::operating((clone $q)->whereIn('status', AcctTransaction::LEDGER))->selectRaw("currency, SUM(CASE WHEN direction='in' THEN amount ELSE 0 END) as ins, SUM(CASE WHEN direction='out' THEN amount ELSE 0 END) as outs")->groupBy('currency')->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'in' => (string) $r->ins, 'out' => (string) $r->outs, 'net' => Money::sub((string) $r->ins, (string) $r->outs)])->values();
        $byStatus = (clone $q)->reorder()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('Accounts/Transactions/Index', $this->shared() + [
            'rows' => $rows, 'filters' => $f + ['sort' => $sort, 'dir' => $dir], 'sums' => $sum, 'byStatus' => $byStatus, 'accounts' => $this->accountOptions(null, false),
            'categories' => AcctCategory::query()->orderBy('sort_order')->get(['id', 'name', 'direction'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name]),
            'statuses' => collect(AcctTransaction::STATUS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(), 'methods' => $this->methods(),
            'projects' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'status'])->map(fn ($w) => ['value' => $w->id, 'label' => $w->name, 'status' => $w->status]),
            'users' => User::query()->where('is_active', 1)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            'can' => ['create' => $this->me()->can('create-acct-inflows') || $this->me()->can('create-acct-outflows'), 'inflow' => $this->me()->can('create-acct-inflows'), 'outflow' => $this->me()->can('create-acct-outflows'), 'export' => $this->me()->can('export-acct-transactions') && $this->me()->can('read-acct-reports')],
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($this->me()->can('create-acct-inflows') || $this->me()->can('create-acct-outflows') || $this->me()->can('create-acct-adjustments'), 403);

        return Inertia::render('Accounts/Transactions/Form', $this->formProps(null, $request));
    }

    public function store(Request $request)
    {
        $d = $this->validated($request);
        $files = $request->file('files', []);

        return $this->run(function () use ($d, $request, $files) {
            $svc = app(AcctPosting::class);
            $t = $svc->create($d, $this->me());
            $this->saveFiles($t, $files, $request->input('doc_type'));
            $msg = __('Saved as a draft.');
            if ($request->boolean('submit')) {
                $t = $svc->submit($t, $this->me());
                $msg = __('Submitted for approval. The balance changes only after it is approved and posted.');
            }

            return redirect()->route('acct.transactions.show', $t->id)->with('message', $msg);
        });
    }

    public function show(int $id)
    {
        $this->need('read-acct-transactions');
        $t = $this->txnOrFail($id);
        $me = $this->me();
        $self = in_array((int) $me->id, [(int) $t->created_by, (int) $t->submitted_by], true);
        $trail = AcctApproval::query()->where('subject_type', 'transaction')->where('subject_id', $t->id)->orderBy('id')->get();
        $files = $me->can('view-acct-attachments') ? AcctAttachment::query()->where('subject_type', 'transaction')->where('subject_id', $t->id)->orderBy('id')->get() : collect();
        $history = $me->can('view-acct-audit') ? AcctAuditEvent::query()->where('entity_type', 'transaction')->where('entity_id', $t->id)->orderByDesc('id')->limit(30)->get() : collect();
        // every person named on this page, in one query (it used to ask for each attachment and each history row separately)
        $users = User::query()->whereIn('id', array_filter(array_merge([$t->created_by, $t->submitted_by, $t->posted_by], $trail->pluck('user_id')->all(), $files->pluck('uploaded_by')->all(), $history->pluck('user_id')->all())))->pluck('name', 'id');

        return Inertia::render('Accounts/Transactions/Show', $this->shared() + [
            't' => $this->detail($t, $users), 'timeline' => $trail->map(fn ($a) => ['action' => $a->action, 'user' => $users[$a->user_id] ?? '-', 'role' => $a->role_context, 'comment' => $a->comment, 'at' => $a->created_at->toDateTimeString()])->values(),
            'attachments' => $files->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'doc_type' => $a->doc_type, 'size' => (int) $a->size, 'mime' => $a->mime, 'by' => $users[$a->uploaded_by] ?? null, 'at' => $a->created_at->toDateTimeString()])->values(),
            'history' => $history->map(fn ($e) => ['event' => $e->event, 'user' => $users[$e->user_id] ?? null, 'reason' => $e->reason, 'at' => $e->created_at->toDateTimeString()])->values(),
            'reversal' => $t->reversed_by_id ? AcctTransaction::query()->find($t->reversed_by_id, ['id', 'reference']) : null,
            'reverses' => $t->reverses_id ? AcctTransaction::query()->find($t->reverses_id, ['id', 'reference']) : null,
            'doc_types' => collect(self::DOC_TYPES)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(),
            'can' => [
                'edit' => in_array($t->status, ['draft', 'rejected'], true) && ! $t->transfer_id && ((int) $t->created_by === (int) $me->id || $me->hasRole('Super Admin')),
                'submit' => in_array($t->status, ['draft', 'rejected'], true) && ! $t->transfer_id && ($me->can('submit-acct-transactions') || (int) $t->created_by === (int) $me->id),
                'approve' => $t->status === 'submitted' && $me->can(AcctPermissions::need('approve', $t)) && (! $self || ! \App\Services\Accounts\AcctSettings::get()['maker_checker']) && ! $trail->where('action', 'approved')->where('user_id', $me->id)->count(),
                'reject' => in_array($t->status, ['submitted', 'approved'], true) && $me->can(AcctPermissions::need('approve', $t)),
                'post' => $t->status === 'approved' && $me->can(AcctPermissions::need('post', $t)) && ! $t->transfer_id,
                'cancel' => in_array($t->status, ['draft', 'submitted', 'approved', 'rejected'], true) && ! $t->transfer_id && ((int) $t->created_by === (int) $me->id || $me->can(AcctPermissions::need('approve', $t))),
                'reverse' => $t->status === 'posted' && ! $t->transfer_id && $me->can('reverse-acct-transactions'), 'attach' => $this->canAttach($t),
            ],
        ]);
    }

    public function edit(int $id)
    {
        $t = $this->txnOrFail($id);
        abort_unless(in_array($t->status, ['draft', 'rejected'], true) && ! $t->transfer_id && ((int) $t->created_by === (int) $this->me()->id || $this->me()->hasRole('Super Admin')), 403);

        return Inertia::render('Accounts/Transactions/Form', $this->formProps($t, request()));
    }

    public function update(Request $request, int $id)
    {
        $t = $this->txnOrFail($id);
        $d = $this->validated($request, true);
        $files = $request->file('files', []);

        return $this->run(function () use ($t, $d, $request, $files) {
            $svc = app(AcctPosting::class);
            $t = $svc->update($t, $d, $this->me());
            $this->saveFiles($t, $files, $request->input('doc_type'));
            if ($request->boolean('submit')) {
                $svc->submit($t, $this->me());
            }

            return redirect()->route('acct.transactions.show', $t->id)->with('message', __('Saved.'));
        });
    }

    public function submit(int $id)
    {
        $t = $this->txnOrFail($id);

        return $this->run(function () use ($t) {
            $r = app(AcctPosting::class)->submit($t, $this->me());

            return back()->with('message', $r->status === 'approved' ? __('Submitted. No approval is needed: it can be posted.') : __('Submitted for approval.'));
        });
    }

    public function approve(Request $request, int $id)
    {
        $t = $this->txnOrFail($id);
        $c = $request->validate(['comment' => 'nullable|string|max:500'])['comment'] ?? null;

        return $this->run(function () use ($t, $c) {
            $r = app(AcctPosting::class)->approve($t, $this->me(), $c);

            return back()->with('message', $r->status === 'approved' ? __('Approved. It can now be posted.') : __('Approval recorded (:a of :n).', ['a' => $r->approvals_count, 'n' => $r->required_approvals]));
        });
    }

    public function reject(Request $request, int $id)
    {
        $t = $this->txnOrFail($id);
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];

        return $this->run(fn () => app(AcctPosting::class)->reject($t, $this->me(), $reason), __('Rejected. The creator can correct it and send it again.'));
    }

    public function cancel(Request $request, int $id)
    {
        $t = $this->txnOrFail($id);
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];

        return $this->run(fn () => app(AcctPosting::class)->cancel($t, $this->me(), $reason), __('Cancelled.'));
    }

    public function post(int $id)
    {
        $t = $this->txnOrFail($id);

        return $this->run(fn () => app(AcctPosting::class)->post($t, $this->me()), __('Posted. The balance is updated.'));
    }

    public function reverse(Request $request, int $id)
    {
        $t = $this->txnOrFail($id);
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];

        return $this->run(function () use ($t, $reason) {
            $rev = app(AcctPosting::class)->reverse($t, $this->me(), $reason);

            return redirect()->route('acct.transactions.show', $rev->id)->with('message', __('Reversed. The original is kept and linked to this correction.'));
        });
    }

    // ---- attachments ----

    public function attach(Request $request, int $id)
    {
        $t = $this->txnOrFail($id);
        abort_unless($this->canAttach($t), 403);
        $request->validate(['files' => 'required|array|max:10', 'files.*' => 'file|max:8192|mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt', 'doc_type' => 'nullable|string|max:30']);
        $this->saveFiles($t, $request->file('files', []), $request->input('doc_type'));

        return back()->with('message', __('Attached.'));
    }

    public function download(int $id, int $file)
    {
        $this->need('view-acct-attachments');
        $t = $this->txnOrFail($id);
        $a = AcctAttachment::query()->where('subject_type', 'transaction')->where('subject_id', $t->id)->findOrFail($file);
        abort_unless(Storage::disk('local')->exists($a->path), 404);
        AcctAudit::log(request()->boolean('inline') ? 'attachment.viewed' : 'attachment.downloaded', 'transaction', $t->id, $t->reference, null, ['file' => $a->title], null, $this->me());

        // ?inline=1 shows an image or a PDF in the browser (the preview); anything else, or no flag, is a download
        if (request()->boolean('inline') && in_array(strtolower(pathinfo($a->title, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'webp' => 'image/webp'][strtolower(pathinfo($a->title, PATHINFO_EXTENSION))] ?? 'image/jpeg';

            return response()->file(Storage::disk('local')->path($a->path), ['Content-Type' => $mime, 'Content-Disposition' => 'inline; filename="' . addslashes($a->title) . '"', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox"]);
        }

        return Storage::disk('local')->download($a->path, $a->title, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function removeFile(int $id, int $file)
    {
        $t = $this->txnOrFail($id);
        abort_unless(in_array($t->status, ['draft', 'rejected'], true) && ((int) $t->created_by === (int) $this->me()->id || $this->me()->hasRole('Super Admin')), 403);
        $a = AcctAttachment::query()->where('subject_type', 'transaction')->where('subject_id', $t->id)->findOrFail($file);
        Storage::disk('local')->delete($a->path);
        $a->delete();
        AcctAudit::log('attachment.removed', 'transaction', $t->id, $t->reference, ['file' => $a->title], null, null, $this->me());

        return back()->with('message', __('Removed.'));
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * Evidence can be added by someone who may create or submit this kind of entry. Once the entry is posted (or reversed) the record is closed: only a person
     * who may post this kind of entry can still add to it. A cancelled entry takes nothing more. Being able to READ transactions is never enough.
     */
    private function canAttach(AcctTransaction $t): bool
    {
        $me = $this->me();
        if ($t->status === 'cancelled') {
            return false;
        }
        if (! ($me->can(AcctPermissions::need('create', $t)) || $me->can('submit-acct-transactions'))) {
            return false;
        }

        return ! in_array($t->status, ['posted', 'reversed'], true) || $me->can(AcctPermissions::need('post', $t));
    }

    private function saveFiles(AcctTransaction $t, $files, ?string $docType): void
    {
        foreach ((array) $files as $f) {
            if (! $f || ! $f->isValid()) {
                continue;
            }
            $ext = strtolower($f->getClientOriginalExtension());
            if (! in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'], true) || $f->getSize() > 8 * 1024 * 1024) {
                throw new AcctException(__('That file type or size is not allowed.'));
            }
            $path = $f->storeAs('acct-attachments/' . now()->format('Y/m'), \Illuminate\Support\Str::uuid() . '.' . $ext, 'local');
            AcctAttachment::query()->create(['subject_type' => 'transaction', 'subject_id' => $t->id, 'doc_type' => array_key_exists((string) $docType, self::DOC_TYPES) ? $docType : 'other', 'title' => substr($f->getClientOriginalName(), 0, 190), 'path' => $path,
                'mime' => $f->getMimeType(), 'size' => $f->getSize(), 'uploaded_by' => $this->me()->id, 'created_at' => now()]);
            AcctAudit::log('attachment.added', 'transaction', $t->id, $t->reference, null, ['file' => $f->getClientOriginalName()], null, $this->me());
        }
    }

    private function txnOrFail(int $id): AcctTransaction
    {
        $t = AcctTransaction::query()->findOrFail($id);
        $ids = $this->visibleAccountIds();
        abort_if($ids !== null && ! in_array((int) $t->account_id, $ids, true), 404);

        return $t;
    }

    private function filters(Request $r): array
    {
        $f = [];
        foreach (['q', 'account_id', 'direction', 'kind', 'status', 'category_id', 'currency', 'from', 'to', 'warehouse_id', 'department', 'creator', 'reconciled', 'fund_allocation_id', 'method'] as $k) {
            $f[$k] = trim((string) $r->query($k, ''));
        }

        return $f;
    }

    private function query(array $f)
    {
        $q = $this->scopeAccounts(AcctTransaction::query(), 'account_id');
        $like = fn ($v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';

        return $q->when($f['q'] !== '', fn ($w) => $w->where(fn ($x) => $x->where('reference', 'like', $like($f['q']))->orWhere('description', 'like', $like($f['q']))->orWhere('external_ref', 'like', $like($f['q']))->orWhere('receipt_no', 'like', $like($f['q']))->orWhere('invoice_no', 'like', $like($f['q']))->orWhere('voucher_no', 'like', $like($f['q']))->orWhere('counterparty_name', 'like', $like($f['q']))))
            ->when(ctype_digit($f['account_id']) && $f['account_id'] !== '', fn ($w) => $w->where('account_id', (int) $f['account_id']))
            ->when(in_array($f['direction'], ['in', 'out'], true), fn ($w) => $w->where('direction', $f['direction']))
            ->when(in_array($f['kind'], ['receipt', 'payment', 'transfer', 'fee', 'adjustment', 'reversal'], true), fn ($w) => $w->where('kind', $f['kind']))
            ->when(array_key_exists($f['status'], AcctTransaction::STATUS), fn ($w) => $w->where('status', $f['status']))
            ->when(ctype_digit($f['category_id']) && $f['category_id'] !== '', fn ($w) => $w->where('category_id', (int) $f['category_id']))
            ->when($f['currency'] !== '', fn ($w) => $w->where('currency', $f['currency']))
            ->when($f['from'] !== '' && strtotime($f['from']), fn ($w) => $w->whereDate('txn_date', '>=', $f['from']))->when($f['to'] !== '' && strtotime($f['to']), fn ($w) => $w->whereDate('txn_date', '<=', $f['to']))
            ->when(ctype_digit($f['warehouse_id']) && $f['warehouse_id'] !== '', fn ($w) => $w->where('warehouse_id', (int) $f['warehouse_id']))
            ->when($f['department'] !== '', fn ($w) => $w->where('department', $f['department']))
            ->when(ctype_digit($f['creator']) && $f['creator'] !== '', fn ($w) => $w->where('created_by', (int) $f['creator']))
            ->when($f['reconciled'] === 'yes', fn ($w) => $w->whereNotNull('reconciled_at'))->when($f['reconciled'] === 'no', fn ($w) => $w->whereNull('reconciled_at')->where('status', 'posted'))
            ->when(ctype_digit($f['fund_allocation_id']) && $f['fund_allocation_id'] !== '', fn ($w) => $w->where('fund_allocation_id', (int) $f['fund_allocation_id']))
            ->when($f['method'] !== '', fn ($w) => $w->where('payment_method', $f['method']));
    }

    private function methods(): array
    {
        return collect(self::METHODS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values()->all();
    }

    private function row(AcctTransaction $t, $creators): array
    {
        return ['id' => $t->id, 'reference' => $t->reference, 'date' => $t->txn_date->toDateString(), 'account' => optional($t->account)->name, 'account_id' => $t->account_id, 'direction' => $t->direction, 'kind' => $t->kind, 'amount' => (string) $t->amount, 'currency' => $t->currency,
            'status' => $t->status, 'status_label' => __(AcctTransaction::STATUS[$t->status] ?? $t->status), 'description' => $t->description, 'counterparty' => $t->counterparty_name, 'method' => $t->payment_method ? __(self::METHODS[$t->payment_method] ?? $t->payment_method) : null,
            'creator' => $creators[$t->created_by] ?? null, 'reconciled' => (bool) $t->reconciled_at, 'transfer_id' => $t->transfer_id, 'department' => $t->department, 'approvals' => $t->approvals_count . '/' . $t->required_approvals];
    }

    private function detail(AcctTransaction $t, $users): array
    {
        $acct = AcctAccount::query()->find($t->account_id);
        $cat = $t->category_id ? AcctCategory::query()->find($t->category_id) : null;
        $r = $this->row($t->setRelation('account', $acct), $users);

        return $r + $t->only(['txn_time', 'value_date', 'reason', 'payment_method', 'method_details', 'external_ref', 'receipt_no', 'invoice_no', 'voucher_no', 'counterparty_type', 'department', 'cost_centre', 'notes', 'reject_reason', 'cancel_reason', 'source_module', 'source_type', 'source_id', 'fund_allocation_id', 'exchange_rate', 'reporting_amount', 'reporting_currency', 'required_approvals', 'approvals_count', 'transfer_id'])
            + ['value_date' => optional($t->value_date)->toDateString(), 'category' => optional($cat)->name, 'account_kind' => $acct?->kind, 'created_by_name' => $users[$t->created_by] ?? null, 'submitted_by_name' => $users[$t->submitted_by] ?? null, 'posted_by_name' => $users[$t->posted_by] ?? null,
                'posted_at' => optional($t->posted_at)->toDateTimeString(), 'created_at' => $t->created_at->toDateTimeString(), 'project' => $t->warehouse_id ? optional(Warehouse::withoutGlobalScopes()->find($t->warehouse_id))->name : null,
                'allocation' => $t->fund_allocation_id ? optional(\App\Models\FundAllocation::withoutGlobalScopes()->find($t->fund_allocation_id))->reference : null, 'method_label' => $t->payment_method ? __(self::METHODS[$t->payment_method] ?? $t->payment_method) : null];
    }

    private function formProps(?AcctTransaction $t, Request $request): array
    {
        $me = $this->me();
        $kind = $t?->kind ?: (string) $request->query('kind', 'payment');
        $dir = $t?->direction ?: ($kind === 'receipt' ? 'in' : ($request->query('direction') === 'in' ? 'in' : 'out'));

        return $this->shared() + [
            't' => $t ? $this->detail($t, collect()) + ['account_id' => $t->account_id, 'category_id' => $t->category_id, 'txn_date' => $t->txn_date->toDateString(), 'txn_time' => $t->txn_time, 'method_details' => $t->method_details ?? [], 'warehouse_id' => $t->warehouse_id, 'amount' => rtrim(rtrim((string) $t->amount, '0'), '.')] : null,
            'direction' => $dir, 'kind' => $kind, 'accounts' => $this->accountOptions(), 'categories' => AcctCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'direction'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name, 'direction' => $c->direction]),
            'methods' => $this->methods(), 'projects' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'status'])->map(fn ($w) => ['value' => $w->id, 'label' => $w->name, 'status' => $w->status]),
            'doc_types' => collect(self::DOC_TYPES)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(), 'preset' => ['account_id' => (int) $request->query('account_id') ?: null, 'fund_allocation_id' => (int) $request->query('fund_allocation_id') ?: null],
            'idem' => (string) \Illuminate\Support\Str::uuid(),
            'can' => ['inflow' => $me->can('create-acct-inflows'), 'outflow' => $me->can('create-acct-outflows'), 'adjust' => $me->can('create-acct-adjustments'), 'submit' => true],
            'attachments' => $t && $me->can('view-acct-attachments') ? AcctAttachment::query()->where('subject_type', 'transaction')->where('subject_id', $t->id)->get(['id', 'title', 'doc_type', 'size', 'mime']) : [],
        ];
    }

    private function validated(Request $request, bool $update = false): array
    {
        $d = $request->validate([
            'account_id' => 'required|integer', 'direction' => $update ? 'nullable|in:in,out' : 'required|in:in,out', 'kind' => 'nullable|in:receipt,payment,adjustment', 'amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'], 'txn_date' => 'required|date', 'txn_time' => 'nullable|date_format:H:i',
            'value_date' => 'nullable|date', 'category_id' => 'nullable|integer', 'description' => 'required|string|max:255', 'reason' => 'nullable|string|max:1000', 'payment_method' => 'nullable|in:' . implode(',', array_keys(self::METHODS)),
            'method_details' => 'nullable|array', 'method_details.*' => 'nullable|string|max:120', 'external_ref' => 'nullable|string|max:120', 'receipt_no' => 'nullable|string|max:80', 'invoice_no' => 'nullable|string|max:80', 'voucher_no' => 'nullable|string|max:80',
            'counterparty_type' => 'nullable|in:contact,staff,other', 'counterparty_name' => 'nullable|string|max:190', 'warehouse_id' => 'nullable|integer|exists:warehouses,id', 'department' => 'nullable|string|max:190', 'cost_centre' => 'nullable|string|max:120',
            'fund_allocation_id' => 'nullable|integer|exists:fund_allocations,id', 'notes' => 'nullable|string|max:2000', 'idempotency_key' => 'nullable|string|max:80',
            'files' => 'nullable|array|max:10', 'files.*' => 'file|max:8192|mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt', 'doc_type' => 'nullable|string|max:30',
        ]);
        $this->accountOrFail((int) $d['account_id']);
        foreach (['txn_time', 'value_date', 'category_id', 'payment_method', 'warehouse_id', 'fund_allocation_id'] as $k) {
            if (($d[$k] ?? '') === '') {
                $d[$k] = null;
            }
        }
        $d['method_details'] = array_filter((array) ($d['method_details'] ?? []), fn ($v) => $v !== null && $v !== '');

        return $d;
    }
}
