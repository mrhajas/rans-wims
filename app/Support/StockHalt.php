<?php

namespace App\Support;

use App\Models\AdjustmentSession;
use Illuminate\Support\Facades\DB;

/**
 * Stock movement halt. While an Adjustment Session is open it can also halt the stock movement of its Project / Warehouse: nothing can be received into it,
 * issued from it or moved to or from it (check-ins, check-outs, transfers, reallocations, issues, returns, normal adjustments) until the session ends or the
 * halt is released. The Super Adjustments of the count itself are not blocked. Everybody is held by it, a Super Admin too, so a count is never disturbed.
 *
 * The halt is stored on the session (halt_stock, halted_by_user_id, halted_at); this class only reads it, one small query per request.
 */
class StockHalt
{
    /** @var array<int,array>|null memo for the current request: warehouse id => halt */
    private static ?array $memo = null;

    public static function forget(): void
    {
        self::$memo = null;
    }

    /** @return array<int,array> every halted warehouse: id => [warehouse_id, warehouse, session_id, reference, by, by_id, at] */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        try {
            $rows = DB::table('adjustment_sessions as s')
                ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
                ->leftJoin('users as u', 'u.id', '=', 's.halted_by_user_id')
                ->where('s.status', AdjustmentSession::STATUS_OPEN)->where('s.halt_stock', 1)->whereNull('s.deleted_at')
                ->get(['s.warehouse_id', 's.id as session_id', 's.reference', 's.halted_by_user_id', 's.halted_at', 'w.name as warehouse', 'u.name as by']);
        } catch (\Throwable $e) {
            return self::$memo = []; // the halt columns are not there yet (migration not run): nothing is halted
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->warehouse_id] = [
                'warehouse_id' => (int) $r->warehouse_id, 'warehouse' => $r->warehouse, 'session_id' => (int) $r->session_id, 'reference' => $r->reference,
                'by' => $r->by ?: __('an authorised user'), 'by_id' => $r->halted_by_user_id ? (int) $r->halted_by_user_id : null,
                'at' => $r->halted_at ? \Illuminate\Support\Carbon::parse($r->halted_at)->toIso8601String() : null,
            ];
        }

        return self::$memo = $out;
    }

    /** The halt on this warehouse, or null. */
    public static function for(?int $warehouseId): ?array
    {
        return $warehouseId ? (self::all()[(int) $warehouseId] ?? null) : null;
    }

    /** The first halt among these warehouses (nulls and duplicates are ignored), or null when none is halted. */
    public static function blocking(...$warehouseIds): ?array
    {
        $halts = self::all();
        if (! $halts) {
            return null;
        }
        foreach (array_unique(array_filter(array_map(fn ($i) => $i ? (int) $i : null, array_merge(...array_map(fn ($x) => (array) $x, $warehouseIds))))) as $id) {
            if (isset($halts[$id])) {
                return $halts[$id];
            }
        }

        return null;
    }

    /** The sentence shown to whoever tries to move stock of a halted warehouse; it always names the person who halted it. */
    public static function message(array $halt): string
    {
        $when = $halt['at'] ? \Illuminate\Support\Carbon::parse($halt['at'])->timezone(config('app.timezone'))->format('d M Y H:i') : '';

        return __('Stock movement for :w has been halted by :n:when because of the physical count in Adjustment Session :r. Nothing can be received into it, issued from it or moved to or from it until the session ends or the halt is released.', [
            'w' => $halt['warehouse'], 'n' => $halt['by'], 'when' => $when ? ' ' . __('on') . ' ' . $when : '', 'r' => $halt['reference'],
        ]);
    }

    /** What the pages need to mark halted warehouses: id => [by, at, reference, session_id]. */
    public static function forPages(): array
    {
        $out = [];
        foreach (self::all() as $id => $h) {
            $out[$id] = ['by' => $h['by'], 'at' => $h['at'], 'reference' => $h['reference'], 'session_id' => $h['session_id'], 'message' => self::message($h)];
        }

        return $out;
    }
}
