<?php

namespace App\Http\Requests;

use App\Models\TransportRecord;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransportRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorization is handled explicitly in TransportRecordController
    }

    /**
     * "Site Campus" route: a movement inside one big site. The destination is the same place as the departure, so the
     * form does not ask for one - it is filled here and the normal destination checks below then pass unchanged.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->boolean('is_campus')) {
            $this->merge(['is_campus' => false]);

            return;
        }

        $type = $this->input('departure_type');
        $this->merge([
            'is_campus' => true,
            'destination_type' => $type,
            'warehouse_id' => $this->input('departure_warehouse_id'),
            'destination_transport_location_id' => null,
            'transport_location_id' => $this->input('departure_transport_location_id'),
            'destination_contact_id' => null,
            'destination_warehouse_ids' => [],
        ]);
    }

    public function rules(): array
    {
        return [
            'trip_date' => 'required|date',
            'trip_time' => 'nullable|date_format:H:i',
            // Only vehicles registered under Transport Vehicles can run trips (assets registered in the Asset Register are
            // not in this list). A trip saved earlier with another vehicle can still be edited without changing it.
            'asset_id' => ['required', function ($attribute, $value, $fail) {
                $current = $this->route('transport_record');
                $unchanged = $current instanceof TransportRecord && (int) $current->asset_id === (int) $value;

                if (! $unchanged && ! \App\Models\Asset::vehicles()->whereKey($value)->exists()) {
                    $fail(__('Choose a vehicle registered under Transport Vehicles.'));
                }
            }],
            'driver_contact_id' => [
                'nullable', Rule::exists('contacts', 'id')->where('category', 'Drivers'),
            ],

            // Departure mirrors destination. Required when logging a new trip; older trips saved before
            // departure existed can still be edited without one.
            'departure_type' => [$this->isMethod('POST') ? 'required' : 'nullable', 'in:' . implode(',', TransportRecord::DESTINATION_TYPES)],
            'departure_warehouse_id' => 'nullable|exists:warehouses,id',
            'departure_warehouse_ids' => 'nullable|array|max:30',
            'departure_warehouse_ids.*' => 'integer|exists:warehouses,id',
            'departure_contact_id' => 'nullable|exists:contacts,id',
            'departure_transport_location_id' => 'nullable|exists:transport_locations,id',

            'destination_type' => 'required|in:' . implode(',', TransportRecord::DESTINATION_TYPES),
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'destination_warehouse_ids' => 'nullable|array|max:30',
            'destination_warehouse_ids.*' => 'integer|exists:warehouses,id',
            'destination_contact_id' => 'nullable|exists:contacts,id',
            'transport_location_id' => 'nullable|exists:transport_locations,id',

            'departure_location' => 'nullable|string|max:190',
            'destination_location' => 'nullable|string|max:190',
            'delivery_note_ref' => 'nullable|string|max:190',
            'purpose' => 'nullable|string',

            // Price of ONE trip; the record's total is this x trips_count (worked out in the controller).
            'amount' => 'required|numeric|min:0',
            'trips_count' => 'nullable|integer|min:1|max:' . TransportRecord::MAX_TRIPS,
            'is_campus' => 'boolean',
            'amount_change_reason' => 'nullable|string|max:1000',

            'notes' => 'nullable|string',

            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:20480|mimes:' . attachment_exts('jpg,png,pdf,docx,xlsx,zip'),
        ];
    }

    /**
     * Enforces the destination tagged-union: exactly one of warehouse_id /
     * destination_contact_id / transport_location_id must be set, and it
     * must match destination_type — and, for Project/Warehouse, that the
     * chosen warehouse is actually a project vs. a main warehouse
     * respectively (WIMS has no separate Projects table — see
     * Warehouse::scopeMainWarehouses()).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('is_campus') && ! in_array($this->input('departure_type'), TransportRecord::CAMPUS_TYPES, true)) {
                $validator->errors()->add('departure_type', __('A site campus movement must start from a Project or a Site.'));
            }

            $this->validateEndpoint($validator, 'destination_type', 'warehouse_id', 'destination_contact_id', 'transport_location_id');

            if ($this->filled('departure_type')) {
                $this->validateEndpoint($validator, 'departure_type', 'departure_warehouse_id', 'departure_contact_id', 'departure_transport_location_id');
            }

            // Whether amount_change_reason is required depends on comparing
            // the submitted amount against the true default (from Transport
            // Settings on create, or the record's own default_amount on
            // update) — the controller knows that, a client-sent flag can't
            // be trusted for an audit-critical check, so that comparison and
            // the resulting "reason required" enforcement happens in
            // TransportRecordController::persist(), not here.
        });
    }

    /** Same tagged-union rule for both ends of the trip: the chosen type decides which one id field must be filled. */
    private function validateEndpoint($validator, string $typeField, string $warehouseField, string $contactField, string $locationField): void
    {
        $type = $this->input($typeField);

        if ($type === TransportRecord::DESTINATION_MIXED) {
            $ids = array_values(array_unique(array_map('intval', (array) $this->input($warehouseField === 'warehouse_id' ? 'destination_warehouse_ids' : 'departure_warehouse_ids', []))));
            if (count($ids) < 2) {
                $validator->errors()->add($warehouseField === 'warehouse_id' ? 'destination_warehouse_ids' : 'departure_warehouse_ids', __('Select at least two Main Warehouses.'));

                return;
            }
            $main = Warehouse::whereIn('id', $ids)->mainWarehouses()->pluck('id')->all();
            if (count($main) !== count($ids)) {
                $validator->errors()->add($warehouseField === 'warehouse_id' ? 'destination_warehouse_ids' : 'departure_warehouse_ids', __('Only Main Warehouses can be selected here.'));
            }

            return;
        }

        if (in_array($type, TransportRecord::WAREHOUSE_DESTINATION_TYPES, true)) {
            if (! $this->filled($warehouseField)) {
                $validator->errors()->add($warehouseField, __('A :type is required for this destination type.', ['type' => $type]));

                return;
            }
            $warehouse = Warehouse::find($this->input($warehouseField));
            if ($warehouse) {
                $isMainWarehouse = Warehouse::whereKey($warehouse->id)->mainWarehouses()->exists();
                if ($type === TransportRecord::DESTINATION_PROJECT && $isMainWarehouse) {
                    $validator->errors()->add($warehouseField, __('The selected warehouse is a main warehouse, not a project.'));
                }
                if ($type === TransportRecord::DESTINATION_WAREHOUSE && ! $isMainWarehouse) {
                    $validator->errors()->add($warehouseField, __('The selected warehouse is a project, not a main warehouse.'));
                }
            }
        } elseif (in_array($type, TransportRecord::CONTACT_DESTINATION_TYPES, true)) {
            if (! $this->filled($contactField)) {
                $validator->errors()->add($contactField, __('A :type contact is required for this destination type.', ['type' => $type]));
            } else {
                // Without this only the Vue dropdown's own filtering stopped a Supplier from pointing at a
                // Driver (or any other) contact; a direct request could set the field to any contact id.
                $expectedCategory = $type . 's';
                $matches = \App\Models\Contact::whereKey($this->input($contactField))->where('category', $expectedCategory)->exists();
                if (! $matches) {
                    $validator->errors()->add($contactField, __('The selected contact is not a :type.', ['type' => $type]));
                }
            }
        } elseif (in_array($type, TransportRecord::LOCATION_DESTINATION_TYPES, true)) {
            if (! $this->filled($locationField)) {
                $validator->errors()->add($locationField, __('A location is required for this destination type.'));
            }
        }
    }
}
