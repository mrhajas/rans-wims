<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaterialReallocationAllocateRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check();
    }

    public function rules()
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|exists:material_reallocation_items,id',
            'items.*.destinations' => 'required|array|min:1',
            'items.*.destinations.*.warehouse_id' => 'required|exists:warehouses,id',
            'items.*.destinations.*.quantity' => 'required|numeric|gt:0',
        ];
    }
}
