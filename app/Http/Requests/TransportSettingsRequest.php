<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransportSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transport_default_price' => 'required|numeric|min:0',
            'transport_loading_daily_rate' => 'required|numeric|min:0',
        ];
    }
}
