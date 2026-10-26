<?php

namespace App\Support\StockArchive;

use App\Models\Setting;

/**
 * Settings of the Stock Archive (Super Admin): when the report is made and sent, who gets it (roles, people and extra e-mail addresses) and how many
 * days the entries are kept. Stored as JSON in the settings table, per account, so nothing is fixed in the code.
 */
class ArchiveSettings
{
    public const KEY = 'stock_archive_config';

    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'send_day' => 1,            // 0 = Sunday ... 6 = Saturday; Monday
            'send_time' => '07:00',     // East Africa Time
            'keep_days' => 60,          // entries older than this are removed (their file too); 0 = keep for ever
            'email' => true,            // e-mail the report with the PDF attached
            'role_names' => [],         // everybody with one of these roles
            'user_ids' => [],           // and these people
            'extra_emails' => ['extriontech@gmail.com'], // and these addresses (testing)
            'last_run_on' => null,
        ];
    }

    public static function get(int $accountId): array
    {
        $raw = Setting::query()->where('tec_key', self::KEY)->where('account_id', $accountId)->value('tec_value');

        return self::clean(is_string($raw) ? (json_decode($raw, true) ?: []) : [], true);
    }

    public static function save(array $input, int $accountId): array
    {
        $old = self::get($accountId);
        $c = self::clean($input, false);
        $c['last_run_on'] = $old['last_run_on'];
        Setting::updateOrCreate(['tec_key' => self::KEY, 'account_id' => $accountId], ['tec_value' => json_encode($c)]);

        return $c;
    }

    public static function markRun(int $accountId, string $date): void
    {
        $c = self::get($accountId);
        $c['last_run_on'] = $date;
        Setting::updateOrCreate(['tec_key' => self::KEY, 'account_id' => $accountId], ['tec_value' => json_encode($c)]);
    }

    /** Fills gaps and drops anything that does not belong, so the rest of the code can trust the shape. $saved: the stored copy (gaps take the defaults). */
    public static function clean(array $in, bool $saved): array
    {
        $d = self::defaults();
        $pick = fn ($k) => array_key_exists($k, $in) ? $in[$k] : ($saved ? $d[$k] : $d[$k]);
        $emails = collect((array) $pick('extra_emails'))->map(fn ($e) => mb_strtolower(trim((string) $e)))->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->values()->all();

        return [
            'enabled' => (bool) $pick('enabled'),
            'send_day' => max(0, min(6, (int) $pick('send_day'))),
            'send_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $pick('send_time')) ? $pick('send_time') : $d['send_time'],
            'keep_days' => max(0, min(3650, (int) $pick('keep_days'))),
            'email' => (bool) $pick('email'),
            'role_names' => array_values(array_unique(array_filter(array_map(fn ($s) => trim(mb_substr((string) $s, 0, 60)), (array) $pick('role_names'))))),
            'user_ids' => array_values(array_unique(array_map('intval', (array) $pick('user_ids')))),
            'extra_emails' => array_slice($emails, 0, 30),
            'last_run_on' => $in['last_run_on'] ?? null,
        ];
    }
}
