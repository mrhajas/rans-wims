<?php

namespace App\Http\Requests;

use App\Models\MaterialRequest;
use Illuminate\Foundation\Http\FormRequest;

class MaterialRequestStoreRequest extends FormRequest
{
    public function authorize()
    {
        if (! auth()->check()) {
            return false;
        }

        // Delegates to the Policy (workflow-step-driven, see
        // MaterialRequestPolicy::create()) rather than its own hardcoded
        // role list — this used to be a completely separate, out-of-sync
        // copy of that check that ignored Administration → Material
        // Request Workflow entirely.
        return auth()->user()->can('create', MaterialRequest::class);
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

            $warehouseId = (int) $this->input('warehouse_id');
            $user = auth()->user();

            if ($user->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers'])) {
                return;
            }

            if (! $user->manages($warehouseId)) {
                $validator->errors()->add('warehouse_id', __('You are not assigned to this warehouse.'));
            }
        });
    }
}
