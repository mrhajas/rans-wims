<?php

namespace App\Services\SmartBrief;

use App\Models\Setting;

/** Smart Brief defaults, kept as one JSON setting per account ('smart_brief_settings'). Changed on the Smart Brief Settings page. */
class Settings
{
    public const KEY = 'smart_brief_settings';

    public const DEFAULTS = [
        'default_period' => 'today',
        'max_range_days' => 92,
        'timeline_limit' => 500,
        'show_line_items' => false,
        // Smart Brief's own log lines older than this many days are removed automatically (0 = never)
        'auto_clear_days' => 30,
        // Raw activity-log names that are bookkeeping rather than something a person did (stock rows and the logs of logs).
        'excluded_log_names' => ['Stock', 'StockTrail', 'LoginLog', 'DownloadPrintLog'],
        'ai_enabled' => false,
    ];

    public static function get(?int $accountId = null): array
    {
        $accountId ??= (int) (auth()->user()->account_id ?? 1);
        $raw = Setting::query()->where('tec_key', self::KEY)->where('account_id', $accountId)->value('tec_value');
        $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        return array_replace(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    public static function save(array $data, ?int $accountId = null): array
    {
        $accountId ??= (int) (auth()->user()->account_id ?? 1);
        $clean = array_replace(self::get($accountId), array_intersect_key($data, self::DEFAULTS));
        Setting::query()->updateOrCreate(['tec_key' => self::KEY, 'account_id' => $accountId], ['tec_value' => json_encode($clean)]);

        return $clean;
    }
}
