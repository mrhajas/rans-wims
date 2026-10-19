<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation()
    {
        if ($this->has('category')) {
            $this->merge(['category' => \App\Models\Contact::normalizeCategory($this->input('category'))]);
        }
    }

    public function rules()
    {
        return [
            'details'  => 'nullable',
            'email'    => 'nullable|email',
            'category' => 'nullable|string|max:50',
            'phone'    => 'nullable|required_without:email',
            'location' => 'nullable|string|max:150',
            'name'     => 'required|max:50|unique:contacts,name,' . optional($this->route('contact'))->id,
        ];
    }
}
