<?php

namespace App\Services\Subcontracts;

use App\Models\Setting;

/** Subcontract settings: one row of the WIMS settings table (key `subcontract_settings`), starting from these defaults. */
class SubcontractSettings
{
    public const KEY = 'subcontract_settings';

    private static ?array $memo = null;

    public static function defaults(): array
    {
        return [
            'currencies' => ['TZS', 'USD'],
            // canonical unit => spellings found in the existing workbook (compared ignoring case and dots)
            'units' => [
                'Item' => ['item', 'items', 'itm'], 'Sum' => ['sum', 'lump sum', 'ls'], 'Nr' => ['nr', 'nrs', 'no', 'nos', 'pcs', 'pcss', 'pc', 'piece', 'pieces', 'pts', 'pt'],
                'Sqm' => ['sqm', 'm2', 'sq.m', 'sq m'], 'm' => ['m', 'lm', 'mtr', 'meter', 'metre'], 'm3' => ['m3', 'cum'], 'Day' => ['day', 'days'],
                'Person-day' => ['persxday', 'pers/day', 'pers / day', 'pers x day', 'person day', 'man day', 'pers', 'persons'], 'Week' => ['week', 'weekly'], 'Month' => ['month', 'monthly'], 'Bag' => ['bag', 'bags'], 'Set' => ['set', 'sets'], 'Box' => ['box', 'boxes'], 'RM' => ['rm', 'r.m', 'running meter'], 'Trip' => ['trip', 'trips'], 'Ton' => ['ton', 'tons', 'tonne', 'tonnes'],
            ],
            'categories' => ['Masonry', 'Plumbing', 'Electrical', 'Aluminium and glazing', 'Joinery', 'Metal works', 'Painting', 'Roofing', 'Ceiling', 'Terrazzo and tiling', 'ICT', 'Air conditioning', 'Piling', 'Lifts', 'Scaffolding', 'Maintenance', 'General'],
            'fields' => [],                              // the field settings (SubcontractFields): optional built-in fields on/off and the custom fields
            'override_allowed' => true,                  // a person with override-subcontract-limits may approve above the remaining entitlement, with a reason
            'tolerance' => '0.00',                       // an approval may exceed the headroom by this much without an override
            'default_retention_percent' => '0',
            'review_alert_hours' => 72,                  // a submitted item waiting this long is flagged
            'completion_alert_days' => 14,
            'alert_cooldown_hours' => 24,               // the same open alert tells people again only after this many hours
            'unpaid_alert_days' => 7,                   // approved money not paid after this many days is flagged
            'near_limit_percent' => 10,                 // an account with this percent (or less) of its contract left to pay is flagged
            'dated_list_tolerance' => '1.00',            // reconciliation: a difference up to this amount is rounding
            'claims_require_verification' => true,       // a Completed agreement can be claimed only after its completion is verified (verify-subcontract-completion)
            'claims_require_evidence' => false,          // when true, verifying a completion (and claiming) needs at least one document attached to the agreement
            'claims_allow_partial_request' => true,      // a claim line may ask for less than the whole remaining balance of the agreement
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

    /** "SQM", "sqm" and "m2" are the same unit. A spelling that is not known stays as typed (nothing is lost). */
    public static function normalizeUnit(?string $unit): ?string
    {
        $u = trim((string) $unit);
        if ($u === '') {
            return null;
        }
        $key = preg_replace('/\s*\/\s*/', '/', mb_strtolower(rtrim($u, '. ')));
        foreach (self::get()['units'] as $canonical => $aliases) {
            if ($key === mb_strtolower($canonical) || in_array($key, array_map('mb_strtolower', $aliases), true)) {
                return $canonical;
            }
        }

        return $u;
    }
}
