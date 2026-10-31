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
        // a Quick request needs the Quick permission, a normal one the normal Create step
        return $this->boolean('quick')
            ? auth()->user()->can(\App\Models\MaterialRequestWorkflowStep::PERMISSION_QUICK_CREATE)
            : \App\Models\MaterialRequestWorkflowStep::isAllowed(auth()->user(), \App\Models\MaterialRequestWorkflowStep::STEP_CREATE);
    }

    public function rules()
    {
        return [
            'quick' => 'sometimes|boolean',
            'warehouse_id' => 'required|exists:warehouses,id',
            'date_requested' => 'required|date',
            'reason' => $this->boolean('quick') ? 'nullable|string' : 'required|string',
            'comments' => 'nullable|string',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:pdf,doc,docx|max:5120',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.priority' => ($this->boolean('quick') ? 'nullable' : 'required') . '|in:Low,Normal,High,Emergency',
            'items.*.remarks' => 'nullable|string',
        ];
    }

    public function messages()
    {
        return [
            'attachments.max' => __('A request holds up to :max files.', ['max' => 5]),
            'attachments.*.max' => __('Each file can be up to 5 MB.'),
            'attachments.*.mimes' => __('Only PDF, DOC or DOCX files can be attached.'),
            'attachments.*.uploaded' => __('A file could not be uploaded. Keep each file under 5 MB.'),
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
