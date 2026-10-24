<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SuperAdjustmentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'warehouse_id' => 'required|exists:warehouses,id',
            'reason' => 'required|string|max:255',
            'details' => 'nullable|string',
            'remarks' => 'nullable|string',

            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:20480|mimes:' . attachment_exts('jpg,png,pdf,docx,xlsx,zip'),

            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|distinct|exists:items,id',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.physical_quantity' => 'required|numeric|min:0',
            'items.*.auto_cleared' => 'nullable|boolean',
        ];
    }

    public function withValidator($validator)
    {
        $validator->setImplicitAttributesFormatter(function ($attribute) {
            $attributes = explode('.', $attribute);
            if ($attributes[0] === 'items') {
                return __('Item') . ' ' . ((int) $attributes[1] + 1) . ' ' . __($attributes[2] ?? '');
            }

            return $attribute;
        });
    }
}
