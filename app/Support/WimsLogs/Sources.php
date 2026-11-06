<?php

namespace App\Support\WimsLogs;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * The logs WIMS Logs archives. Each one is a table that only grows and is only looked at for its recent rows: how old a row must be before it is moved (days, a setting),
 * which rows may never be moved (those that are still being worked on), and the one line that describes a record in the lists.
 *
 * Business records (stock, items, requests, transfers, approvals, sign-in history ...) are not here and are never touched.
 */
class Sources
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'weather' => [
                'label' => 'Weather forecasts',
                'help' => 'Every forecast and reading the Weather module saved. The newest ones are all the pages use.',
                'table' => 'weather_snapshots', 'pk' => 'id', 'date' => 'fetched_at', 'keep_days' => 7,
                'user' => null,
                'eligible' => null,
                'summary' => fn (array $r) => trim(($r['kind'] ?? '') . ' ' . ($r['location_key'] ?? '') . ' (' . ($r['provider'] ?? '') . ', ' . ($r['status'] ?? '') . ')'),
            ],
            'delivery' => [
                'label' => 'Delivery logs (SMS, e-mail, alerts)',
                'help' => 'What was sent to people, with the full text of every e-mail. Messages still waiting, retrying or failed are never moved: they stay live to be worked on.',
                'table' => 'wims_connect_logs', 'pk' => 'id', 'date' => 'created_at', 'keep_days' => 14,
                'user' => fn (array $r) => ((int) ($r['recipient_user_id'] ?? 0)) ?: null,
                'eligible' => fn (Builder $q) => $q->whereIn('status', ['SENT', 'DELIVERED']),
                'summary' => fn (array $r) => trim(($r['channel'] ?? '') . ' · ' . ($r['event_key'] ?? '') . ' · ' . ($r['address'] ?? '-') . ' · ' . Str::limit((string) (($r['subject'] ?? '') !== '' ? $r['subject'] : ($r['message'] ?? '')), 90, '…')),
            ],
            'downloads' => [
                'label' => 'Download and print logs',
                'help' => 'Who downloaded or printed which page or report, and when.',
                'table' => 'download_print_logs', 'pk' => 'id', 'date' => 'performed_at', 'keep_days' => 60,
                'user' => fn (array $r) => ((int) ($r['user_id'] ?? 0)) ?: null,
                'eligible' => null,
                'summary' => fn (array $r) => trim(($r['action_type'] ?? '') . ' · ' . ($r['module'] ?? '') . ' · ' . ($r['user_name'] ?? '-') . ' · ' . Str::limit((string) (($r['file_name'] ?? '') !== '' ? $r['file_name'] : ($r['route_name'] ?? '')), 80, '…')),
            ],
            'alerts' => [
                'label' => 'Bell alerts that were read',
                'help' => 'Alerts that people have already opened. Alerts not yet read are never moved.',
                'table' => 'notifications', 'pk' => 'id', 'date' => 'created_at', 'keep_days' => 30,
                'pk_type' => 'string',
                'user' => fn (array $r) => str_contains((string) ($r['notifiable_type'] ?? ''), 'User') ? (((int) ($r['notifiable_id'] ?? 0)) ?: null) : null,
                'eligible' => fn (Builder $q) => $q->whereNotNull('read_at'),
                'summary' => function (array $r) {
                    $d = json_decode((string) ($r['data'] ?? ''), true);
                    $d = is_array($d) ? $d : [];

                    return Str::limit(trim((string) ($d['title'] ?? $d['module'] ?? '') . ' · ' . ($d['message'] ?? $d['body'] ?? '')), 140, '…');
                },
            ],
            'system' => [
                'label' => 'System logs (activity history)',
                'help' => 'The page called System Logs: who did what, and when, across WIMS. Records older than the days below are moved out of the live list and stay readable here. Business records themselves are never touched.',
                'table' => 'activity_log', 'pk' => 'id', 'date' => 'created_at', 'keep_days' => 365,
                'user' => fn (array $r) => str_contains((string) ($r['causer_type'] ?? ''), 'User') ? (((int) ($r['causer_id'] ?? 0)) ?: null) : null,
                'eligible' => null,
                'summary' => fn (array $r) => Str::limit(trim(($r['log_name'] ?? '') . ' · ' . ($r['description'] ?? '') . ' · ' . (($r['subject_type'] ?? '') !== '' ? class_basename((string) $r['subject_type']) . (($r['subject_id'] ?? null) ? ' #' . $r['subject_id'] : '') : '-') . ' · user ' . ($r['causer_id'] ?? '-')), 200, '…'),
            ],
            'slow' => [
                'label' => 'Slow pages and queries',
                'help' => 'The record of pages and database queries that were slower than the limits set in Settings > Performance & Data.',
                'table' => 'slow_logs', 'pk' => 'id', 'date' => 'created_at', 'keep_days' => 7,
                'user' => fn (array $r) => ((int) ($r['user_id'] ?? 0)) ?: null,
                'eligible' => null,
                'summary' => fn (array $r) => trim(($r['kind'] ?? '') . ' · ' . ($r['method'] ?? '') . ' ' . Str::limit((string) (($r['path'] ?? '') !== '' ? $r['path'] : ($r['label'] ?? '')), 80, '…') . ' · ' . ($r['duration_ms'] ?? '') . ' ms'),
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
