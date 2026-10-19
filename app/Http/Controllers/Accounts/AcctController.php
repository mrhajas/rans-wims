<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctCurrency;
use App\Models\User;
use App\Services\Accounts\AcctSettings;
use App\Support\Accounts\AcctException;

/** Shared by every Accounts Tracking controller: server-side permission checks (never only hidden buttons), account scope and one way to report a refused financial rule. */
abstract class AcctController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
        // The accounts are the Funds Allocation accounts (Settings > Accounts): make sure the tracked copies are up to date before anything reads them.
        \App\Services\Accounts\AcctFundSync::all();
    }

    protected function me(): User
    {
        return auth()->user();
    }

    /** Refuse unless the person holds at least one of these abilities. A refusal on a financial route is also kept in the audit trail. */
    protected function need(string ...$abilities): void
    {
        foreach ($abilities as $a) {
            if ($this->me()->can($a)) {
                return;
            }
        }
        // one audit row per person and route per minute: a refused page that is reloaded (or hammered) must not flood the trail or queue on the audit lock
        try {
            $path = request()->path();
            if (\Illuminate\Support\Facades\Cache::add('acct.denied.' . $this->me()->id . '.' . md5($path) . '.' . now()->format('YmdHi'), 1, 120)) {
                \App\Services\Accounts\AcctAudit::log('access.denied', 'route', null, $path, null, ['needed' => $abilities], null, $this->me());
            }
        } catch (\Throwable) {
        }
        abort(403);
    }

    /**
     * Has this person confirmed their password recently? The same session check as the `password.confirm` route middleware, for an action that must first
     * decide who may see it at all (so a stranger still gets a plain 403/404, never a request for a password).
     */
    protected function passwordConfirmed(): bool
    {
        return (time() - (int) session('auth.password_confirmed_at', 0)) < (int) config('auth.password_timeout', 900);
    }

    /** null = every account; otherwise the ids this person may see (accounts they look after or are named on). */
    protected function visibleAccountIds(): ?array
    {
        $u = $this->me();
        if ($u->can('view-all-acct-accounts')) {
            return null;
        }

        return AcctAccount::query()->visibleTo((int) $u->id)->pluck('id')->all();
    }

    /** Names of the accounts this person may see (a hidden account's name is never put in a page, list or report). */
    protected function visibleNames()
    {
        return $this->scopeAccounts(AcctAccount::query())->pluck('name', 'id');
    }

    /** A transfer belongs to a person only if they can see BOTH of its accounts: one side alone would show (and let them decide on) the other. */
    protected function scopeTransfers($q)
    {
        $ids = $this->visibleAccountIds();

        return $ids === null ? $q : $q->whereIn('from_account_id', $ids ?: [0])->whereIn('to_account_id', $ids ?: [0]);
    }

    /** Alerts about accounts the person cannot see are not theirs to read. Security and currency alerts (no account) are visible to anyone who may read alerts. */
    protected function scopedAlerts()
    {
        $ids = $this->visibleAccountIds();

        return \App\Models\Accounts\AcctAlert::query()->when($ids !== null, fn ($q) => $q->where(fn ($x) => $x->whereNull('account_id')->orWhereIn('account_id', $ids ?: [0])));
    }

    protected function scopeAccounts($q, string $col = 'id')
    {
        $ids = $this->visibleAccountIds();

        return $ids === null ? $q : $q->whereIn($col, $ids ?: [0]);
    }

    /** May this person see this account at all? Otherwise it is a plain 404, never a hint that it exists. */
    protected function accountOrFail(int $id): AcctAccount
    {
        $a = AcctAccount::query()->find($id);
        $ids = $this->visibleAccountIds();
        abort_if(! $a || ($ids !== null && ! in_array($a->id, $ids, true)), 404);

        return $a;
    }

    protected function currencies(): array
    {
        return AcctCurrency::query()->orderByDesc('is_reporting')->orderBy('code')->get(['code', 'name', 'symbol', 'precision', 'is_active', 'is_reporting'])->keyBy('code')->map(fn ($c) => ['name' => $c->name, 'symbol' => $c->symbol, 'precision' => (int) $c->precision, 'active' => (bool) $c->is_active])->all();
    }

    /** What every page needs to draw money: currency precision and symbol, the reporting currency. */
    protected function shared(): array
    {
        return ['cur' => $this->currencies(), 'reportingCurrency' => AcctSettings::get()['reporting_currency']];
    }

    /** Run a financial action; a refused rule comes back as a clear message on the same page, anything else is a real error. */
    protected function run(\Closure $f, ?string $ok = null)
    {
        try {
            $r = $f();
        } catch (AcctException $e) {
            return back()->with('error', e($e->getMessage()));
        }
        if ($r instanceof \Symfony\Component\HttpFoundation\Response) {
            return $r;
        }

        return back()->with('message', $ok ?? __('Done.'));
    }

    protected function maskNumber(?string $plain): ?string
    {
        if (! $plain) {
            return null;
        }
        $d = preg_replace('/\s+/', '', $plain);

        return strlen($d) <= 4 ? str_repeat('•', strlen($d)) : str_repeat('•', max(4, strlen($d) - 4)) . substr($d, -4);
    }

    protected function accountOptions(?array $kinds = null, bool $activeOnly = true)
    {
        return $this->scopeAccounts(AcctAccount::query()->when($activeOnly, fn ($q) => $q->where('status', 'active'))->when($kinds, fn ($q) => $q->whereIn('kind', $kinds)))
            ->orderBy('kind')->orderBy('name')->get(['id', 'code', 'name', 'currency', 'kind', 'balance', 'status', 'warehouse_id'])
            ->map(fn ($a) => ['value' => $a->id, 'label' => $a->name . ' (' . $a->currency . ')', 'code' => $a->code, 'currency' => $a->currency, 'kind' => $a->kind, 'balance' => (string) $a->balance]);
    }
}
