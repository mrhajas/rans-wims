<?php

namespace App\Http\Requests;

use App\Models\LabourListItem;
use Illuminate\Foundation\Http\FormRequest;

class LabourListUpdateRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check() && auth()->user()->can('update', $this->route('labour'));
    }

    public function rules()
    {
        return [
            'warehouse_id' => 'required|exists:warehouses,id',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'description' => 'nullable|string',
            'attachments' => 'nullable',
            'attachments.*' => 'mimes:pdf,doc,docx,jpg,jpeg,png',
            'items' => 'required|array|min:1',
            'items.*.payee_type' => 'required|in:' . LabourListItem::PAYEE_TYPE_STAFF . ',' . LabourListItem::PAYEE_TYPE_EXTERNAL,
            'items.*.staff_id' => 'required_if:items.*.payee_type,' . LabourListItem::PAYEE_TYPE_STAFF . '|nullable|exists:staffs,id',
            'items.*.contact_id' => 'required_if:items.*.payee_type,' . LabourListItem::PAYEE_TYPE_EXTERNAL . '|nullable|exists:contacts,id',
            'items.*.workforce_agreement_id' => 'nullable|exists:workforce_agreements,id',
            'items.*.priority' => 'nullable|string|in:' . implode(',', LabourListItem::priorities()),
            'items.*.work_description' => 'nullable|string',
            'items.*.days' => 'nullable|numeric|min:0',
            'items.*.hours' => 'nullable|numeric|min:0',
            'items.*.rate_type' => 'required|in:' . implode(',', LabourListItem::rateTypes()),
            'items.*.rate' => 'required|numeric|min:0.01',
        ];
    }

    public function withValidator($validator)
    {
        // Same warehouse-scope check LabourListStoreRequest already enforces
        // — without it, a Site Engineer editing their own Draft/Returned
        // list (LabourPolicy::update() only checks ownership + workflow
        // step, not warehouse assignment) could resubmit it against a
        // project they aren't assigned to, since warehouse_id itself is
        // only validated as `exists:warehouses,id` above.
        $validator->after(function ($validator) {
            if (! auth()->check()) {
                return;
            }

            $user = auth()->user();
            $warehouseId = (int) $this->input('warehouse_id');

            if ($user->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers'])) {
                return;
            }

            if (! $user->manages($warehouseId)) {
                $validator->errors()->add('warehouse_id', __('You are not assigned to this warehouse.'));
            }
        });

        $validator->after(function ($validator) {
            \App\Support\GuardsWorkforceBalance::checkItems($this->input('items', []), $validator, auth()->user());
        });
    }
}
