<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\Stock;
use App\Models\SuperAdjustment;

/**
 * The value report of a Super Adjustment or an Adjustment: for every item the unit price, the quantity there was (before), what that is worth, the quantity there is
 * after, what THAT is worth, and the difference in quantity and in value. One place works all of it out, so the page, the print, the PDF and the Excel always agree.
 *
 *  - Money is worked out in whole cents: a line's value is price x quantity rounded to the cent, the difference of a line is its value after minus its value before,
 *    and every total is the sum of the lines, so the totals always add up exactly to what is printed under them.
 *  - "Above" is the sum of the lines whose value went up, "Below" the sum of the lines whose value went down (as a positive amount); Above minus Below is the net difference.
 *  - Super Adjustment: the system quantity is the one the approval really compared the count with. For an Approved count that is the physical count minus what the generated
 *    Adjustments added (plus what they took away): stock may have moved between the submission and the approval, so the figure kept at submission is not used. Before approval it is the stock today.
 *  - Adjustment: the quantity before and after come from AdjustmentDifferences (the stock trail), in the stock unit of the item.
 */
class ValueReport
{
    public static function forSuperAdjustment(SuperAdjustment $sa): array
    {
        $sa->loadMissing(['items.item:id,name,code,price', 'items.unit:id,name,code', 'warehouse:id,name,code', 'user:id,name', 'approver:id,name', 'additionAdjustment', 'subtractionAdjustment']);
        $approved = $sa->status === SuperAdjustment::STATUS_APPROVED;
        $live = Stock::withoutGlobalScopes()->main()->ofWarehouse($sa->warehouse_id)->whereIn('item_id', $sa->items->pluck('item_id'))->pluck('quantity', 'item_id');
        $added = $approved && $sa->additionAdjustment ? $sa->additionAdjustment->items()->pluck('quantity', 'item_id') : collect();
        $taken = $approved && $sa->subtractionAdjustment ? $sa->subtractionAdjustment->items()->pluck('quantity', 'item_id') : collect();

        $lines = [];
        foreach ($sa->items as $i) {
            $physical = (float) $i->physical_quantity;
            if ($approved) {
                // the count minus what the approval added (plus what it took away) is the system quantity the approval compared the count with
                $system = round($physical - (float) ($added[$i->item_id] ?? 0) + (float) ($taken[$i->item_id] ?? 0), 4);
            } else {
                $system = round((float) ($live[$i->item_id] ?? 0), 4);
            }
            $lines[] = ['name' => optional($i->item)->name ?? __('(Item removed)'), 'code' => optional($i->item)->code, 'unit' => optional($i->unit)->name ?: optional($i->unit)->code,
                'price' => (float) (optional($i->item)->price ?? 0), 'before' => $system, 'after' => $physical, 'cleared' => (bool) $i->auto_cleared];
        }

        return self::build($lines, [
            'kind' => 'super_adjustment', 'reference' => $sa->reference, 'warehouse' => optional($sa->warehouse)->name, 'date' => optional($sa->date)->toDateString() ?? (string) $sa->date, 'status' => $sa->status,
            'labels' => ['before' => __('System quantity'), 'before_total' => __('Total in System'), 'after' => __('Physical Count'), 'after_total' => __('Total Physical')],
            'basis' => $approved ? __('System quantity is the stock the approval compared the count with (the count minus what the approval added or took away).') : __('Not approved yet: System quantity is the stock today, which is what approving now would compare the count with.'),
        ]);
    }

    public static function forAdjustment(Adjustment $a): array
    {
        $a->loadMissing(['items.item:id,name,code,price,track_quantity', 'items.unit:id,name,code', 'warehouse:id,name,code']);
        $diff = AdjustmentDifferences::for($a);
        $lines = [];
        foreach ($a->items as $i) {
            $d = $diff['rows'][$i->id] ?? null;
            if (! $d || $d['was'] === null || $d['now'] === null) {
                continue;
            }
            $lines[] = ['name' => optional($i->item)->name ?? __('(Item removed)'), 'code' => optional($i->item)->code, 'unit' => optional($i->unit)->name ?: optional($i->unit)->code,
                'price' => (float) (optional($i->item)->price ?? 0), 'before' => (float) $d['was'], 'after' => (float) $d['now'], 'cleared' => false];
        }

        return self::build($lines, [
            'kind' => 'adjustment', 'reference' => $a->reference, 'warehouse' => optional($a->warehouse)->name, 'date' => optional($a->date)->toDateString() ?? (string) $a->date, 'status' => $a->status,
            'labels' => ['before' => __('Was'), 'before_total' => __('Value before'), 'after' => __('Now'), 'after_total' => __('Value after')],
            'basis' => $diff['basis'] === 'projected' ? __('Not approved yet: Was is the stock today and Now is what it will be once approved.') : __('Was and Now are the stock of the warehouse before and after this adjustment.'),
            'available' => $diff['basis'] !== 'none',
        ]);
    }

    /** The lines (name, code, unit, price, before, after) with their values, the differences and the totals, all in whole cents. */
    public static function build(array $lines, array $meta): array
    {
        $cents = fn (float $price, float $qty): int => (int) round(round($price * $qty, 6) * 100);
        $rows = [];
        $t = ['before_qty' => 0.0, 'after_qty' => 0.0, 'before' => 0, 'after' => 0, 'above' => 0, 'below' => 0, 'above_items' => 0, 'below_items' => 0, 'no_price' => 0];
        foreach ($lines as $l) {
            $before = round((float) $l['before'], 4);
            $after = round((float) $l['after'], 4);
            $b = $cents((float) $l['price'], $before);
            $a = $cents((float) $l['price'], $after);
            $dv = $a - $b;
            $dq = round($after - $before, 4);
            $rows[] = $l + ['before' => $before, 'after' => $after, 'before_total' => $b / 100, 'after_total' => $a / 100, 'diff' => $dq, 'diff_value' => $dv / 100];
            $t['before_qty'] += $before;
            $t['after_qty'] += $after;
            $t['before'] += $b;
            $t['after'] += $a;
            if ($dv > 0) {
                $t['above'] += $dv;
            } elseif ($dv < 0) {
                $t['below'] += -$dv;
            }
            if ($dq > 0) {
                $t['above_items']++;
            } elseif ($dq < 0) {
                $t['below_items']++;
            }
            if ((float) $l['price'] <= 0) {
                $t['no_price']++;
            }
        }
        $totals = [
            'items' => count($rows), 'before_qty' => round($t['before_qty'], 4), 'after_qty' => round($t['after_qty'], 4), 'diff_qty' => round($t['after_qty'] - $t['before_qty'], 4),
            'before' => $t['before'] / 100, 'after' => $t['after'] / 100, 'diff' => ($t['after'] - $t['before']) / 100, 'above' => $t['above'] / 100, 'below' => $t['below'] / 100,
            'above_items' => $t['above_items'], 'below_items' => $t['below_items'], 'no_price' => $t['no_price'],
        ];

        return $meta + ['available' => true] + ['rows' => $rows, 'totals' => $totals];
    }
}
