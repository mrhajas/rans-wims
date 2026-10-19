<?php

namespace App\Http\Requests;

use App\Models\WorkPackage;
use Illuminate\Foundation\Http\FormRequest;

class WorkPackageLabourForecastStoreRequest extends FormRequest
{
    public function authorize()
    {
        $workPackage = $this->route('work_package');

        return $workPackage instanceof WorkPackage && $this->user()?->can('manageForecast', $workPackage);
    }

    public function rules()
    {
        return [
            'contact_id' => 'nullable|exists:contacts,id',
            'trade' => 'nullable|string|max:100',
            'quantity' => 'required|integer|min:1',
            'duration_days' => 'nullable|integer|min:1',
            'required_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ];
    }
}
