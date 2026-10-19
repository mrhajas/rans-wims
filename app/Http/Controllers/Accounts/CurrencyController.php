<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctCurrency;
use App\Models\Accounts\AcctExchangeRate;
use App\Models\User;
use App\Services\Accounts\AcctAlerts;
use App\Services\Accounts\AcctAudit;
use App\Services\Accounts\AcctRates;
use App\Services\Accounts\AcctSettings;
use App\Support\Accounts\AcctException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Currencies (any number can be added) and the exchange rate register. A rate is used only once approved; the rate used on a transaction is kept on it. */
class CurrencyController extends AcctController
{
    public const RATE_TYPES = ['management' => 'Management rate', 'buying' => 'Bank buying rate', 'selling' => 'Bank selling rate', 'bank_transfer' => 'Bank transfer rate', 'cash' => 'Cash rate', 'other' => 'Other'];

    public function index(Request $request)
    {
        $this->need('read-acct-accounts', 'manage-acct-currencies', 'create-acct-rates', 'approve-acct-rates');
        $me = $this->me();
        $q = AcctExchangeRate::query()
            ->when(in_array($request->query('status'), ['pending', 'approved', 'rejected'], true), fn ($w) => $w->where('status', $request->query('status')))
            ->when(preg_match('/^[A-Z]{3}$/', (string) $request->query('currency')), fn ($w) => $w->where(fn ($x) => $x->where('base_currency', $request->query('currency'))->orWhere('quote_currency', $request->query('currency'))))
            ->when(array_key_exists((string) $request->query('type'), self::RATE_TYPES), fn ($w) => $w->where('rate_type', $request->query('type')));
        $rows = $q->orderByDesc('rate_at')->orderByDesc('id')->paginate(20)->withQueryString();
        $users = User::query()->whereIn('id', $rows->getCollection()->pluck('entered_by')->merge($rows->getCollection()->pluck('approved_by'))->filter()->unique())->pluck('name', 'id');
        $rows->getCollection()->transform(fn ($r) => ['id' => $r->id, 'base' => $r->base_currency, 'quote' => $r->quote_currency, 'rate' => rtrim(rtrim((string) $r->rate, '0'), '.'), 'type' => $r->rate_type, 'type_label' => __(self::RATE_TYPES[$r->rate_type] ?? $r->rate_type), 'source' => $r->source, 'source_name' => $r->source_name, 'reference' => $r->reference,
            'rate_at' => $r->rate_at->toDateTimeString(), 'from' => $r->effective_from->toDateString(), 'to' => optional($r->effective_to)->toDateString(), 'status' => $r->status, 'entered_by' => $users[$r->entered_by] ?? null, 'approved_by' => $users[$r->approved_by] ?? null, 'approved_at' => optional($r->approved_at)->toDateTimeString(), 'reject_reason' => $r->reject_reason, 'notes' => $r->notes,
            'own' => (int) $r->entered_by === (int) $me->id]);

        return Inertia::render('Accounts/Currencies', $this->shared() + [
            'currencies' => AcctCurrency::query()->orderByDesc('is_reporting')->orderBy('code')->get()->map(fn ($c) => $c->only(['id', 'code', 'name', 'symbol', 'precision', 'is_active', 'is_reporting', 'notes']) + ['accounts' => AcctAccount::query()->where('currency', $c->code)->count()]),
            'rates' => $rows, 'filters' => $request->only('status', 'currency', 'type'), 'rateTypes' => collect(self::RATE_TYPES)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(),
            'current' => $this->currentRates(),
            'can' => ['manage' => $me->can('manage-acct-currencies'), 'create' => $me->can('create-acct-rates'), 'approve' => $me->can('approve-acct-rates')],
        ]);
    }

    public function storeCurrency(Request $request)
    {
        $this->need('manage-acct-currencies');
        $d = $request->validate(['code' => ['required', 'regex:/^[A-Za-z]{3}$/'], 'name' => 'required|string|max:80', 'symbol' => 'required|string|max:10', 'precision' => 'required|integer|min:0|max:4', 'notes' => 'nullable|string|max:500']);
        $d['code'] = strtoupper($d['code']);
        if (AcctCurrency::query()->where('code', $d['code'])->exists()) {
            return back()->with('error', e(__('That currency already exists.')));
        }
        $c = AcctCurrency::query()->create($d + ['is_active' => true]);
        AcctRates::forget();
        AcctAudit::log('currency.created', 'currency', $c->id, $c->code, null, $d, null, $this->me());

        return back()->with('message', __('Currency added.'));
    }

    public function updateCurrency(Request $request, int $id)
    {
        $this->need('manage-acct-currencies');
        $c = AcctCurrency::query()->findOrFail($id);
        $d = $request->validate(['name' => 'required|string|max:80', 'symbol' => 'required|string|max:10', 'precision' => 'required|integer|min:0|max:4', 'is_active' => 'required|boolean', 'notes' => 'nullable|string|max:500']);
        if ((int) $d['precision'] !== (int) $c->precision && \App\Models\Accounts\AcctTransaction::query()->where('currency', $c->code)->exists()) {
            return back()->with('error', e(__('Decimal places cannot be changed once :c has transactions.', ['c' => $c->code])));
        }
        if (! $d['is_active'] && ($c->is_reporting || AcctAccount::query()->where('currency', $c->code)->where('status', '<>', 'closed')->exists())) {
            return back()->with('error', e(__('A currency used by an open account or as the reporting currency cannot be deactivated.')));
        }
        $old = $c->only(['name', 'symbol', 'precision', 'is_active']);
        $c->fill($d)->save();
        AcctRates::forget();
        AcctAudit::log('currency.updated', 'currency', $c->id, $c->code, $old, $c->only(['name', 'symbol', 'precision', 'is_active']), null, $this->me());

        return back()->with('message', __('Saved.'));
    }

    public function setReporting(int $id)
    {
        $this->need('manage-acct-settings');
        $c = AcctCurrency::query()->where('is_active', true)->findOrFail($id);
        AcctCurrency::query()->update(['is_reporting' => false]);
        $c->forceFill(['is_reporting' => true])->save();
        AcctSettings::save(['reporting_currency' => $c->code]);
        AcctAudit::log('currency.reporting_set', 'currency', $c->id, $c->code, null, null, null, $this->me());

        return back()->with('message', __(':c is now the reporting currency.', ['c' => $c->code]));
    }

    public function storeRate(Request $request)
    {
        $this->need('create-acct-rates');
        $d = $request->validate(['base_currency' => ['required', 'regex:/^[A-Z]{3}$/', 'exists:accounts.acct_currencies,code'], 'quote_currency' => ['required', 'regex:/^[A-Z]{3}$/', 'different:base_currency', 'exists:accounts.acct_currencies,code'],
            'rate' => ['required', 'regex:/^\d{1,12}(\.\d{1,8})?$/'], 'rate_type' => ['required', Rule::in(array_keys(self::RATE_TYPES))], 'source' => 'nullable|in:manual,bank,provider,other', 'source_name' => 'nullable|string|max:190', 'reference' => 'nullable|string|max:190',
            'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from', 'notes' => 'nullable|string|max:500']);
        if (bccomp($d['rate'], '0', 8) <= 0) {
            return back()->with('error', e(__('The rate must be greater than zero.')));
        }
        // A rate far from the last approved one is a typing mistake until proven otherwise: warn, but do not forbid (currencies do move).
        $last = AcctRates::find($d['base_currency'], $d['quote_currency'], $d['effective_from']);
        $warn = '';
        if ($last && ! $last['derived'] && bccomp($last['rate'], '0', 8) > 0) {
            $chg = abs((float) bcdiv(bcsub($d['rate'], $last['rate'], 8), $last['rate'], 6)) * 100;
            if ($chg > 20) {
                $warn = ' ' . __('Note: this is :p% away from the last approved rate. Check it.', ['p' => round($chg, 1)]);
            }
        }
        $r = AcctExchangeRate::query()->create($d + ['source' => $d['source'] ?? 'manual', 'rate_at' => now(), 'status' => 'pending', 'entered_by' => $this->me()->id]);
        AcctAudit::log('rate.created', 'rate', $r->id, $r->base_currency . '/' . $r->quote_currency, null, $r->only(['rate', 'rate_type', 'effective_from']), null, $this->me());
        AcctAlerts::raise('currency', 'rate_pending', 'info', __('Exchange rate :p is waiting for approval', ['p' => $r->base_currency . '/' . $r->quote_currency]), null, ['subject_type' => 'rate', 'subject_id' => $r->id, 'dedupe' => 'rate-pending-' . $r->id, 'notify_event' => 'rate_alert']);

        return back()->with('message', __('Rate saved. It is used only after it is approved.') . $warn);
    }

    public function approveRate(int $id)
    {
        $this->need('approve-acct-rates');
        $r = AcctExchangeRate::query()->findOrFail($id);
        if ($r->status !== 'pending') {
            return back()->with('error', e(__('This rate is not waiting for approval.')));
        }
        if ((int) $r->entered_by === (int) $this->me()->id && AcctSettings::get()['maker_checker'] && ! (AcctSettings::get()['super_admin_self_approval'] && $this->me()->hasRole('Super Admin'))) {
            return back()->with('error', e(__('Someone other than the person who entered the rate must approve it.')));
        }
        $r->fill(['status' => 'approved', 'approved_by' => $this->me()->id, 'approved_at' => now()])->save();
        AcctAudit::log('rate.approved', 'rate', $r->id, $r->base_currency . '/' . $r->quote_currency, null, ['rate' => (string) $r->rate], null, $this->me());
        AcctAlerts::clear('rate-pending-' . $r->id, 'The rate was approved.');
        AcctAlerts::clear('rate-missing-' . $r->base_currency . $r->quote_currency, 'A rate now exists.');

        return back()->with('message', __('Rate approved.'));
    }

    public function rejectRate(Request $request, int $id)
    {
        $this->need('approve-acct-rates');
        $r = AcctExchangeRate::query()->findOrFail($id);
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];
        if ($r->status !== 'pending') {
            return back()->with('error', e(__('This rate is not waiting for approval.')));
        }
        $r->fill(['status' => 'rejected', 'reject_reason' => $reason, 'approved_by' => $this->me()->id, 'approved_at' => now()])->save();
        AcctAudit::log('rate.rejected', 'rate', $r->id, $r->base_currency . '/' . $r->quote_currency, null, null, $reason, $this->me());
        AcctAlerts::clear('rate-pending-' . $r->id, 'The rate was rejected.');

        return back()->with('message', __('Rate rejected.'));
    }

    /** For each pair of active currencies, the approved rate in force today (or none: shown as missing, never guessed). */
    private function currentRates(): array
    {
        $codes = AcctCurrency::query()->where('is_active', true)->pluck('code')->all();
        $out = [];
        foreach ($codes as $a) {
            foreach ($codes as $b) {
                if ($a !== $b) {
                    $r = AcctRates::find($a, $b);
                    $out[] = ['from' => $a, 'to' => $b, 'rate' => $r ? rtrim(rtrim($r['rate'], '0'), '.') : null, 'derived' => (bool) ($r['derived'] ?? false), 'type' => $r['type'] ?? null, 'at' => isset($r['at']) ? (string) $r['at'] : null];
                }
            }
        }

        return $out;
    }
}
