<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\MaterialReallocation;
use App\Models\User;
use App\Http\Requests\Concerns\ValidatesReallocationStockAvailability;

class MaterialReallocationStoreRequest extends FormRequest
{
    use ValidatesReallocationStockAvailability;

    public function authorize()
    {
        if (! auth()->check()) {
            return false;
        }

        $user = auth()->user();

        return $user->hasRole([...User::SITE_ENGINEER_ROLES, 'Super Admin', 'Admin'])
            || $user->can('create-material-reallocations');
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
            if (auth()->check()) {
                $user = auth()->user();
                $warehouseId = (int) $this->input('source_warehouse_id');

                if (! $user->hasRole(['Super Admin', 'Admin']) && ! $user->manages($warehouseId)) {
                    $validator->errors()->add('source_warehouse_id', __('You are not assigned to this warehouse.'));
                }
            }

            $this->validateStockAvailability($validator);
        });
    }
}
