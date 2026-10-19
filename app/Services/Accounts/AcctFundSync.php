<?php

namespace App\Services\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctCurrency;
use App\Models\FundAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts Tracking does not have accounts of its own: the accounts are the ones set up under Funds Allocation > Settings > Accounts.
 * Every fund account has exactly one tracked account (acct_accounts.fund_account_id). Its identity (name, currency, number, active or not) is
 * copied from the fund account every time that one changes; what is added here is the money side (opening balance, limits, custodian ...).
 */
class AcctFundSync
{
    public const KINDS = ['bank' => 'bank_account', 'cash' => 'cash_in_hand', 'mobile' => 'mobile_money', 'other' => 'other'];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        try {
            return self::$ready ??= Schema::connection('accounts')->hasTable('acct_accounts');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Bring one fund account across (create it, or refresh what it owns). Never throws: a fund account must save even if this cannot. */
    public static function one(FundAccount $f): ?AcctAccount
    {
        if (! self::ready()) {
            return null;
        }
        try {
            $f = FundAccount::withTrashed()->find($f->id) ?? $f;
            $currency = strtoupper((string) ($f->currency ?: 'TZS'));
            if (! AcctCurrency::query()->where('code', $currency)->exists()) {
                AcctCurrency::query()->create(['code' => $currency, 'name' => $currency, 'symbol' => $currency, 'precision' => 2, 'is_active' => true]);
                AcctRates::forget();
            }
            $a = AcctAccount::query()->where('fund_account_id', $f->id)->first();
            $own = ['name' => $f->name, 'description' => $f->description];
            if ($f->account_number) {
                $own['account_number'] = Crypt::encryptString($f->account_number);
                $own['account_number_masked'] = self::mask($f->account_number);
            } else {
                $own['account_number'] = null;
                $own['account_number_masked'] = null;
            }
            $active = ! $f->trashed() && $f->is_active;
            if (! $a) {
                $a = AcctAccount::query()->create($own + [
                    'code' => 'FA-' . str_pad((string) $f->id, 4, '0', STR_PAD_LEFT), 'kind' => self::KINDS[$f->type] ?? 'other', 'currency' => $currency, 'fund_account_id' => $f->id,
                    'status' => $active ? 'active' : 'inactive', 'opening_balance' => '0', 'balance' => '0', 'opening_date' => optional($f->created_at)->toDateString() ?: now()->toDateString(), 'balance_updated_at' => now(),
                ]);
                AcctAudit::log('account.linked', 'account', $a->id, $a->code, null, ['fund_account_id' => $f->id], __('Taken from Funds Allocation settings.'));

                return $a;
            }
            // Currency is the one thing that cannot follow once money has moved: the fund account keeps its currency, the ledger keeps its own.
            if ($a->currency !== $currency && ! \App\Models\Accounts\AcctTransaction::query()->where('account_id', $a->id)->exists()) {
                $own['currency'] = $currency;
            }
            $own['status'] = match (true) {
                $a->status === 'closed' => 'closed',
                $active => $a->status === 'inactive' ? 'active' : $a->status,   // a fund account switched back on wakes the tracked account; a suspension made here stays
                default => 'inactive',
            };
            $a->fill($own);
            if ($a->isDirty()) {
                $old = $a->getOriginal();
                $a->save();
                AcctAudit::log('account.synced', 'account', $a->id, $a->code, array_intersect_key($old, $a->getChanges()), array_diff_key($a->getChanges(), ['account_number' => 1, 'updated_at' => 1]), __('Changed in Funds Allocation settings.'));
            }

            return $a;
        } catch (\Throwable $e) {
            Log::warning('Accounts Tracking could not follow a fund account', ['fund_account_id' => $f->id ?? null, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Every fund account. Cheap when nothing changed: it compares a fingerprint of the fund accounts with the last one seen. */
    public static function all(bool $force = false): void
    {
        if (! self::ready()) {
            return;
        }
        $sig = FundAccount::withTrashed()->selectRaw('COUNT(*) c, MAX(updated_at) u, MAX(deleted_at) d, SUM(is_active) s')->first();
        $fingerprint = md5(json_encode($sig->toArray()) . AcctAccount::query()->whereNotNull('fund_account_id')->count());
        if (! $force && Cache::get('acct.fund.sync') === $fingerprint) {
            return;
        }
        FundAccount::withTrashed()->orderBy('id')->each(fn ($f) => self::one($f));
        Cache::put('acct.fund.sync', md5(json_encode(FundAccount::withTrashed()->selectRaw('COUNT(*) c, MAX(updated_at) u, MAX(deleted_at) d, SUM(is_active) s')->first()->toArray()) . AcctAccount::query()->whereNotNull('fund_account_id')->count()), 3600);
    }

    private static function mask(string $n): string
    {
        $d = preg_replace('/\s+/', '', $n);

        return strlen($d) <= 4 ? str_repeat('•', strlen($d)) : str_repeat('•', max(4, strlen($d) - 4)) . substr($d, -4);
    }
}
