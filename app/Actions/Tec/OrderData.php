<?php

namespace App\Actions\Tec;

use App\Models\Checkin;
use App\Models\Transfer;
use App\Models\Adjustment;

class OrderData
{
    public function __invoke($form, $model)
    {
        $checkin = ($model instanceof Checkin);
        $transfer = ($model instanceof Transfer);
        $adjustment = ($model instanceof Adjustment);
        $form['items'] = collect($form['items'])->transform(function ($item, $index) use ($form, $checkin, $adjustment, $transfer) {
            $item['serials'] = [];
            if ($adjustment && ! empty($item['selected']['serials'])) {
                $item['serials'] = $item['selected']['serials'];
            } elseif ($checkin && ! empty($item['selected']['serials'])) {
                $serials = $item['selected']['serials'];
                foreach ($serials as $serial) {
                    if (isset($serial['till']) && ! empty($serial['till'])) {
                        for ($i = $serial['number']; $i < $serial['till']; $i++) {
                            $item['serials'][] = $serial['number'] + $i;
                        }
                    } else {
                        $item['serials'][] = isset($serial['number']) && ! empty($serial['number']) ? $serial['number'] : $serial;
                    }
                }
            } else {
                if (! empty($item['selected']['serials'])) {
                    $item['serials'] = $item['selected']['serials'];
                }
            }

            $item['variations'] = $item['selected']['variations'] ?? [];
            if (! empty($item['variations'])) {
                $item['weight'] = 0;
                $item['quantity'] = 0;
                $track_weight = get_settings('track_weight');
                foreach ($item['variations'] as &$variation) {
                    if ($track_weight && ($item['track_weight'] ?? null)) {
                        $item['weight'] += $variation['weight'];
                    }
                    if ($item['track_quantity'] ?? true) {
                        $item['quantity'] += $variation['quantity'];
                    }
                    unset($variation['old_quantity']);
                }
                unset($variation);
                // sync() reads a list's POSITIONS as the ids to link, so [0, 1] would be linked (and an edit would rewrite the wrong rows);
                // keyed by the variation's own id each variation is matched to its own row, and one listed twice is counted once
                $item['variations'] = collect($item['variations'])->filter(fn ($v) => ! empty($v['variation_id']))->keyBy('variation_id')->all();
            }
            unset($item['selected']);
            $item['order'] = $index;
            if ($adjustment) {
                $item['type'] = $form['type'];
            }
            if ($transfer) {
                $item['to_warehouse_id'] = $form['to_warehouse_id'];
                $item['from_warehouse_id'] = $form['from_warehouse_id'];
            } else {
                $item['warehouse_id'] = $form['warehouse_id'];
            }
            $item['draft'] = $form['draft'] ?? false;
            unset($item['old_quantity']);

            return $item;
        });

        return $form;
    }
}
