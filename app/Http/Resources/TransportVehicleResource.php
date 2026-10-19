<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A thin wrapper over Asset exposing only the Vehicle-Register-relevant
 * fields (the underlying Asset carries a much larger depreciation/
 * insurance/lease/disposal schema not relevant to Transport Routes),
 * plus computed trip stats for the Vehicle details page.
 */
class TransportVehicleResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'asset_number' => $this->asset_number,
            'name' => $this->name,
            'asset_category_id' => $this->asset_category_id,
            'registration_number' => $this->registration_number,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'year' => $this->year,
            'color' => $this->color,
            'vin_chassis_number' => $this->vin_chassis_number,
            'engine_number' => $this->engine_number,
            'ownership_type' => $this->ownership_type,
            'owner_name' => $this->owner_name,
            'capacity' => $this->capacity,
            'fuel_type' => $this->fuel_type,
            'status' => $this->status,
            'operating_personnel_type' => $this->operating_personnel_type,
            'operating_personnel_staff_id' => $this->operating_personnel_staff_id,
            'operating_personnel_contact_id' => $this->operating_personnel_contact_id,
            'notes' => $this->notes,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,

            'opening_balance' => $this->opening_balance !== null ? (float) $this->opening_balance : null,
            // Formatted as a plain 'Y-m-d' (not the default ISO datetime
            // Carbon's 'date' cast would serialize) — it's a calendar date,
            // not a moment in time, so JSON-encoding it as UTC would shift
            // it across a day boundary for any timezone behind UTC, and a
            // native <input type="date"> only accepts this exact format.
            'opening_balance_date' => optional($this->opening_balance_date)->format('Y-m-d'),
            'opening_balance_notes' => $this->opening_balance_notes,
            'opening_balance_set_at' => $this->opening_balance_set_at,

            'category' => $this->whenLoaded('category'),
            'operating_personnel_contact' => $this->whenLoaded('operatingPersonnelContact'),
            'operating_personnel_staff' => $this->whenLoaded('operatingPersonnel'),
            'creator' => $this->whenLoaded('creator'),
            'opening_balance_set_by' => $this->whenLoaded('openingBalanceSetBy'),
            'attachments' => $this->whenLoaded('attachments'),

            // Vehicle details page stats — only present when explicitly
            // computed and attached by the controller (show()), never
            // recomputed per-row on the index listing.
            'trip_stats' => $this->when(isset($this->resource->trip_stats), fn () => $this->resource->trip_stats),
            'recent_trips' => $this->when(
                $this->relationLoaded('transportRecords'),
                fn () => TransportRecordResource::collection($this->transportRecords)
            ),
            'activity' => $this->when(isset($this->resource->activity), fn () => $this->resource->activity),
            'payment_vouchers' => $this->when(isset($this->resource->payment_vouchers), fn () => $this->resource->payment_vouchers),

            'can_edit' => $user ? $user->can('update-transport-vehicles') : false,
            'can_delete' => $user ? $user->can('delete-transport-vehicles') : false,
        ];
    }
}
