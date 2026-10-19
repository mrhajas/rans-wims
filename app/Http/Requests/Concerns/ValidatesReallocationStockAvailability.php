<?php

namespace App\Http\Requests\Concerns;

use App\Models\Stock;
use App\Models\Unit;
use Illuminate\Validation\Validator;

trait ValidatesReallocationStockAvailability
{
    /**
     * A reallocation can only move what physically exists at the source warehouse —
     * checked against the same base-unit `Stock` quantity the rest of the app uses
     * (Checkin/Checkout/Transfer/Adjustment), converting each requested quantity to
     * base units first since `Stock::quantity` is always stored in base units.
     */
    protected function validateStockAvailability(Validator $validator): void
    {
        $warehouseId = (int) $this->input('source_warehouse_id');
        $items = (array) $this->input('items', []);

        if (! $warehouseId || empty($items)) {
            return;
        }

        $unitsById = Unit::whereIn('id', collect($items)->pluck('unit_id')->filter()->unique())->get()->keyBy('id');

        foreach ($items as $index => $item) {
            $itemId = $item['item_id'] ?? null;
            $requestedQty = (float) ($item['requested_quantity'] ?? 0);

            if (! $itemId || $requestedQty <= 0) {
                continue;
            }

            $unit = isset($item['unit_id']) ? $unitsById->get($item['unit_id']) : null;
            $baseQty = convert_to_base_quantity($requestedQty, $unit);

            $available = (float) (Stock::query()->main()->ofWarehouse($warehouseId)->where('item_id', $itemId)->value('quantity') ?? 0);

            if ($available <= 0) {
                $validator->errors()->add(
                    "items.{$index}.requested_quantity",
                    __('This item is not available in stock at the selected warehouse.')
                );
            } elseif ($baseQty > $available) {
                $validator->errors()->add(
                    "items.{$index}.requested_quantity",
                    __('Only :qty available at the selected warehouse, you requested more than is in stock.', [
                        'qty' => $this->formatStockQuantity($available),
                    ])
                );
            }
        }
    }

    private function formatStockQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');
    }
}
