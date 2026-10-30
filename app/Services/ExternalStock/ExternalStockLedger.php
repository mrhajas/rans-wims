<?php

namespace App\Services\ExternalStock;

use App\Models\ExternalStockMovement;
use Illuminate\Support\Facades\DB;

/**
 * The ledger of External Personnel stock. For each person, warehouse and item:
 *   balance   = approved Stock In + approved opening stock - approved Stock Out
 *   reserved  = Stock Outs that are still waiting for approval (so the same stock cannot be promised twice)
 *   available = balance - reserved
 * Rejected and cancelled movements count for nothing. The company's own stock figures are never touched.
 */
class ExternalStockLedger
{
    /** The warehouse of a line: its own, and the movement's for lines saved before each item had one. */
    public const WH = 'COALESCE(i.warehouse_id, m.warehouse_id)';

    private static function base()
    {
        return DB::table('external_stock_items as i')->join('external_stock_movements as m', 'm.id', '=', 'i.movement_id')->whereNull('i.deleted_at')->whereNull('m.deleted_at');
    }

    /** @return array{in:float,out:float,balance:float,reserved:float,available:float} for one person / warehouse / item (optionally leaving one movement out). */
    public static function position(int $contactId, int $warehouseId, int $itemId, ?int $exceptMovementId = null): array
    {
        $rows = self::base()->where('m.contact_id', $contactId)->whereRaw(self::WH . ' = ?', [$warehouseId])->where('i.item_id', $itemId)
            ->when($exceptMovementId, fn ($q) => $q->where('m.id', '<>', $exceptMovementId))
            ->selectRaw("m.status, m.kind, SUM(i.quantity) q")->groupBy('m.status', 'm.kind')->get();

        return self::fold($rows);
    }

    /** The same figures for one person and item across every warehouse (a Stock Out that does not name a warehouse). */
    public static function positionAnywhere(int $contactId, int $itemId, ?int $exceptMovementId = null): array
    {
        $rows = self::base()->where('m.contact_id', $contactId)->where('i.item_id', $itemId)
            ->when($exceptMovementId, fn ($q) => $q->where('m.id', '<>', $exceptMovementId))
            ->selectRaw("m.status, m.kind, SUM(i.quantity) q")->groupBy('m.status', 'm.kind')->get();

        return self::fold($rows);
    }

    /**
     * Where does this person hold this item? One row per warehouse that still has available stock, the one with the most first.
     *
     * @param array<int,int>|null $allowedWarehouseIds null = all
     * @return array<int,array{warehouse_id:int,warehouse:string,balance:float,reserved:float,available:float}>
     */
    public static function holders(int $contactId, int $itemId, ?array $allowedWarehouseIds = null, ?int $exceptMovementId = null): array
    {
        $wids = self::base()->where('m.contact_id', $contactId)->where('i.item_id', $itemId)->whereIn('m.status', [ExternalStockMovement::APPROVED, ExternalStockMovement::PENDING])
            ->when($allowedWarehouseIds !== null, fn ($q) => $q->whereIn(DB::raw(self::WH), $allowedWarehouseIds ?: [0]))
            ->selectRaw(self::WH . ' as wid')->distinct()->pluck('wid');
        $names = DB::table('warehouses')->whereIn('id', $wids)->pluck('name', 'id');
        $out = [];
        foreach ($wids as $wid) {
            $p = self::position($contactId, (int) $wid, $itemId, $exceptMovementId);
            if ($p['available'] > 0.00005) {
                $out[] = ['warehouse_id' => (int) $wid, 'warehouse' => (string) ($names[$wid] ?? '#' . $wid)] + $p;
            }
        }
        usort($out, fn ($a, $b) => $b['available'] <=> $a['available'] ?: strcmp($a['warehouse'], $b['warehouse']));

        return $out;
    }

    private static function fold($rows): array
    {
        $in = $out = $reserved = 0.0;
        foreach ($rows as $r) {
            if ($r->status === ExternalStockMovement::APPROVED) {
                $r->kind === 'withdrawal' ? $out += (float) $r->q : $in += (float) $r->q;
            } elseif ($r->status === ExternalStockMovement::PENDING && $r->kind === 'withdrawal') {
                $reserved += (float) $r->q;
            }
        }
        $balance = round($in - $out, 4);

        return ['in' => round($in, 4), 'out' => round($out, 4), 'balance' => $balance, 'reserved' => round($reserved, 4), 'available' => round($balance - $reserved, 4)];
    }

    /**
     * Balances, one row per person / warehouse / item, for a report or a screen. Filters: contact_id, warehouse_id, item_id, search, only_stock (balance > 0),
     * warehouse_ids (limit to what the viewer may see), as_of (a date: only movements dated on or before it).
     */
    public static function balances(array $f = [])
    {
        $q = self::base()->join('items as it', 'it.id', '=', 'i.item_id')->join('contacts as c', 'c.id', '=', 'm.contact_id')->join('warehouses as w', 'w.id', '=', DB::raw(self::WH))
            ->whereIn('m.status', [ExternalStockMovement::APPROVED, ExternalStockMovement::PENDING])
            ->when(! empty($f['contact_id']), fn ($w) => $w->where('m.contact_id', (int) $f['contact_id']))
            ->when(! empty($f['warehouse_id']), fn ($w) => $w->whereRaw(self::WH . ' = ?', [(int) $f['warehouse_id']]))
            ->when(isset($f['warehouse_ids']) && $f['warehouse_ids'] !== null, fn ($w) => $w->whereIn(DB::raw(self::WH), $f['warehouse_ids'] ?: [0]))
            ->when(! empty($f['item_id']), fn ($w) => $w->where('i.item_id', (int) $f['item_id']))
            ->when(! empty($f['as_of']), fn ($w) => $w->whereDate('m.date', '<=', $f['as_of']))
            ->when(trim((string) ($f['search'] ?? '')) !== '', fn ($w) => $w->where(fn ($x) => $x->where('c.name', 'like', '%' . trim($f['search']) . '%')->orWhere('it.name', 'like', '%' . trim($f['search']) . '%')->orWhere('it.code', 'like', '%' . trim($f['search']) . '%')))
            // the report's Item filter: only the item's own name or code (a person's name must not pull in their other items)
            ->when(trim((string) ($f['item_search'] ?? '')) !== '', fn ($w) => $w->where(fn ($x) => $x->where('it.name', 'like', '%' . trim($f['item_search']) . '%')->orWhere('it.code', 'like', '%' . trim($f['item_search']) . '%')))
            ->selectRaw("m.contact_id, c.name as contact, w.id as warehouse_id, w.name as warehouse, i.item_id, it.name as item, it.code as code, it.unit_id,
                SUM(CASE WHEN m.status = 'Approved' AND m.kind <> 'withdrawal' THEN i.quantity ELSE 0 END) as q_in,
                SUM(CASE WHEN m.status = 'Approved' AND m.kind = 'withdrawal' THEN i.quantity ELSE 0 END) as q_out,
                SUM(CASE WHEN m.status = 'Pending Approval' AND m.kind = 'withdrawal' THEN i.quantity ELSE 0 END) as q_reserved,
                MAX(CASE WHEN m.status = 'Approved' THEN m.date END) as last_date")
            ->groupBy('m.contact_id', 'c.name', 'w.id', 'w.name', 'i.item_id', 'it.name', 'it.code', 'it.unit_id')
            ->orderBy('c.name')->orderBy('w.name')->orderBy('it.name');

        $rows = $q->get()->map(function ($r) {
            $bal = round((float) $r->q_in - (float) $r->q_out, 4);

            return (object) ['contact_id' => (int) $r->contact_id, 'contact' => $r->contact, 'warehouse_id' => (int) $r->warehouse_id, 'warehouse' => $r->warehouse, 'item_id' => (int) $r->item_id, 'item' => $r->item, 'code' => $r->code,
                'unit_id' => $r->unit_id, 'in' => round((float) $r->q_in, 4), 'out' => round((float) $r->q_out, 4), 'balance' => $bal, 'reserved' => round((float) $r->q_reserved, 4), 'available' => round($bal - (float) $r->q_reserved, 4), 'last_date' => $r->last_date];
        });

        return ! empty($f['only_stock']) ? $rows->filter(fn ($r) => $r->balance > 0.00005 || $r->reserved > 0)->values() : $rows;
    }

    /**
     * Would these lines be covered? $lines = [[item_id, quantity, warehouse_id?], ...] for one person; a line without a warehouse uses $warehouseId.
     * Returns the problems keyed "warehouse|item" (empty = fine). $exceptMovementId leaves a movement out (an edit, or the movement being approved, must not count against itself).
     *
     * @return array<string,string>
     */
    public static function shortfalls(int $contactId, ?int $warehouseId, array $lines, ?int $exceptMovementId = null, bool $useAvailable = true): array
    {
        $need = [];
        foreach ($lines as $l) {
            $wid = (int) (($l['warehouse_id'] ?? null) ?: $warehouseId);
            $k = $wid . '|' . (int) $l['item_id'];
            $need[$k] = ($need[$k] ?? 0) + (float) $l['quantity'];
        }
        $names = DB::table('items')->whereIn('id', collect(array_keys($need))->map(fn ($k) => (int) explode('|', $k)[1])->all())->pluck('name', 'id');
        $whs = DB::table('warehouses')->whereIn('id', collect(array_keys($need))->map(fn ($k) => (int) explode('|', $k)[0])->all())->pluck('name', 'id');
        $out = [];
        foreach ($need as $k => $qty) {
            [$wid, $itemId] = array_map('intval', explode('|', $k));
            $p = self::position($contactId, $wid, $itemId, $exceptMovementId);
            $have = $useAvailable ? $p['available'] : $p['balance'];
            if ($qty > $have + 0.00005) {
                $out[$k] = __(':item: :n requested but only :have is held for this person in :wh.', ['item' => $names[$itemId] ?? '#' . $itemId, 'n' => self::fmt($qty), 'have' => self::fmt(max(0, $have)), 'wh' => $whs[$wid] ?? __('this warehouse')]);
            }
        }

        return $out;
    }

    public static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0';
    }
}
