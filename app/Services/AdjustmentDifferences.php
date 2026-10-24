<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\AdjustmentItem;
use App\Models\Stock;
use App\Models\StockTrail;

/**
 * What an Adjustment did to each item: how much stock there was (Was), how much it changed (Change) and how much there was afterwards (Now).
 *
 *  - Approved: the stock trail written when the stock was changed keeps the quantity AFTER the change, so Was = that quantity minus the change. That also holds for old records,
 *    because the trail has been written since the module started. A record without a trail shows the change only.
 *  - Pending: nothing has happened yet, so Was is the stock today and Now is what it will be once approved (a projection).
 *  - Rejected: stock was never touched, only the change that was asked for is shown.
 * Quantities are in the item's stock unit (the entered quantity converted with its unit, as the stock itself is). "Today" is the stock right now, for the moment it differs from Now.
 */
class AdjustmentDifferences
{
    /** @return array{rows: array<int,array>, totals: array, basis: string} */
    public static function for(Adjustment $a): array
    {
        $items = $a->relationLoaded('items') ? $a->items : $a->items()->get();
        $items->loadMissing(['item:id,track_quantity', 'unit']);
        $sign = $a->type === 'Addition' ? 1 : -1;
        $status = $a->status;
        // a deleted record has had its stock effect taken back: only the change it asked for is known
        $deleted = (bool) $a->deleted_at;
        $ids = $items->pluck('id')->all();
        $itemIds = $items->pluck('item_id')->unique()->all();

        // the quantity after the last time each line changed the stock (the trail type says Updating for an increase and Editing for a decrease, so the latest trail of the line is what counts)
        $after = [];
        if ($status === Adjustment::STATUS_APPROVED && ! $deleted && $ids) {
            StockTrail::withoutGlobalScopes()->where('subject_type', AdjustmentItem::class)->whereIn('subject_id', $ids)->orderBy('id')
                ->get(['subject_id', 'quantity'])->each(function ($t) use (&$after) {
                    $after[$t->subject_id] = (float) $t->quantity;
                });
        }
        $today = $itemIds ? Stock::withoutGlobalScopes()->main()->ofWarehouse($a->warehouse_id)->whereIn('item_id', $itemIds)->pluck('quantity', 'item_id')->map(fn ($q) => (float) $q)->all() : [];

        $rows = [];
        $added = 0.0;
        $removed = 0.0;
        foreach ($items as $i) {
            $tracks = (bool) optional($i->item)->track_quantity;
            $change = $tracks ? round($sign * (float) convert_to_base_quantity((float) $i->quantity, $i->unit), 4) : null;
            $row = ['change' => $change, 'was' => null, 'now' => null, 'today' => $today[$i->item_id] ?? ($tracks ? 0.0 : null), 'basis' => 'none'];
            if ($change !== null) {
                $change > 0 ? $added += $change : $removed += abs($change);
                if ($deleted) {
                    $row['basis'] = 'none';
                } elseif ($status === Adjustment::STATUS_APPROVED && isset($after[$i->id])) {
                    $row['now'] = round($after[$i->id], 4);
                    $row['was'] = round($after[$i->id] - $change, 4);
                    $row['basis'] = 'applied';
                } elseif ($status === Adjustment::STATUS_PENDING && ! $deleted) {
                    $row['was'] = round((float) ($today[$i->item_id] ?? 0), 4);
                    $row['now'] = round($row['was'] + $change, 4);
                    $row['basis'] = 'projected';
                } elseif ($status === Adjustment::STATUS_APPROVED) {
                    $row['basis'] = 'unknown';
                }
            }
            $rows[$i->id] = $row;
        }

        return [
            'rows' => $rows,
            'totals' => ['added' => round($added, 4), 'removed' => round($removed, 4), 'net' => round($added - $removed, 4)],
            'basis' => $deleted ? 'none' : ($status === Adjustment::STATUS_APPROVED ? 'applied' : ($status === Adjustment::STATUS_PENDING ? 'projected' : 'none')),
        ];
    }
}
