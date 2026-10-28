<?php

namespace App\Http\Requests;

use App\Helpers\CheckOverSelling;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class TransferRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $referenceRule = $this->isMethod('post')
            ? 'nullable|max:50|unique:transfers,reference'
            : 'nullable|max:50';

        return [
            'details'       => 'nullable',
            'attachments'   => 'nullable',
            'draft'         => 'nullable|boolean',
            'material_request_id' => 'nullable|integer|exists:material_requests,id',
            'attachments.*' => 'mimes:' . attachment_exts('jpg,png,pdf,docx,xlsx,zip'),

            'date'              => 'required|date',
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id'   => 'required|exists:warehouses,id|different:from_warehouse_id',
            'reference'         => $referenceRule,

            'items'                    => 'required|array|min:1',
            'items.*.id'               => 'nullable',
            'items.*.transfer_item_id' => 'nullable',
            'items.*.has_serials'      => 'nullable',
            'items.*.has_variants'     => 'nullable',
            'items.*.quantity'         => 'required|numeric|gt:0',
            'items.*.old_quantity'     => 'nullable|numeric',
            'items.*.item_id'          => 'required|exists:items,id',
            'items.*.unit_id'          => 'nullable|exists:units,id',
            'items.*.selected'         => 'nullable|array|required_if:items.*.has_variants,1',
            'items.*.weight'           => 'nullable|numeric|min:0|required_if:items.*.track_weight,1',
        ];
    }

    public function messages()
    {
        return [
            'items.required'     => __('Add at least one item to the list.'),
            'items.min'          => __('Add at least one item to the list.'),
            'items.*.item_id.required' => __('Choose an item.'),
        ];
    }

    public function withValidator($validator)
    {
        // The checks the plain rules cannot express: one row per item, the unit must be the item's own (or one of its sub-units),
        // and every chosen variation needs a real quantity. They add to the same items.{n}.* keys the form shows under each row.
        $validator->after(function ($validator) {
            $rows = $this->input('items');
            if (! is_array($rows) || $validator->errors()->has('items')) {
                return;
            }
            $items = \App\Models\Item::with('unit.subunits')->whereIn('id', collect($rows)->pluck('item_id')->filter()->all())->get()->keyBy('id');
            $seen = [];
            foreach ($rows as $n => $row) {
                $itemId = $row['item_id'] ?? null;
                $item = $itemId ? $items->get($itemId) : null;
                if (! $item) {
                    continue;
                }
                if (isset($seen[$itemId])) {
                    $validator->errors()->add("items.{$n}.item_id", __(':item is on the list twice. Keep one row and change its quantity.', ['item' => $item->name]));
                }
                $seen[$itemId] = true;

                $allowedUnits = collect([$item->unit_id])->merge($item->unit ? $item->unit->subunits->pluck('id') : [])->filter()->map(fn ($u) => (int) $u)->all();
                $variations = $row['selected']['variations'] ?? [];
                if (! empty($row['unit_id']) && $allowedUnits && ! in_array((int) $row['unit_id'], $allowedUnits, true)) {
                    $validator->errors()->add("items.{$n}.quantity", __('The unit chosen for :item does not belong to it.', ['item' => $item->name]));
                }
                if (filter_var($row['has_variants'] ?? false, FILTER_VALIDATE_BOOLEAN) && empty($variations)) {
                    $validator->errors()->add("items.{$n}.variation_id", __('Choose at least one variation of :item.', ['item' => $item->name]));
                }
                foreach ((array) $variations as $v) {
                    $quantity = $v['quantity'] ?? null;
                    if (! is_numeric($quantity) || (float) $quantity <= 0) {
                        $validator->errors()->add("items.{$n}.quantity", __('Every variation of :item needs a quantity greater than 0.', ['item' => $item->name]));
                        break;
                    }
                    if (isset($v['weight']) && $v['weight'] !== '' && (! is_numeric($v['weight']) || (float) $v['weight'] < 0)) {
                        $validator->errors()->add("items.{$n}.weight", __('The weight of :item must be 0 or more.', ['item' => $item->name]));
                        break;
                    }
                    if (! empty($v['unit_id']) && $allowedUnits && ! in_array((int) $v['unit_id'], $allowedUnits, true)) {
                        $validator->errors()->add("items.{$n}.quantity", __('The unit chosen for :item does not belong to it.', ['item' => $item->name]));
                        break;
                    }
                }
            }
        });

        $validator->setImplicitAttributesFormatter(function ($attribute) {
            $attributes = explode('.', $attribute);
            if ($attributes[0] == 'items') {
                if ($attributes[2]) {
                    return __('Item') . ' ' . ((int) $attributes[1] + 1) . ' ' . __($attributes[2]) . ' ' . (isset($attributes[3]) ? ((int) $attributes[3] + 1) : '');
                }

                return __('Item') . ' ' . ((int) $attributes[1] + 1);
            } elseif ($attributes[0] == 'attachments') {
                return 'attachments ' . ((int) $attributes[1] + 1);
            }

            return $attributes;
        });
    }

    protected function passedValidation()
    {
        if (! $this->input('draft') && ! get_settings('over_selling')) {
            $error = (new CheckOverSelling())->check($this->input('items'), $this->input('from_warehouse_id'));
            if ($error) {
                throw ValidationException::withMessages($error);
            }
        }
    }
}
