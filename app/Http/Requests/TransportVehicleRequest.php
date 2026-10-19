<?php

namespace App\Http\Requests;

use App\Models\Asset;
use App\Models\AssetCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the Vehicle Register subset of Asset's much larger schema —
 * Transport Routes only exposes the fields relevant to a trip-making
 * vehicle, not Asset's full depreciation/insurance/lease/disposal surface.
 */
class TransportVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorization is handled explicitly in TransportVehicleController
    }

    public function rules(): array
    {
        $assetId = $this->route('asset')?->id;

        // Restricted to the 'Vehicles' category tree — not just any
        // asset_categories row. Without this, registering a vehicle under
        // a non-Vehicles category silently created an asset that then never
        // appeared in the Vehicle Register (Asset::scopeVehicles() filters
        // it out), an easy way to lose a just-created record entirely.
        $vehiclesId = AssetCategory::where('name', Asset::TRANSPORT_VEHICLE_CATEGORY_NAME)->whereNull('parent_id')->value('id');
        $vehicleCategoryIds = AssetCategory::where(fn ($q) => $q->where('id', $vehiclesId)->orWhere('parent_id', $vehiclesId))->pluck('id');

        return [
            'name' => 'required|string|max:190',
            'asset_category_id' => ['required', Rule::in($vehicleCategoryIds)],
            'registration_number' => [
                'nullable', 'string', 'max:190',
                Rule::unique('assets', 'registration_number')->whereNotNull('registration_number')->ignore($assetId),
            ],
            // Make/Model are open-ended catalogs (the form's autocomplete lets
            // a user add a new one on the fly), so they stay free strings —
            // only Fuel Type and Year are closed sets validated with `in:`.
            'manufacturer' => 'nullable|string|max:190', // Make
            'model' => 'nullable|string|max:190',
            'year' => 'nullable|integer|min:1980|max:2050',
            'color' => 'nullable|string|max:50',
            'vin_chassis_number' => 'nullable|string|max:190',
            'engine_number' => 'nullable|string|max:190',
            'ownership_type' => 'required|in:' . implode(',', Asset::OWNERSHIP_TYPES),
            'owner_name' => 'nullable|string|max:190',
            'capacity' => 'nullable|numeric|min:0',
            'fuel_type' => 'nullable|in:' . implode(',', Asset::FUEL_TYPES),
            'status' => 'required|in:' . implode(',', Asset::STATUSES),
            'operating_personnel_type' => 'nullable|in:' . implode(',', Asset::OPERATING_PERSONNEL_TYPES),
            'operating_personnel_staff_id' => 'nullable|exists:staffs,id',
            'operating_personnel_contact_id' => [
                'nullable', Rule::exists('contacts', 'id')->where('category', 'Drivers'),
            ],
            'notes' => 'nullable|string',
        ];
    }
}
