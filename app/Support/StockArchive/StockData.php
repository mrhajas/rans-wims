<?php

namespace App\Support\StockArchive;

use Illuminate\Support\Facades\DB;

/**
 * The whole company's stock for the Stock Archive: every warehouse / project that holds something, its items grouped by category
 * (items of every variation added together). Plain queries, no models, so it is fast and runs the same from the scheduler and from a page.
 */
class StockData
{
    /**
     * @return array{warehouses: array<int, array>, totals: array{warehouses:int, items:int, rows:int, value:float, categories:int}}
     */
    public static function build(int $accountId): array
    {
        $stock = DB::table('stocks')
            ->join('items', 'items.id', '=', 'stocks.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stocks.warehouse_id')
            ->where('stocks.account_id', $accountId)
            ->whereNull('stocks.deleted_at')->whereNull('items.deleted_at')->whereNull('warehouses.deleted_at')
            ->where(fn ($q) => $q->where('stocks.quantity', '!=', 0)->orWhere('stocks.weight', '!=', 0))
            ->groupBy('stocks.warehouse_id', 'stocks.item_id')
            ->selectRaw('stocks.warehouse_id, stocks.item_id, SUM(stocks.quantity) AS qty, SUM(stocks.weight) AS wt')
            ->get();

        $itemIds = $stock->pluck('item_id')->unique()->values();
        $items = collect();
        foreach ($itemIds->chunk(1500) as $chunk) {
            $items = $items->union(DB::table('items')->leftJoin('units', 'units.id', '=', 'items.unit_id')->whereIn('items.id', $chunk)
                ->get(['items.id', 'items.code', 'items.name', 'items.price', 'items.track_weight', 'units.name as unit'])->keyBy('id'));
        }
        // the first category of an item is the one it is listed under
        $category = [];
        foreach ($itemIds->chunk(1500) as $chunk) {
            foreach (DB::table('categorizables')->join('categories', 'categories.id', '=', 'categorizables.category_id')
                ->where('categorizables.categorizable_type', 'App\\Models\\Item')->whereIn('categorizables.categorizable_id', $chunk)->whereNull('categories.deleted_at')
                ->orderBy('categories.id')->get(['categorizables.categorizable_id as item_id', 'categories.name']) as $c) {
                $category[$c->item_id] ??= $c->name;
            }
        }

        $warehouses = DB::table('warehouses')->whereIn('id', $stock->pluck('warehouse_id')->unique())->get(['id', 'name', 'status', 'code'])->keyBy('id');
        $out = [];
        $totals = ['warehouses' => 0, 'items' => 0, 'rows' => 0, 'value' => 0.0, 'categories' => 0];
        $allItems = [];
        $allCats = [];
        foreach ($stock->groupBy('warehouse_id') as $wid => $rows) {
            $w = $warehouses[$wid] ?? null;
            if (! $w) {
                continue;
            }
            $cats = [];
            $wValue = 0.0;
            foreach ($rows as $r) {
                $it = $items[$r->item_id] ?? null;
                if (! $it) {
                    continue;
                }
                $qty = (float) $r->qty;
                $weight = (float) $r->wt;
                $price = (float) $it->price;
                $value = $qty * $price;
                $cat = $category[$r->item_id] ?? 'Uncategorised';
                $cats[$cat][] = ['code' => (string) $it->code, 'name' => (string) $it->name, 'unit' => (string) ($it->unit ?? ''), 'qty' => $qty, 'weight' => $weight, 'price' => $price, 'value' => $value];
                $wValue += $value;
                $allItems[$r->item_id] = true;
                $allCats[$cat] = true;
                $totals['rows']++;
            }
            if (! $cats) {
                continue;
            }
            uksort($cats, fn ($a, $b) => ($a === 'Uncategorised') <=> ($b === 'Uncategorised') ?: strcasecmp($a, $b));
            foreach ($cats as &$list) {
                usort($list, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
            }
            unset($list);
            $out[] = ['id' => (int) $wid, 'name' => (string) $w->name, 'group' => self::group((string) $w->status), 'status' => (string) $w->status, 'categories' => $cats, 'items' => collect($cats)->sum(fn ($l) => count($l)), 'value' => $wValue];
            $totals['value'] += $wValue;
        }
        // Main Warehouses first, then the projects, then the rest; each by name
        $order = ['Main Warehouses' => 0, 'Projects' => 1, 'Others' => 2];
        usort($out, fn ($a, $b) => ($order[$a['group']] <=> $order[$b['group']]) ?: strcasecmp($a['name'], $b['name']));
        $totals['warehouses'] = count($out);
        $totals['items'] = count($allItems);
        $totals['categories'] = count($allCats);

        return ['warehouses' => $out, 'totals' => $totals];
    }

    public static function group(string $status): string
    {
        $s = mb_strtolower(trim($status));
        if (str_starts_with($s, 'main warehouse')) {
            return 'Main Warehouses';
        }

        return in_array($s, ['government', 'private', 'directors'], true) ? 'Projects' : 'Others';
    }
}
