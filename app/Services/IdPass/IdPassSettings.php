<?php

namespace App\Services\IdPass;

use App\Models\Setting;

/** Settings of RANS ID & Pass Management (the shared back of the card, QR verification, expiry notices, gate pass types). Kept in the WIMS settings table; the supplied card back is the starting wording. */
class IdPassSettings
{
    public const TERMS = ['Company ID remains company property.', 'ID must be carried during work hours.', 'IDs are for official use only.', 'Do not share or misuse the ID.', 'Report lost or damaged ID immediately.',
        'Keep the ID in good condition.', 'Return the ID upon request by the company.', 'Misuse may lead to disciplinary action.'];

    public const DEFAULTS = [
        'idpass_intro' => 'This ID card is issued by RANS for official company identification and authorized use only',
        'idpass_terms' => '',
        'idpass_address' => 'Fumba Road - Dimani, Kombeni',
        'idpass_pobox' => 'P.O.Box 3267 - Zanzibar - Tanzania',
        'idpass_phones' => '+255 777 700615   /   +255 777 426670',
        'idpass_email' => 'info@rans.co.tz',
        'idpass_website' => 'www.rans.co.tz',
        'idpass_back_color' => '#cf7d1c',
        'idpass_qr_mode' => 'public',          // public: anybody who scans sees status and validity (and no private details); login: only signed-in people see anything
        'idpass_expiry_notice_days' => '30',
        'idpass_gate_types' => "Parking\nSupplier delivery\nContractor\nService provider\nOther",
        'idpass_no_expiry_text' => 'Valid until revoked',
        'idpass_stock_holder' => 'RANS Company Limited', // who a stock of Gate Passes belongs to until each one is given to a person
        'idpass_sync_batch' => '30',           // up to this many cards are made at once; more are made in the background
    ];

    public static function get(string $key): string
    {
        $v = get_settings($key);

        return ($v === null || $v === '') ? (self::DEFAULTS[$key] ?? '') : (string) $v;
    }

    public static function save(array $values, ?int $accountId = null): void
    {
        foreach ($values as $k => $v) {
            if (! array_key_exists($k, self::DEFAULTS)) {
                continue;
            }
            Setting::query()->updateOrCreate(['tec_key' => $k, 'account_id' => $accountId ?: getAccountId(1)], ['tec_value' => (string) $v]);
        }
    }

    /** @return string[] */
    public static function terms(): array
    {
        $raw = trim((string) get_settings('idpass_terms'));
        $lines = $raw === '' ? self::TERMS : array_values(array_filter(array_map('trim', preg_split('/\R/', $raw))));

        return array_slice($lines, 0, 10);
    }

    /** @return string[] */
    public static function gateTypes(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', self::get('idpass_gate_types')))));
    }

    public static function qrMode(): string
    {
        return self::get('idpass_qr_mode') === 'login' ? 'login' : 'public';
    }

    public static function noticeDays(): int
    {
        return max(0, min(180, (int) self::get('idpass_expiry_notice_days')));
    }

    public static function syncBatch(): int
    {
        return max(5, min(200, (int) self::get('idpass_sync_batch')));
    }

    /** What the Settings page shows and saves. */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $k) {
            $out[$k] = $k === 'idpass_terms' ? implode("\n", self::terms()) : self::get($k);
        }

        return $out;
    }
}
