<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaterialReallocationDispatchRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check();
    }

    public function rules()
    {
        return [
            'driver_contact_id' => [
                'required',
                Rule::exists('contacts', 'id')->where('category', 'Drivers'),
            ],
            'vehicle' => 'required|string|max:64',
            'dispatch_date_time' => 'required|date',
            'attachments' => 'nullable',
            'attachments.*' => 'mimes:pdf,doc,docx,jpg,jpeg,png',
        ];
    }
}
