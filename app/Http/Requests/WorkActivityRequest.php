<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkActivityRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => 'required|max:190|unique:work_activities,name,' . optional($this->route('activity'))->id,
        ];
    }
}
