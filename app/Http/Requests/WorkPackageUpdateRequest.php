<?php

namespace App\Http\Requests;

use App\Models\WorkPackage;
use Illuminate\Foundation\Http\FormRequest;

class WorkPackageUpdateRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()?->can('update', $this->route('work_package')) ?? false;
    }

    public function rules()
    {
        return [
            'title' => 'required|string|max:190',
            'category' => 'nullable|string|max:100',
            'warehouse_id' => 'required|exists:warehouses,id',
            'building' => 'nullable|string|max:100',
            'floor' => 'nullable|string|max:100',
            'zone' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'engineer_id' => 'nullable|exists:users,id',
            'foreman_id' => 'nullable|exists:contacts,id',
            'subcontractor_id' => 'nullable|exists:contacts,id',
            'priority' => 'required|string|in:' . implode(',', WorkPackage::priorities()),
            'planned_start' => 'required|date',
            'planned_finish' => 'required|date|after_or_equal:planned_start',
            'planned_duration' => 'nullable|integer|min:0',
            'dependencies' => 'nullable|array',
            'dependencies.*' => 'integer|exists:work_packages,id',
            'expected_progress_weight' => 'nullable|numeric|min:0.01',
            'actual_progress_percent' => 'nullable|numeric|min:0|max:100',
            'status' => 'nullable|string|in:' . implode(',', WorkPackage::statuses()),
        ];
    }
}
