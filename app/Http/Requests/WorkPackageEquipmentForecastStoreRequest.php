<?php

namespace App\Http\Requests;

use App\Models\WorkPackage;
use Illuminate\Foundation\Http\FormRequest;

class WorkPackageEquipmentForecastStoreRequest extends FormRequest
{
    public function authorize()
    {
        $workPackage = $this->route('work_package');

        return $workPackage instanceof WorkPackage && $this->user()?->can('manageForecast', $workPackage);
    }

    public function rules()
    {
        return [
            'item_id' => 'required|exists:items,id',
            'required_quantity' => 'required|numeric|min:0.01',
            'required_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ];
    }
}
