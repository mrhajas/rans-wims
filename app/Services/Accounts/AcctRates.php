<?php

namespace App\Services\Accounts;

use App\Models\Accounts\AcctCurrency;
use App\Models\Accounts\AcctExchangeRate;

/** Which exchange rate applies. Only APPROVED rates are ever used, and none is invented: a missing rate is reported as missing. */
class AcctRates
{
    private static array $precision = [];

    public static function precision(string $code): int
    {
        return self::$precision[$code] ??= (int) (AcctCurrency::query()->where('code', $code)->value('precision') ?? 2);
    }

    public static function forget(): void
    {
        self::$precision = [];
    }

    /**
     * The approved rate from $from to $to that is in force on $date. Returns ['rate' => string, 'id' => ?int, 'derived' => bool, 'type' => ..., 'at' => ...] or null.
     * A direct rate wins; otherwise the inverse of an approved opposite rate (flagged derived) when settings allow it.
     */
    public static function find(string $from, string $to, ?string $date = null, ?string $type = null): ?array
    {
        if ($from === $to) {
            return ['rate' => '1.00000000', 'id' => null, 'derived' => false, 'type' => 'same', 'at' => null];
        }
        $date = $date ?: now()->toDateString();
        $q = fn (string $b, string $qte) => AcctExchangeRate::query()->where('status', 'approved')->where('base_currency', $b)->where('quote_currency', $qte)
            ->whereDate('effective_from', '<=', $date)->where(fn ($w) => $w->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->when($type, fn ($w) => $w->where('rate_type', $type))->orderByDesc('effective_from')->orderByDesc('rate_at')->orderByDesc('id');
        $direct = $q($from, $to)->first();
        if ($direct) {
            return ['rate' => bcadd((string) $direct->rate, '0', 8), 'id' => $direct->id, 'derived' => false, 'type' => $direct->rate_type, 'at' => $direct->rate_at];
        }
        if (AcctSettings::get()['allow_inverse_rates']) {
            $inv = $q($to, $from)->first();
            if ($inv && bccomp((string) $inv->rate, '0', 8) > 0) {
                return ['rate' => bcdiv('1', (string) $inv->rate, 8), 'id' => $inv->id, 'derived' => true, 'type' => $inv->rate_type, 'at' => $inv->rate_at];
            }
        }

        return null;
    }

    /** $amount of $from expressed in the reporting currency on $date, or null when no approved rate exists. */
    public static function toReporting(string $amount, string $from, ?string $date = null): ?array
    {
        $rep = AcctSettings::get()['reporting_currency'];
        $r = self::find($from, $rep, $date);

        return $r ? ['amount' => \App\Support\Accounts\Money::convert($amount, $r['rate'], self::precision($rep)), 'currency' => $rep, 'rate' => $r] : null;
    }
}
