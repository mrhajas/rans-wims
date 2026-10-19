<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaterialReallocationRemarksRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check();
    }

    public function rules()
    {
        return [
            'remarks' => $this->routeIs('material-reallocations.cancel') ? 'nullable|string' : 'required|string',
        ];
    }
}
