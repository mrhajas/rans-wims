<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\MaterialReallocation;
use App\Http\Requests\Concerns\ValidatesReallocationStockAvailability;

class MaterialReallocationUpdateRequest extends FormRequest
{
    use ValidatesReallocationStockAvailability;

    public function authorize()
    {
        return auth()->check();
    }

    public function rules()
    {
        return [
            'source_warehouse_id' => 'required|exists:warehouses,id',
            'date_requested' => 'required|date',
            'reason' => 'required|string|in:' . implode(',', MaterialReallocation::reasons()),
            'reason_details' => 'required_if:reason,' . MaterialReallocation::REASON_OTHER . '|nullable|string',
            'remarks' => 'nullable|string',
            'attachments' => 'nullable',
            'attachments.*' => 'mimes:pdf,doc,docx,jpg,jpeg,png',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.requested_quantity' => 'required|numeric|gt:0',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.remarks' => 'nullable|string',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $this->validateStockAvailability($validator);
        });
    }
}
