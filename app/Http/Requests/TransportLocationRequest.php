<?php

namespace App\Http\Requests;

use App\Models\TransportLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransportLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $locationId = $this->route('transport_location')?->id;

        return [
            'name' => [
                'required', 'string', 'max:190',
                // withoutTrashed() so a soft-deleted location's name never
                // permanently blocks recreating it — the DB unique index
                // itself doesn't know about deleted_at either (see the
                // companion migration), so this alone isn't quite enough
                // without that fix too, but it's what stops a legitimate
                // re-add from failing validation with a misleading "already
                // taken" error pointing at an invisible row.
                Rule::unique('transport_locations', 'name')->where('location_type', $this->input('location_type'))->withoutTrashed()->ignore($locationId),
            ],
            'location_type' => 'required|in:' . implode(',', TransportLocation::LOCATION_TYPES),
            'address' => 'nullable|string|max:190',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ];
    }
}
