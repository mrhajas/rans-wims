<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class MaterialRequestUpdateRequest extends FormRequest
{
    public function authorize()
    {
        if (! auth()->check()) {
            return false;
        }

        $materialRequest = $this->route('material_request');
        if (! $materialRequest) {
            return false;
        }

        return auth()->user()->can('update', $materialRequest);
    }

    public function rules()
    {
        return [
            'warehouse_id' => 'required|exists:warehouses,id',
            'date_requested' => 'required|date',
            'reason' => 'required|string',
            'comments' => 'nullable|string',
            'attachments' => 'nullable',
            'attachments.*' => 'mimes:pdf,doc,docx',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.priority' => 'required|in:Low,Normal,High,Emergency',
            'items.*.remarks' => 'nullable|string',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if (! auth()->check()) {
                return;
            }

            if (! auth()->user()->hasRole(User::SITE_ENGINEER_ROLES)) {
                return;
            }

            $warehouseId = (int) $this->input('warehouse_id');
            if (! auth()->user()->manages($warehouseId)) {
                $validator->errors()->add('warehouse_id', __('You are not assigned to this warehouse.'));
            }
        });
    }
}
