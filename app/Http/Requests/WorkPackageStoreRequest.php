<?php

namespace App\Http\Requests;

use App\Models\WorkPackage;
use Illuminate\Foundation\Http\FormRequest;

class WorkPackageStoreRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()?->can('create', WorkPackage::class) ?? false;
    }

    public function rules()
    {
        return [
            'warehouse_id' => 'required|exists:warehouses,id',
            'work_packages' => 'required|array|min:1',
            'work_packages.*.title' => 'required|string|max:190',
            'work_packages.*.category' => 'nullable|string|max:100',
            'work_packages.*.building' => 'nullable|string|max:100',
            'work_packages.*.floor' => 'nullable|string|max:100',
            'work_packages.*.zone' => 'nullable|string|max:100',
            'work_packages.*.area' => 'nullable|string|max:100',
            'work_packages.*.engineer_id' => 'nullable|exists:users,id',
            'work_packages.*.foreman_id' => 'nullable|exists:contacts,id',
            'work_packages.*.subcontractor_id' => 'nullable|exists:contacts,id',
            'work_packages.*.priority' => 'required|string|in:' . implode(',', WorkPackage::priorities()),
            'work_packages.*.planned_start' => 'required|date',
            'work_packages.*.planned_finish' => 'required|date|after_or_equal:work_packages.*.planned_start',
            'work_packages.*.planned_duration' => 'nullable|integer|min:0',
            'work_packages.*.expected_progress_weight' => 'nullable|numeric|min:0.01',
        ];
    }
}
