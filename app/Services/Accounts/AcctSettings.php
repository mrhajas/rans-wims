<?php

namespace App\Services\Accounts;

use App\Models\Setting;

/** Accounts Tracking settings: one row of the WIMS settings table (key `acct_settings`), starting from these defaults. */
class AcctSettings
{
    public const KEY = 'acct_settings';

    private static ?array $memo = null;

    public static function defaults(): array
    {
        return [
            'reporting_currency' => 'TZS',
            'maker_checker' => true,                  // a person never approves what they created or submitted
            'super_admin_self_approval' => false,      // an audited exception, off unless switched on here
            'date_rule' => 'txn_date',                // which date puts a transaction into a historical balance: txn_date | value_date
            'closed_through' => null,                 // transactions dated on or before this day are locked (accounting period close)
            'large_txn' => ['TZS' => 5000000, 'USD' => 2000],           // alert above these amounts, per currency
            'attachment_required_over' => ['TZS' => 500000, 'USD' => 200], // a payment above this needs a supporting document
            'discrepancy_dual_over' => ['TZS' => 50000, 'USD' => 20],   // a cash / bank difference above this needs two approvals
            'pending_alert_hours' => 48,
            'transfer_pending_hours' => 24,
            'unusual_factor' => 5,                    // a payment this many times the account's recent average is flagged
            'alert_cooldown_hours' => 24,
            'rate_tolerance_pct' => '1.0',            // a cross-currency credit may differ from amount x rate by this much before it must be explained
            'allow_inverse_rates' => true,
            'recon_overdue_days' => 30,
            'denominations' => ['TZS' => [500, 1000, 2000, 5000, 10000], 'USD' => [1, 5, 10, 20, 50, 100]],
        ];
    }

    public static function get(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        $raw = Setting::query()->where('tec_key', self::KEY)->where('account_id', (int) (auth()->user()->account_id ?? 1))->value('tec_value');
        $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        return self::$memo = array_replace(self::defaults(), array_intersect_key($saved, self::defaults()));
    }

    public static function save(array $data): array
    {
        $clean = array_replace(self::get(), array_intersect_key($data, self::defaults()));
        Setting::query()->updateOrCreate(['tec_key' => self::KEY, 'account_id' => (int) (auth()->user()->account_id ?? 1)], ['tec_value' => json_encode($clean)]);
        self::$memo = null;

        return $clean;
    }

    public static function forget(): void
    {
        self::$memo = null;
    }

    public static function amountFor(string $key, string $currency): ?string
    {
        $v = self::get()[$key][$currency] ?? null;

        return $v === null || $v === '' ? null : (string) $v;
    }
}
