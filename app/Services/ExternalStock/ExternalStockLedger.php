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
    private static function base()
    {
        return DB::table('external_stock_items as i')->join('external_stock_movements as m', 'm.id', '=', 'i.movement_id')->whereNull('i.deleted_at')->whereNull('m.deleted_at');
    }

    /** @return array{in:float,out:float,balance:float,reserved:float,available:float} for one person / warehouse / item (optionally leaving one movement out). */
    public static function position(int $contactId, int $warehouseId, int $itemId, ?int $exceptMovementId = null): array
    {
        $rows = self::base()->where('m.contact_id', $contactId)->where('m.warehouse_id', $warehouseId)->where('i.item_id', $itemId)
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
     * Which warehouse does a Stock Out come from when nobody chose one? The first warehouse (by name) where this person's available stock covers every line.
     * Returns the warehouse id, or null when no single warehouse covers it all (the person must then choose, or ask for less).
     *
     * @param array<int,array{item_id:int,quantity:float|string}> $lines
     * @param array<int,int>|null $allowedWarehouseIds null = all
     */
    public static function resolveWarehouse(int $contactId, array $lines, ?array $allowedWarehouseIds, ?int $exceptMovementId = null): ?int
    {
        $ids = self::base()->where('m.contact_id', $contactId)->whereIn('m.status', [ExternalStockMovement::APPROVED, ExternalStockMovement::PENDING])
            ->when($allowedWarehouseIds !== null, fn ($q) => $q->whereIn('m.warehouse_id', $allowedWarehouseIds ?: [0]))
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')->distinct()->orderBy('w.name')->pluck('m.warehouse_id');
        foreach ($ids as $wid) {
            if (! self::shortfalls($contactId, (int) $wid, $lines, $exceptMovementId)) {
                return (int) $wid;
            }
        }

        return null;
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
        $q = self::base()->join('items as it', 'it.id', '=', 'i.item_id')->join('contacts as c', 'c.id', '=', 'm.contact_id')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->whereIn('m.status', [ExternalStockMovement::APPROVED, ExternalStockMovement::PENDING])
            ->when(! empty($f['contact_id']), fn ($w) => $w->where('m.contact_id', (int) $f['contact_id']))
            ->when(! empty($f['warehouse_id']), fn ($w) => $w->where('m.warehouse_id', (int) $f['warehouse_id']))
            ->when(isset($f['warehouse_ids']) && $f['warehouse_ids'] !== null, fn ($w) => $w->whereIn('m.warehouse_id', $f['warehouse_ids'] ?: [0]))
            ->when(! empty($f['item_id']), fn ($w) => $w->where('i.item_id', (int) $f['item_id']))
            ->when(! empty($f['as_of']), fn ($w) => $w->whereDate('m.date', '<=', $f['as_of']))
            ->when(trim((string) ($f['search'] ?? '')) !== '', fn ($w) => $w->where(fn ($x) => $x->where('c.name', 'like', '%' . trim($f['search']) . '%')->orWhere('it.name', 'like', '%' . trim($f['search']) . '%')->orWhere('it.code', 'like', '%' . trim($f['search']) . '%')))
            // the report's Item filter: only the item's own name or code (a person's name must not pull in their other items)
            ->when(trim((string) ($f['item_search'] ?? '')) !== '', fn ($w) => $w->where(fn ($x) => $x->where('it.name', 'like', '%' . trim($f['item_search']) . '%')->orWhere('it.code', 'like', '%' . trim($f['item_search']) . '%')))
            ->selectRaw("m.contact_id, c.name as contact, m.warehouse_id, w.name as warehouse, i.item_id, it.name as item, it.code as code, it.unit_id,
                SUM(CASE WHEN m.status = 'Approved' AND m.kind <> 'withdrawal' THEN i.quantity ELSE 0 END) as q_in,
                SUM(CASE WHEN m.status = 'Approved' AND m.kind = 'withdrawal' THEN i.quantity ELSE 0 END) as q_out,
                SUM(CASE WHEN m.status = 'Pending Approval' AND m.kind = 'withdrawal' THEN i.quantity ELSE 0 END) as q_reserved,
                MAX(CASE WHEN m.status = 'Approved' THEN m.date END) as last_date")
            ->groupBy('m.contact_id', 'c.name', 'm.warehouse_id', 'w.name', 'i.item_id', 'it.name', 'it.code', 'it.unit_id')
            ->orderBy('c.name')->orderBy('w.name')->orderBy('it.name');

        $rows = $q->get()->map(function ($r) {
            $bal = round((float) $r->q_in - (float) $r->q_out, 4);

            return (object) ['contact_id' => (int) $r->contact_id, 'contact' => $r->contact, 'warehouse_id' => (int) $r->warehouse_id, 'warehouse' => $r->warehouse, 'item_id' => (int) $r->item_id, 'item' => $r->item, 'code' => $r->code,
                'unit_id' => $r->unit_id, 'in' => round((float) $r->q_in, 4), 'out' => round((float) $r->q_out, 4), 'balance' => $bal, 'reserved' => round((float) $r->q_reserved, 4), 'available' => round($bal - (float) $r->q_reserved, 4), 'last_date' => $r->last_date];
        });

        return ! empty($f['only_stock']) ? $rows->filter(fn ($r) => $r->balance > 0.00005 || $r->reserved > 0)->values() : $rows;
    }

    /**
     * Would these lines be covered? $lines = [[item_id, quantity], ...] for one person and warehouse. Returns the problems (empty = fine).
     * $exceptMovementId leaves a movement out (an edit, or the movement being approved, must not count against itself).
     *
     * @return array<int,string>
     */
    public static function shortfalls(int $contactId, int $warehouseId, array $lines, ?int $exceptMovementId = null, bool $useAvailable = true): array
    {
        $need = [];
        foreach ($lines as $l) {
            $need[(int) $l['item_id']] = ($need[(int) $l['item_id']] ?? 0) + (float) $l['quantity'];
        }
        $names = DB::table('items')->whereIn('id', array_keys($need))->pluck('name', 'id');
        $out = [];
        foreach ($need as $itemId => $qty) {
            $p = self::position($contactId, $warehouseId, $itemId, $exceptMovementId);
            $have = $useAvailable ? $p['available'] : $p['balance'];
            if ($qty > $have + 0.00005) {
                $out[$itemId] = __(':item: :n requested but only :have is held for this person in this warehouse.', ['item' => $names[$itemId] ?? '#' . $itemId, 'n' => self::fmt($qty), 'have' => self::fmt(max(0, $have))]);
            }
        }

        return $out;
    }

    public static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0';
    }
}
