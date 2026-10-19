<?php

namespace App\Services;

use App\Models\FundAllocation;

/**
 * Every number shown on a funds-allocation sheet, computed in one place so the form, the detail page, the list,
 * the reports and the print view always agree.
 *
 *  available   : sum of the day's balances converted to TZS
 *  requested   : sum of the item request amounts
 *  allocated   : sum of the item allocated amounts
 *  balance     : available - allocated  ("balance after this expenses" on the paper sheet)
 *  by_category : request / allocated / paid per category (in category sort order)
 *  by_method   : planned and paid per payment method (the TT / CASH columns, plus a USD column whose figures are in USD)
 *  usd         : total USD to pay on the sheet (lines paid in USD = the USD column), paid and still to pay
 *  paid / unpaid / progress : what the payer has ticked so far
 *  unreconciled_items : items whose payment lines do not add up to their allocated amount
 */
class FundAllocationTotals
{
    public static function for(FundAllocation $allocation): array
    {
        $allocation->loadMissing(['balances', 'items.lines.method', 'items.category']);

        $available = round((float) $allocation->balances->sum('amount_tzs'), 2);
        $requested = round((float) $allocation->items->sum('request_amount'), 2);
        $allocated = round((float) $allocation->items->sum('allocated_amount'), 2);

        $byCategory = [];
        $byMethod = [];
        $paid = 0.0;
        $planned = 0.0;
        $unreconciled = [];
        $usdPlanned = 0.0;
        $usdPaid = 0.0;

        foreach ($allocation->items as $item) {
            $cat = $item->category;
            $key = $item->fund_category_id;
            $byCategory[$key] ??= ['id' => $key, 'name' => optional($cat)->name, 'sort' => optional($cat)->sort_order ?? 0, 'items' => 0, 'requested' => 0.0, 'allocated' => 0.0, 'paid' => 0.0];
            $byCategory[$key]['items']++;
            $byCategory[$key]['requested'] += (float) $item->request_amount;
            $byCategory[$key]['allocated'] += (float) $item->allocated_amount;

            $lineSum = 0.0;
            foreach ($item->lines as $line) {
                $lineSum += (float) $line->amount;
                $m = $line->fund_payment_method_id;
                $byMethod[$m] ??= ['id' => $m, 'name' => optional($line->method)->name, 'short' => optional($line->method)->short_label, 'currency' => optional($line->method)->currency ?: 'TZS', 'sort' => optional($line->method)->sort_order ?? 0, 'planned' => 0.0, 'paid' => 0.0, 'usd_planned' => 0.0, 'usd_paid' => 0.0, 'lines' => 0, 'paid_lines' => 0];
                $byMethod[$m]['planned'] += (float) $line->amount;
                $usd = (float) $line->usd_amount;
                $byMethod[$m]['usd_planned'] += $usd;
                $usdPlanned += $usd;
                $byMethod[$m]['lines']++;
                $planned += (float) $line->amount;
                if ($line->is_paid) {
                    $byMethod[$m]['paid'] += (float) $line->amount;
                    $byMethod[$m]['usd_paid'] += $usd;
                    $usdPaid += $usd;
                    $byMethod[$m]['paid_lines']++;
                    $byCategory[$key]['paid'] += (float) $line->amount;
                    $paid += (float) $line->amount;
                }
            }
            if (abs($lineSum - (float) $item->allocated_amount) > 0.009) {
                $unreconciled[] = ['id' => $item->id, 'description' => $item->description, 'allocated' => (float) $item->allocated_amount, 'lines' => round($lineSum, 2)];
            }
        }

        $cats = collect($byCategory)->sortBy('sort')->values()->map(fn ($c) => array_merge($c, ['requested' => round($c['requested'], 2), 'allocated' => round($c['allocated'], 2), 'paid' => round($c['paid'], 2)]))->all();
        $meths = collect($byMethod)->sortBy('sort')->values()->map(fn ($m) => array_merge($m, ['planned' => round($m['planned'], 2), 'paid' => round($m['paid'], 2), 'usd_planned' => round($m['usd_planned'], 2), 'usd_paid' => round($m['usd_paid'], 2)]))->all();

        return [
            'available' => $available,
            'requested' => $requested,
            'allocated' => $allocated,
            'balance' => round($available - $allocated, 2),
            'overspent' => $allocated > $available + 0.009,
            'planned' => round($planned, 2),
            'paid' => round($paid, 2),
            'unpaid' => round($planned - $paid, 2),
            'progress' => $planned > 0 ? round($paid / $planned * 100, 1) : 0,
            'item_count' => $allocation->items->count(),
            'paid_item_count' => $allocation->items->filter(fn ($i) => $i->isFullyPaid())->count(),
            'usd' => ['planned' => round($usdPlanned, 2), 'paid' => round($usdPaid, 2), 'unpaid' => round($usdPlanned - $usdPaid, 2)],
            'by_category' => $cats,
            'by_method' => $meths,
            'unreconciled_items' => $unreconciled,
        ];
    }
}
