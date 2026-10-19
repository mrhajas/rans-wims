<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarehouseRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'active'    => 'nullable',
            'address'   => 'nullable',
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'phone'   => 'nullable',
            'email'   => 'nullable|email',
            'name'    => 'required|max:50',
            'logo'    => 'nullable|image|max:500',
            'code'    => 'required|max:20|unique:warehouses,code,' . optional($this->route('warehouse'))->id,
            'status'  => 'required|string|in:Main Warehouse,Directors,Private,Government,General,Rans Warehouse',

            'start_date'               => 'nullable|date',
            'expected_end_date'        => 'nullable|date|after_or_equal:start_date',
            'for_who'                  => 'nullable|string|max:255',
            'registration_number'      => 'nullable|string|max:255',
            'external_body_personnel'  => 'nullable|string|max:255',
            'site_budget'              => 'nullable|numeric|min:0',
            'other_info'               => 'nullable|string',
            'project_manager'          => 'nullable|string|max:190',
            'project_category'         => ['nullable', 'string', Rule::in(['Residential', 'Commercial', 'Institutional', 'Renovation', 'Infrastructure', 'Other'])],
            'floors_units'             => 'nullable|string|max:100',
            'priority_level'           => ['nullable', 'string', Rule::in(['Low', 'Medium', 'High'])],
            'estimated_workforce'      => 'nullable|integer|min:0',
            'stage'                    => 'nullable|string|max:255',
            'completion_percentage'    => 'nullable|integer|min:0|max:100',

            'drawings'         => 'nullable|array|max:20',
            'drawings.*'       => 'file|max:10240',
            'photos'           => 'nullable|array|max:20',
            'photos.*'         => 'image|max:10240',
            'boq_attachment'   => 'nullable|array|max:20',
            'boq_attachment.*' => 'file|max:10240',
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = $this->validator->validated();
        if ($this->has('logo') && $this->logo) {
            $path = $this->logo->store('/images', 'logos');
            $data['logo'] = Storage::disk('logos')->url($path);
        } else {
            unset($data['logo']);
        }

        unset($data['drawings'], $data['photos'], $data['boq_attachment']);

        return $data;
    }
}
