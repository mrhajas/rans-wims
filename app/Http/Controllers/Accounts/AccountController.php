<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctAttachment;
use App\Models\Accounts\AcctAuditEvent;
use App\Models\Accounts\AcctReconciliation;
use App\Models\Accounts\AcctTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounts\AcctAudit;
use App\Services\Accounts\AcctBalance;
use App\Services\Accounts\AcctRates;
use App\Support\Accounts\AcctException;
use App\Support\Accounts\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** The account register: bank accounts, cash in hand and other accounts, with their control settings and their own detail pages. */
class AccountController extends AcctController
{
    private const GROUP_LABELS = ['all' => 'Accounts Register', 'bank' => 'Bank Accounts', 'cash' => 'Cash in Hand', 'other' => 'Other Financial Accounts'];

    public function index(Request $request, AcctBalance $bal)
    {
        $this->need('read-acct-accounts', 'view-all-acct-accounts');
        $group = in_array($request->query('group'), ['bank', 'cash', 'other'], true) ? $request->query('group') : 'all';
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $currency = (string) $request->query('currency', '');
        $base = $this->scopeAccounts(AcctAccount::query())->when($group !== 'all', fn ($w) => $w->whereIn('kind', AcctAccount::GROUPS[$group]))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%$q%")->orWhere('code', 'like', "%$q%")->orWhere('institution', 'like', "%$q%")->orWhere('location', 'like', "%$q%")))
            ->when($status !== '', fn ($w) => $w->where('status', $status))->when($currency !== '', fn ($w) => $w->where('currency', $currency));
        $rows = (clone $base)->orderByDesc('is_primary')->orderBy('kind')->orderBy('name')->paginate(18)->withQueryString();
        $custodians = User::query()->whereIn('id', $rows->getCollection()->pluck('custodian_user_id')->filter()->unique())->pluck('name', 'id');
        $rows->getCollection()->transform(fn ($a) => $this->card($a, $custodians));

        $totals = [];
        foreach ((clone $base)->whereNotIn('status', ['closed'])->get(['currency', 'balance']) as $a) {
            $totals[$a->currency] = Money::add($totals[$a->currency] ?? '0', $a->balance);
        }
        ksort($totals);

        return Inertia::render('Accounts/Accounts/Index', $this->shared() + [
            'rows' => $rows, 'group' => $group, 'title' => __(self::GROUP_LABELS[$group]), 'filters' => ['q' => $q, 'status' => $status, 'currency' => $currency],
            'totals' => $totals, 'count' => (clone $base)->count(), 'kinds' => collect(AcctAccount::KINDS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(),
            'fundSettingsUrl' => route('fund-allocations.settings'), 'can' => ['create' => false, 'export' => $this->me()->can('read-acct-reports') && $this->me()->can('export-acct-transactions'), 'link' => $this->me()->can('create-acct-accounts'), 'inflow' => $this->me()->can('create-acct-inflows'), 'outflow' => $this->me()->can('create-acct-outflows')],
        ]);
    }

    /** Accounts are not made here: they are the accounts of Funds Allocation (Settings > Accounts), brought across automatically. */
    public function create(Request $request)
    {
        return redirect()->route('fund-allocations.settings')->with('message', __('Accounts are added under Funds Allocation settings, in the Accounts tab. They appear here by themselves, and you set their opening balance and limits here.'));
    }

    public function store(Request $request)
    {
        return $this->create($request);
    }

    public function show(Request $request, int $id, AcctBalance $bal)
    {
        $this->need('read-acct-accounts', 'view-all-acct-accounts');
        $a = $this->accountOrFail($id);
        $asOf = $request->query('as_of') && strtotime((string) $request->query('as_of')) ? (string) $request->query('as_of') : null;
        $in = AcctTransaction::query()->where('account_id', $a->id)->whereIn('status', AcctTransaction::LEDGER)->where('direction', 'in');
        $out = AcctTransaction::query()->where('account_id', $a->id)->whereIn('status', AcctTransaction::LEDGER)->where('direction', 'out');
        $tIn = (clone $in)->where('kind', 'transfer')->sum('amount');
        $tOut = (clone $out)->where('kind', 'transfer')->sum('amount');
        $inAll = (clone $in)->sum('amount');
        $outAll = (clone $out)->sum('amount');
        $last = AcctReconciliation::query()->where('account_id', $a->id)->where('status', 'approved')->latest('period_to')->first(['reference', 'period_to', 'difference', 'kind']);
        $open = AcctReconciliation::query()->where('account_id', $a->id)->whereIn('status', ['draft', 'submitted', 'rejected'])->latest('id')->first(['id', 'reference', 'status']);
        $ledgerBalance = $bal->asOf($a, '2999-12-31');
        $recent = AcctTransaction::query()->where('account_id', $a->id)->orderByDesc('id')->limit(12)->get()->map(fn ($t) => ['id' => $t->id, 'reference' => $t->reference, 'date' => $t->txn_date->toDateString(), 'direction' => $t->direction, 'kind' => $t->kind, 'amount' => (string) $t->amount, 'status' => $t->status, 'description' => $t->description]);

        $history = $this->me()->can('view-acct-audit') ? AcctAuditEvent::query()->where('entity_type', 'account')->where('entity_id', $a->id)->orderByDesc('id')->limit(15)->get(['event', 'user_id', 'reason', 'created_at']) : collect();
        $historyUsers = User::query()->whereIn('id', $history->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return Inertia::render('Accounts/Accounts/Show', $this->shared() + [
            'account' => $this->detail($a), 'as_of' => $asOf, 'as_of_balance' => $asOf ? $bal->asOf($a, $asOf) : null,
            'figures' => ['opening' => (string) $a->opening_balance, 'in' => (string) $inAll, 'out' => (string) $outAll, 'net' => Money::sub((string) $inAll, (string) $outAll), 'transfers_in' => (string) $tIn, 'transfers_out' => (string) $tOut, 'ledger_balance' => $ledgerBalance, 'in_sync' => Money::cmp($ledgerBalance, $a->balance) === 0],
            'pending' => $bal->pending($a->id), 'last_recon' => $last, 'open_recon' => $open, 'recent' => $recent,
            'attachments' => $this->me()->can('view-acct-attachments') ? AcctAttachment::query()->where('subject_type', 'account')->where('subject_id', $a->id)->get(['id', 'title', 'doc_type', 'size', 'created_at']) : [],
            'history' => $history->map(fn ($e) => ['event' => $e->event, 'user' => $historyUsers[$e->user_id] ?? null, 'reason' => $e->reason, 'at' => $e->created_at->toDateTimeString()]),
            'can' => ['update' => $this->canManage($a), 'status' => $this->me()->can('deactivate-acct-accounts'), 'close' => $this->me()->can('close-acct-accounts'), 'inflow' => $this->me()->can('create-acct-inflows'), 'outflow' => $this->me()->can('create-acct-outflows'), 'transfer' => $this->me()->can('create-acct-transfers'), 'reconcile' => $this->me()->can('perform-acct-reconciliations'), 'reports' => $this->me()->can('read-acct-reports'), 'repair' => $this->me()->hasRole('Super Admin')],
        ]);
    }

    public function edit(int $id)
    {
        $a = $this->accountOrFail($id);
        abort_unless($this->canManage($a), 403);

        return Inertia::render('Accounts/Accounts/Form', $this->formProps($a, $a->kind));
    }

    public function update(Request $request, int $id)
    {
        $a = $this->accountOrFail($id);
        abort_unless($this->canManage($a), 403);
        $d = $this->validated($request, $a);

        return $this->run(function () use ($a, $d) {
            $old = $this->audit($a);
            $hasLedger = AcctTransaction::query()->where('account_id', $a->id)->exists();
            if ($hasLedger && isset($d['opening_balance']) && Money::cmp($d['opening_balance'], $a->opening_balance) !== 0) {
                throw new AcctException(__('This account already has transactions, so its opening balance cannot be edited. Record an approved adjustment instead.'));
            }
            if ($hasLedger && ! empty($d['opening_date']) && \Carbon\Carbon::parse($d['opening_date'])->toDateString() !== $a->opening_date?->toDateString()) {
                throw new AcctException(__('This account already has transactions, so its opening date cannot be changed. Balances on past dates are counted from it.'));
            }
            if ($hasLedger && isset($d['currency']) && $d['currency'] !== $a->currency) {
                throw new AcctException(__('The currency of an account with transactions cannot be changed.'));
            }
            if (! $hasLedger && isset($d['opening_balance'])) {
                $d['balance'] = $d['opening_balance'];
            }
            if (($d['account_number'] ?? null) === null) {
                unset($d['account_number'], $d['account_number_masked']);
            }
            $a->fill($d + ['updated_by' => $this->me()->id])->save();
            AcctAudit::log('account.updated', 'account', $a->id, $a->code, $old, $this->audit($a->fresh()), null, $this->me());
            \App\Services\Accounts\AcctAlerts::scanAccount($a->fresh());

            return redirect()->route('acct.accounts.show', $a->id)->with('message', __('Account saved.'));
        });
    }

    /** activate | deactivate | suspend | close (closing needs a zero balance and nothing pending). */
    public function status(Request $request, int $id)
    {
        $a = $this->accountOrFail($id);
        $data = $request->validate(['action' => 'required|in:activate,deactivate,suspend,close', 'reason' => 'nullable|string|max:500']);
        $this->need($data['action'] === 'close' ? 'close-acct-accounts' : 'deactivate-acct-accounts');

        return $this->run(function () use ($a, $data) {
            $new = ['activate' => 'active', 'deactivate' => 'inactive', 'suspend' => 'suspended', 'close' => 'closed'][$data['action']];
            if ($a->status === 'closed' && $new !== 'closed') {
                throw new AcctException(__('A closed account stays closed.'));
            }
            if ($new !== 'active' && trim((string) ($data['reason'] ?? '')) === '') {
                throw new AcctException(__('Give a reason.'));
            }
            if ($new === 'closed') {
                if (Money::cmp($a->balance, '0') !== 0) {
                    throw new AcctException(__('An account can only be closed when its balance is zero. Transfer the money out first.'));
                }
                if (AcctTransaction::query()->where('account_id', $a->id)->whereIn('status', ['draft', 'submitted', 'approved'])->exists()
                    || \App\Models\Accounts\AcctTransfer::query()->whereIn('status', ['draft', 'submitted', 'approved'])->where(fn ($w) => $w->where('from_account_id', $a->id)->orWhere('to_account_id', $a->id))->exists()) {
                    throw new AcctException(__('Finish or cancel the pending transactions first.'));
                }
            }
            $old = $a->status;
            $a->fill(['status' => $new, 'closed_at' => $new === 'closed' ? now() : null, 'closed_reason' => $new === 'closed' ? $data['reason'] : null, 'updated_by' => $this->me()->id])->save();
            AcctAudit::log('account.' . $data['action'], 'account', $a->id, $a->code, ['status' => $old], ['status' => $new], $data['reason'] ?? null, $this->me());

            return back()->with('message', __('Account is now :s.', ['s' => __($new)]));
        });
    }

    /** Re-read the Funds Allocation accounts now (normally this happens by itself). */
    public function linkFunds()
    {
        $this->need('create-acct-accounts', 'update-acct-accounts');
        \App\Services\Accounts\AcctFundSync::all(true);

        return back()->with('message', __('Accounts refreshed from Funds Allocation settings.'));
    }

    /** Recalculate a stored balance from the ledger (Super Admin): the ledger is the truth. */
    public function repair(int $id, AcctBalance $bal)
    {
        abort_unless($this->me()->hasRole('Super Admin'), 403);
        $a = $this->accountOrFail($id);
        if (! $this->passwordConfirmed()) {
            return back()->with('error', e(__('Confirm your password first, then try again.')));
        }
        $new = $bal->repair($a, $this->me());

        return back()->with('message', __('Balance checked against the ledger: :b.', ['b' => Money::format($new, AcctRates::precision($a->currency))]));
    }

    // ---------------------------------------------------------------------------------------------

    private function canManage(AcctAccount $a): bool
    {
        $u = $this->me();
        $group = AcctAccount::groupOf($a->kind);

        return $u->can('update-acct-accounts') || ($group === 'cash' && $u->can('manage-acct-cash')) || ($group === 'bank' && $u->can('manage-acct-banks'));
    }

    private function validated(Request $request, ?AcctAccount $a): array
    {
        if ($a && $a->fund_account_id) {
            // name, currency and account number belong to the Funds Allocation account: whatever is sent for them is ignored
            $request->merge(['name' => $a->name, 'currency' => $a->currency, 'account_number' => null]);
        }
        $d = $request->validate([
            'name' => 'required|string|max:190', 'code' => ['nullable', 'string', 'max:40', Rule::unique('accounts.acct_accounts', 'code')->ignore($a?->id)], 'kind' => ['required', Rule::in(array_keys(AcctAccount::KINDS))],
            'currency' => ['required', 'string', 'size:3', Rule::exists('accounts.acct_currencies', 'code')->where('is_active', 1)], 'description' => 'nullable|string|max:1000', 'institution' => 'nullable|string|max:190', 'branch' => 'nullable|string|max:190',
            'account_holder' => 'nullable|string|max:190', 'account_number' => 'nullable|string|max:60', 'swift' => 'nullable|string|max:40', 'iban' => 'nullable|string|max:60', 'legal_entity' => 'nullable|string|max:190',
            'location' => 'nullable|string|max:190', 'department' => 'nullable|string|max:190', 'warehouse_id' => 'nullable|integer|exists:warehouses,id', 'custodian_user_id' => 'nullable|integer|exists:users,id',
            'is_primary' => 'boolean', 'opening_balance' => ['nullable', 'regex:/^-?\d+(\.\d{1,4})?$/'], 'opening_date' => 'nullable|date', 'min_balance' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'], 'max_balance' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'txn_limit' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'], 'allow_negative' => 'boolean', 'requires_approval' => 'boolean', 'reconcile_every_days' => 'nullable|integer|min:1|max:365',
            'alert_user_ids' => 'nullable|array', 'alert_user_ids.*' => 'integer', 'allowed_user_ids' => 'nullable|array', 'allowed_user_ids.*' => 'integer', 'notes' => 'nullable|string|max:2000',
        ]);
        foreach (['opening_balance', 'min_balance', 'max_balance', 'txn_limit'] as $k) {
            $d[$k] = ($d[$k] ?? '') === '' ? ($k === 'opening_balance' ? '0' : null) : Money::norm($d[$k]);
        }
        foreach (['opening_balance', 'min_balance', 'max_balance', 'txn_limit'] as $k) {
            if (($d[$k] ?? null) !== null && Money::cmp(ltrim($d[$k], '-'), Money::MAX_ENTRY) > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([$k => __('That amount is too large.')]);
            }
        }
        if (isset($d['opening_balance']) && ! Money::fitsPrecision($d['opening_balance'], AcctRates::precision($d['currency']))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['opening_balance' => __(':c amounts have at most :p decimal places.', ['c' => $d['currency'], 'p' => AcctRates::precision($d['currency'])])]);
        }
        if ($d['opening_balance'] !== null && Money::cmp($d['opening_balance'], '0') < 0 && empty($d['allow_negative'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['opening_balance' => __('A negative opening balance is only allowed on an account that permits negative balances.')]);
        }
        if (! empty($d['account_number'])) {
            if (! $this->me()->can('view-acct-sensitive') && $a) {
                unset($d['account_number']);
            } else {
                $d['account_number_masked'] = $this->maskNumber($d['account_number']);
                $d['account_number'] = Crypt::encryptString($d['account_number']);
            }
        } else {
            $d['account_number'] = $a ? null : null;
        }
        // user ids are saved as numbers, never as text ("12"), so every comparison and query sees the same thing
        foreach (['allowed_user_ids', 'alert_user_ids'] as $k) {
            if (array_key_exists($k, $d)) {
                $d[$k] = array_values(array_unique(array_map('intval', (array) $d[$k])));
            }
        }
        $d['is_primary'] = (bool) ($d['is_primary'] ?? false);
        $d['allow_negative'] = (bool) ($d['allow_negative'] ?? false);
        $d['requires_approval'] = (bool) ($d['requires_approval'] ?? false);
        $d['opening_date'] = $d['opening_date'] ?? ($a ? $a->opening_date?->toDateString() : now()->toDateString());
        if (empty($d['code'])) {
            unset($d['code']);
        }

        return $d;
    }

    private function card(AcctAccount $a, $custodians): array
    {
        return ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'kind' => $a->kind, 'kind_label' => __(AcctAccount::KINDS[$a->kind] ?? $a->kind), 'group' => AcctAccount::groupOf($a->kind), 'currency' => $a->currency, 'balance' => (string) $a->balance,
            'status' => $a->status, 'institution' => $a->institution, 'masked' => $a->account_number_masked, 'custodian' => $custodians[$a->custodian_user_id] ?? null, 'location' => $a->location, 'is_primary' => $a->is_primary,
            'min_balance' => $a->min_balance !== null ? (string) $a->min_balance : null, 'max_balance' => $a->max_balance !== null ? (string) $a->max_balance : null, 'low' => $a->min_balance !== null && Money::cmp($a->balance, $a->min_balance) < 0, 'linked' => (bool) $a->fund_account_id];
    }

    private function detail(AcctAccount $a): array
    {
        $sens = $this->me()->can('view-acct-sensitive');
        $plain = null;
        if ($sens && $a->account_number) {
            try {
                $plain = Crypt::decryptString($a->account_number);
            } catch (\Throwable) {
                $plain = null;
            }
        }

        return $this->card($a, User::query()->whereIn('id', array_filter([$a->custodian_user_id]))->pluck('name', 'id')) + [
            'description' => $a->description, 'branch' => $a->branch, 'account_holder' => $a->account_holder, 'account_number' => $plain, 'swift' => $sens ? $a->swift : null, 'iban' => $sens ? $a->iban : null, 'legal_entity' => $a->legal_entity,
            'department' => $a->department, 'warehouse' => $a->warehouse_id ? optional(Warehouse::withoutGlobalScopes()->find($a->warehouse_id))->name : null, 'opening_balance' => (string) $a->opening_balance, 'opening_date' => optional($a->opening_date)->toDateString(),
            'txn_limit' => $a->txn_limit !== null ? (string) $a->txn_limit : null, 'allow_negative' => $a->allow_negative, 'requires_approval' => $a->requires_approval, 'reconcile_every_days' => $a->reconcile_every_days, 'notes' => $a->notes,
            'closed_at' => optional($a->closed_at)->toDateString(), 'closed_reason' => $a->closed_reason, 'balance_updated_at' => optional($a->balance_updated_at)->toDateTimeString(), 'sensitive_hidden' => ! $sens, 'fund_account_id' => $a->fund_account_id,
        ];
    }

    private function formProps(?AcctAccount $a, string $kind): array
    {
        $d = $a ? $this->detail($a) + ['custodian_user_id' => $a->custodian_user_id, 'warehouse_id' => $a->warehouse_id, 'alert_user_ids' => $a->alert_user_ids ?? [], 'allowed_user_ids' => $a->allowed_user_ids ?? [], 'account_number' => $this->me()->can('view-acct-sensitive') ? $this->detail($a)['account_number'] : ''] : null;

        return $this->shared() + [
            'account' => $d, 'kind' => $kind, 'kinds' => collect(AcctAccount::KINDS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(),
            'users' => User::query()->where('is_active', 1)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->values(),
            'projects' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'status'])->map(fn ($w) => ['value' => $w->id, 'label' => $w->name, 'status' => $w->status])->values(),
            'locked' => $a ? AcctTransaction::query()->where('account_id', $a->id)->exists() : false, 'can' => ['sensitive' => $this->me()->can('view-acct-sensitive')],
        ];
    }

    private function audit(AcctAccount $a): array
    {
        return array_intersect_key($a->getAttributes(), array_flip(['code', 'name', 'kind', 'currency', 'status', 'opening_balance', 'opening_date', 'min_balance', 'max_balance', 'txn_limit', 'allow_negative', 'requires_approval', 'custodian_user_id', 'warehouse_id', 'department', 'is_primary']));
    }
}
