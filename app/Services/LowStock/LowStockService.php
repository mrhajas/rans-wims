<?php

namespace App\Services\LowStock;

use App\Mail\LowStockSummaryMail;
use App\Models\Account;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\WimsConnect\CommunicationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/** Finds the watched materials that are low in the watched projects, and sends the daily Low Stock Alert (email and / or text message). */
class LowStockService
{
    public const TZ = 'Africa/Dar_es_Salaam';

    public static function available(): bool
    {
        try {
            return FeatureManager::isEnabled('low_stock_alert') && \App\Support\SchemaCache::hasTable('stocks');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every watched (project, item) stock record with its state: ok, low or out. An item that fits several groups is listed once, under the first.
     * Main Warehouses are stores rather than projects and are left out unless the setting says otherwise.
     *
     * @return Collection<int, array>
     */
    public static function stockRows(array $cfg, int $accountId, ?array $onlyWarehouseIds = null): Collection
    {
        $seen = [];
        $rows = collect();
        foreach ($cfg['groups'] as $gi => $g) {
            if (! $g['enabled'] || (! $g['keywords'] && ! $g['item_ids'])) {
                continue;
            }
            $q = DB::table('stocks')
                ->join('items', 'items.id', '=', 'stocks.item_id')
                ->join('warehouses', 'warehouses.id', '=', 'stocks.warehouse_id')
                ->leftJoin('units', 'units.id', '=', 'items.unit_id')
                ->where('stocks.account_id', $accountId)
                ->whereNull('stocks.variation_id')->whereNull('stocks.deleted_at')->whereNull('items.deleted_at')->whereNull('warehouses.deleted_at')
                ->where('warehouses.active', 1)
                ->when($cfg['exclude_main_warehouses'] ?? true, fn ($qq) => $qq->where(fn ($w) => $w->whereNull('warehouses.status')->orWhere('warehouses.status', '!=', 'Main Warehouse')))
                ->where(function ($w) use ($g) {
                    foreach ($g['keywords'] as $k) {
                        $w->orWhere('items.name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $k).'%');
                    }
                    if ($g['item_ids']) {
                        $w->orWhereIn('items.id', $g['item_ids']);
                    }
                })
                ->when($g['exclude'], function ($qq) use ($g) {
                    foreach ($g['exclude'] as $x) {
                        // an item picked by hand is never dropped by an exclusion word
                        $qq->where(fn ($w) => $w->where('items.name', 'not like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $x).'%')->orWhereIn('items.id', $g['item_ids'] ?: [0]));
                    }
                })
                ->when($g['projects']['mode'] === 'selected', fn ($qq) => $qq->whereIn('stocks.warehouse_id', $g['projects']['ids'] ?: [0]))
                ->when($onlyWarehouseIds !== null, fn ($qq) => $qq->whereIn('stocks.warehouse_id', $onlyWarehouseIds ?: [0]))
                ->select('stocks.warehouse_id', 'warehouses.name as warehouse', 'items.id as item_id', 'items.name as item', 'items.code', 'units.code as unit', 'stocks.quantity', 'stocks.alert_quantity as stock_alert', 'items.alert_quantity as item_alert');

            foreach ($q->get() as $r) {
                $key = $r->warehouse_id.'-'.$r->item_id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $qty = (float) $r->quantity;
                $level = $g['below'] > 0 ? (float) $g['below'] : max((float) $r->stock_alert, (float) $r->item_alert);
                $out = $qty <= 0;
                $low = $out || ($level > 0 && $qty <= $level);
                $rows->push([
                    'gi' => $gi, 'group' => $g['name'], 'color' => $g['color'], 'keyword' => $g['keywords'][0] ?? '',
                    'warehouse_id' => (int) $r->warehouse_id, 'warehouse' => $r->warehouse,
                    'item_id' => (int) $r->item_id, 'item' => $r->item, 'code' => $r->code, 'unit' => $r->unit ?: '',
                    'qty' => $qty, 'level' => $level, 'out' => $out, 'state' => $out ? 'out' : ($low ? 'low' : 'ok'),
                    'ratio' => $level > 0 ? max(0, min(1, $qty / $level)) : 0,
                ]);
            }
        }

        return $rows;
    }

    /**
     * Only the records that are low or out (used for the "send only when something is low" rule and the text message).
     *
     * @return Collection<int, array>
     */
    public static function rows(array $cfg, int $accountId, ?array $onlyWarehouseIds = null): Collection
    {
        return self::stockRows($cfg, $accountId, $onlyWarehouseIds)->where('state', '!=', 'ok')->values();
    }

    /** A short column title: the item name without the group's own word (STEEL Y10 under Steel becomes Y10). */
    private static function shortName(string $name, string $keyword): string
    {
        $kw = trim(explode(' ', $keyword)[0] ?? '');
        if ($kw !== '' && mb_stripos($name, $kw.' ') === 0 && mb_strlen($name) > mb_strlen($kw) + 1) {
            return trim(mb_substr($name, mb_strlen($kw) + 1));
        }

        return $name;
    }

    /**
     * The overview: every project (one row) against every watched item (one column), with the quantity in each cell.
     * A project that does not stock an item shows an empty cell (null), which is different from zero.
     * `$showAll` false keeps only the projects that have something low or out.
     */
    public static function digest(Collection $stock, bool $showAll = true): array
    {
        // columns: group order first, then item name
        $columns = $stock->groupBy('item_id')->map(function (Collection $r) {
            $f = $r->first();

            return ['item_id' => $f['item_id'], 'item' => $f['item'], 'code' => $f['code'], 'unit' => $f['unit'], 'gi' => $f['gi'], 'group' => $f['group'], 'color' => $f['color'], 'keyword' => $f['keyword']];
        })->sortBy([['gi', 'asc'], ['item', 'asc']])->values()->all();
        // short titles: inside a big group (steel) the group's own word is dropped, and two identical titles get their code added
        $perGroup = collect($columns)->countBy('gi');
        foreach ($columns as &$c) {
            $short = $perGroup[$c['gi']] >= 3 ? self::shortName($c['item'], $c['keyword']) : $c['item'];
            $c['short'] = $short;
        }
        unset($c);
        $dups = collect($columns)->countBy(fn ($c) => $c['gi'].'|'.$c['short'])->filter(fn ($n) => $n > 1)->keys()->all();
        foreach ($columns as &$c) {
            if (in_array($c['gi'].'|'.$c['short'], $dups, true) && $c['code']) {
                $c['short'] .= ' ('.$c['code'].')';
            }
        }
        unset($c);

        $projects = $stock->groupBy('warehouse_id')->map(function (Collection $r) {
            $cells = [];
            foreach ($r as $x) {
                $cells[$x['item_id']] = ['qty' => $x['qty'], 'state' => $x['state'], 'level' => $x['level'], 'unit' => $x['unit']];
            }
            $bad = $r->where('state', '!=', 'ok');

            return [
                'id' => $r->first()['warehouse_id'], 'name' => $r->first()['warehouse'],
                'out' => $r->where('state', 'out')->count(), 'low' => $r->where('state', 'low')->count(),
                'cells' => $cells,
                // the low and out items alone (used by the text message)
                'items' => $bad->sortBy([['out', 'desc'], ['ratio', 'asc'], ['item', 'asc']])->values()->all(),
            ];
        })->when(! $showAll, fn ($c) => $c->filter(fn ($p) => $p['out'] + $p['low'] > 0))
            ->sortBy([['out', 'desc'], ['low', 'desc'], ['name', 'asc']])->values()->all();

        // a column nobody shows any more is dropped
        $ids = collect($projects)->flatMap(fn ($p) => array_keys($p['cells']))->unique()->all();
        $columns = array_values(array_filter($columns, fn ($c) => in_array($c['item_id'], $ids, true)));

        $shown = $stock->whereIn('warehouse_id', array_column($projects, 'id'));
        $groups = [];
        foreach ($columns as $c) {
            $groups[$c['gi']] ??= ['name' => $c['group'], 'color' => $c['color'], 'span' => 0];
            $groups[$c['gi']]['span']++;
        }

        return [
            'projects' => $projects,
            'columns' => $columns,
            'groupSpans' => array_values($groups),
            'totals' => [
                'projects' => count($projects),
                'items' => $shown->where('state', '!=', 'ok')->count(),
                'out' => $shown->where('state', 'out')->count(),
                'low' => $shown->where('state', 'low')->count(),
                'ok' => $shown->where('state', 'ok')->count(),
                'watched' => count($columns),
                'with_problem' => collect($projects)->filter(fn ($p) => $p['out'] + $p['low'] > 0)->count(),
            ],
            'groups' => array_column($groups, 'name'),
        ];
    }

    /** The overview for everybody (or the given projects only), ready for the email, the PDF and the preview. */
    public static function build(array $cfg, int $accountId, ?array $onlyWarehouseIds = null): array
    {
        return self::digest(self::stockRows($cfg, $accountId, $onlyWarehouseIds), (bool) $cfg['show_all_items']);
    }

    /** The overview as PDF bytes (landscape, one to two pages). */
    public static function pdf(array $digest, array $cfg, string $generatedAt, string $by): string
    {
        $logoFile = public_path('images/logo.png');

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('low-stock-pdf', [
            'digest' => $digest, 'config' => $cfg, 'generatedAt' => $generatedAt, 'by' => $by,
            'logo' => file_exists($logoFile) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoFile)) : null,
        ])->setPaper('a4', 'landscape')->output();
    }

    /** @return Collection<int, User> */
    public static function recipients(array $cfg, int $accountId): Collection
    {
        $byId = $cfg['user_ids'] ? User::query()->whereIn('id', $cfg['user_ids'])->get() : collect();
        $byRole = $cfg['role_names'] ? User::role($cfg['role_names'])->get() : collect();

        return $byId->merge($byRole)->unique('id')->filter(fn (User $u) => $u->is_active !== false && $u->account_id == $accountId)->values();
    }

    /** The projects this person should hear about, or null for all of them. */
    public static function scopeFor(array $cfg, User $user): ?array
    {
        if (! $cfg['scope_by_assignment'] || $user->hasRole(['Super Admin', 'Admin'])) {
            return null;
        }
        $ids = DB::table('user_warehouse')->where('user_id', $user->id)->pluck('warehouse_id')->map(fn ($i) => (int) $i)->all();

        return $ids ?: null;
    }

    /** True when the saved time of day (East Africa Time) has been reached today on a chosen day and nothing was sent yet today. */
    public static function isDue(array $cfg, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now(self::TZ);
        if (! $cfg['enabled'] || ($cfg['last_sent_on'] ?? null) === $now->toDateString()) {
            return false;
        }
        if ($cfg['send_days'] && ! in_array($now->dayOfWeek, $cfg['send_days'], true)) {
            return false;
        }
        [$h, $m] = array_map('intval', explode(':', $cfg['send_time']));

        return $now >= $now->setTime($h, $m, 0);
    }

    /**
     * Sends today's alert to everybody chosen, each with only their own projects.
     *
     * @return array{recipients:int, emails:int, sms:int, items:int}
     */
    public static function send(int $accountId, bool $force = false, ?string $onlyToEmail = null, bool $sample = false): array
    {
        $cfg = LowStockConfig::get($accountId);
        $out = ['recipients' => 0, 'emails' => 0, 'sms' => 0, 'items' => 0];
        if (! $force && ! $sample && ! $cfg['enabled']) {
            return $out;
        }
        $now = CarbonImmutable::now(self::TZ);
        $account = Account::find($accountId);
        $smsRecipients = collect();

        foreach (self::recipients($cfg, $accountId) as $user) {
            $stock = self::stockRows($cfg, $accountId, self::scopeFor($cfg, $user));
            $rows = $stock->where('state', '!=', 'ok');
            if ($rows->isEmpty() && $cfg['only_when_low'] && ! $sample) {
                continue;
            }
            $digest = self::digest($stock, (bool) $cfg['show_all_items']);
            $out['recipients']++;
            $out['items'] += $rows->count();
            $to = $onlyToEmail ?: $user->contactEmail(); // the email switched on for them in Recipients
            if ($cfg['email'] && filled($to)) {
                try {
                    $stamp = $now->format('l, d F Y · H:i').' EAT';
                    $pdf = $cfg['attach_pdf'] ? self::pdf($digest, $cfg, $stamp, 'WIMS') : null;
                    Mail::to($to, $user->name)->send(new LowStockSummaryMail($digest, $user->name, $stamp, $cfg, $sample, $pdf, 'low-stock-alert-'.$now->format('Y-m-d').'.pdf'));
                    $out['emails']++;
                } catch (\Throwable $e) {
                    Log::error('LowStock: email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
            if ($cfg['sms'] && $rows->isNotEmpty() && ! $onlyToEmail && FeatureManager::isEnabled('wims_connect') && FeatureManager::isEnabled('wims_connect_sms')) {
                $smsRecipients->push([$user, self::smsText($digest, $now)]);
            }
            if ($onlyToEmail) {
                break; // a test goes to one inbox only
            }
        }

        if ($smsRecipients->isNotEmpty() && $account) {
            foreach ($smsRecipients as [$user, $text]) {
                try {
                    app(CommunicationService::class)->notifyDirect('sms', 'low-stock-alert', $account, collect([$user]), $text, $now->format('Ymd'));
                    $out['sms']++;
                } catch (\Throwable $e) {
                    Log::error('LowStock: sms failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        }

        if (! $onlyToEmail && ! $sample) {
            LowStockConfig::markSent($accountId, $now->toDateString());
            if ($out['recipients'] > 0 && $account) {
                // Stock Alerts workflow page: "Low Stock Alert Sent"
                $who = auth()->user();
                \App\Services\Workflow\WorkflowMessenger::dispatch('stock_alerts', 'summary_sent', $account, $who, [
                    'account_id' => $accountId, 'title' => 'Low Stock Alert Sent', 'url' => route('low-stock-alert.index'), 'dedupe' => 'sent|' . $now->format('YmdHi') . '|' . ($force ? 'now' : 'auto'),
                    'message' => sprintf('The Low Stock Alert was sent%s to %d people: %d email(s), %d text message(s), %d item(s) at or below their threshold.', $who ? ' by ' . $who->name : '', $out['recipients'], $out['emails'], $out['sms'], $out['items']),
                ]);
            }
        }

        return $out;
    }

    /** A short text message: no links, at most about 300 characters. */
    public static function smsText(array $digest, CarbonImmutable $now): string
    {
        $t = $digest['totals'];
        $bad = array_values(array_filter($digest['projects'], fn ($p) => ! empty($p['items'])));
        $head = sprintf('WIMS Low Stock %s: %d item(s) low in %d project(s)%s.', $now->format('d M'), $t['items'], count($bad), $t['out'] ? ", {$t['out']} out of stock" : '');
        $parts = [];
        foreach (array_slice($bad, 0, 3) as $p) {
            $items = collect($p['items'])->take(2)->map(fn ($i) => $i['item'].' '.rtrim(rtrim(number_format($i['qty'], 1), '0'), '.').' '.$i['unit'])->implode(', ');
            $parts[] = $p['name'].': '.$items;
        }
        $text = $head.' '.implode('; ', $parts);
        if (count($bad) > 3) {
            $text .= '; +'.(count($bad) - 3).' more';
        }

        return mb_strlen($text) > 300 ? mb_substr($text, 0, 297).'...' : $text.'. Details by email.';
    }
}
