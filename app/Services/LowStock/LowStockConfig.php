<?php

namespace App\Services\LowStock;

use App\Models\Setting;

/**
 * Low Stock Alert settings (WIMS Notification > Low Stock Alert): who is told, by email and/or text message, at what time, and which
 * materials to watch in which projects. Stored as JSON in the settings table, per account. The starting watch list is Cement, Aggregate
 * and the steel bars (Y8, Y10, ...); every group has its own projects, keywords / items and its own "low" level.
 */
class LowStockConfig
{
    public const KEY = 'low_stock_config';

    public const COLORS = ['#b45309', '#0f766e', '#4f46e5', '#be123c', '#0369a1', '#7c3aed'];

    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'email' => true,
            'sms' => false,
            'user_ids' => [],
            'role_names' => ['Super Admin'],
            'scope_by_assignment' => true,   // project people only hear about their own projects (Super Admin and Admin hear about all)
            'send_time' => '07:00',          // East Africa Time
            'send_days' => [],               // 0 = Sunday ... 6 = Saturday; empty = every day
            'only_when_low' => true,         // stay quiet on days when nothing is low
            'warehouse_email' => true,       // keep emailing each project's own address (the older behaviour)
            'exclude_main_warehouses' => true, // Main Warehouses are stores, not projects: leave them out
            'show_all_items' => true,        // list every watched item with its quantity in each project, not only the low ones
            'attach_pdf' => true,            // attach the PDF overview to the email
            'migrated' => ['sand'],
            'last_sent_on' => null,
            'groups' => [
                ['id' => 'cement', 'name' => 'Cement', 'enabled' => true, 'color' => '#b45309', 'keywords' => ['cement'], 'exclude' => ['white', 'sample'], 'item_ids' => [], 'below' => 50, 'projects' => ['mode' => 'all', 'ids' => []]],
                ['id' => 'aggregate', 'name' => 'Aggregate', 'enabled' => true, 'color' => '#0f766e', 'keywords' => ['aggregate'], 'exclude' => ['sample'], 'item_ids' => [], 'below' => 5, 'projects' => ['mode' => 'all', 'ids' => []]],
                ['id' => 'sand', 'name' => 'Sand', 'enabled' => true, 'color' => '#a16207', 'keywords' => ['sand'], 'exclude' => ['paper', 'sample'], 'item_ids' => [], 'below' => 5, 'projects' => ['mode' => 'all', 'ids' => []]],
                ['id' => 'steel', 'name' => 'Steel bars (Y8, Y10, Y12 ...)', 'enabled' => true, 'color' => '#4f46e5', 'keywords' => ['steel y'], 'exclude' => ['sample'], 'item_ids' => [], 'below' => 30, 'projects' => ['mode' => 'all', 'ids' => []]],
            ],
        ];
    }

    public static function get(int $accountId): array
    {
        $raw = Setting::query()->where('tec_key', self::KEY)->where('account_id', $accountId)->value('tec_value');
        $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        $c = self::clean($saved);
        // a configuration saved before Sand was added gets the Sand group once (the person can then change or delete it)
        if ($saved && ! in_array('sand', $c['migrated'], true)) {
            if (! collect($c['groups'])->contains(fn ($g) => str_contains(mb_strtolower($g['name']), 'sand'))) {
                $c['groups'][] = collect(self::defaults()['groups'])->firstWhere('id', 'sand');
            }
            $c['migrated'][] = 'sand';
            Setting::updateOrCreate(['tec_key' => self::KEY, 'account_id' => $accountId], ['tec_value' => json_encode($c)]);
        }

        return $c;
    }

    public static function save(array $input, int $accountId): array
    {
        $old = self::get($accountId);
        $c = self::clean($input);
        $c['last_sent_on'] = $old['last_sent_on'];
        $c['migrated'] = array_values(array_unique(array_merge($old['migrated'], $c['migrated'], ['sand'])));
        Setting::updateOrCreate(['tec_key' => self::KEY, 'account_id' => $accountId], ['tec_value' => json_encode($c)]);

        return $c;
    }

    public static function markSent(int $accountId, string $date): void
    {
        $c = self::get($accountId);
        $c['last_sent_on'] = $date;
        Setting::updateOrCreate(['tec_key' => self::KEY, 'account_id' => $accountId], ['tec_value' => json_encode($c)]);
    }

    /** Fills gaps and drops anything that does not belong, so the rest of the code can trust the shape. */
    public static function clean(array $in): array
    {
        $d = self::defaults();
        $ints = fn ($a) => array_values(array_unique(array_map('intval', (array) $a)));
        $strs = fn ($a) => array_values(array_filter(array_map(fn ($s) => trim(mb_substr((string) $s, 0, 60)), (array) $a), fn ($s) => $s !== ''));
        $c = [
            'enabled' => (bool) ($in['enabled'] ?? $d['enabled']),
            'email' => (bool) ($in['email'] ?? $d['email']),
            'sms' => (bool) ($in['sms'] ?? $d['sms']),
            'user_ids' => $ints($in['user_ids'] ?? $d['user_ids']),
            'role_names' => $strs($in['role_names'] ?? $d['role_names']),
            'scope_by_assignment' => (bool) ($in['scope_by_assignment'] ?? $d['scope_by_assignment']),
            'send_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($in['send_time'] ?? '')) ? $in['send_time'] : $d['send_time'],
            'send_days' => array_values(array_unique(array_filter($ints($in['send_days'] ?? []), fn ($n) => $n >= 0 && $n <= 6))),
            'only_when_low' => (bool) ($in['only_when_low'] ?? $d['only_when_low']),
            'warehouse_email' => (bool) ($in['warehouse_email'] ?? $d['warehouse_email']),
            'exclude_main_warehouses' => (bool) ($in['exclude_main_warehouses'] ?? $d['exclude_main_warehouses']),
            'show_all_items' => (bool) ($in['show_all_items'] ?? $d['show_all_items']),
            'attach_pdf' => (bool) ($in['attach_pdf'] ?? $d['attach_pdf']),
            'migrated' => array_values(array_unique(array_map('strval', (array) ($in['migrated'] ?? [])))),
            'last_sent_on' => $in['last_sent_on'] ?? null,
            'groups' => [],
        ];
        $groups = array_key_exists('groups', $in) ? (array) $in['groups'] : $d['groups'];
        foreach (array_slice($groups, 0, 30) as $i => $g) {
            $name = trim(mb_substr((string) ($g['name'] ?? ''), 0, 60));
            if ($name === '') {
                continue;
            }
            $c['groups'][] = [
                'id' => preg_replace('/[^a-z0-9_-]/i', '', (string) ($g['id'] ?? '')) ?: 'g'.($i + 1).substr(md5($name), 0, 4),
                'name' => $name, 'enabled' => (bool) ($g['enabled'] ?? true),
                'color' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($g['color'] ?? '')) ? $g['color'] : self::COLORS[$i % count(self::COLORS)],
                'keywords' => $strs($g['keywords'] ?? []), 'exclude' => $strs($g['exclude'] ?? []), 'item_ids' => $ints($g['item_ids'] ?? []),
                'below' => max(0, min(1000000, (float) ($g['below'] ?? 0))),
                'projects' => ['mode' => ($g['projects']['mode'] ?? 'all') === 'selected' ? 'selected' : 'all', 'ids' => $ints($g['projects']['ids'] ?? [])],
            ];
        }

        return $c;
    }
}
