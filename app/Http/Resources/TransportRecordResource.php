<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransportRecordResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();
        $isOwner = $user && (int) $this->created_by_user_id === (int) $user->id;

        return [
            'id' => $this->id,
            'trip_number' => $this->trip_number,
            // Explicit Y-m-d, not a raw Carbon instance — the default JSON
            // serialization converts to UTC first, and this app's timezone
            // (Africa/Dar_es_Salaam, UTC+3) turns local midnight into the
            // previous day's 21:00 UTC, silently shifting the date back by
            // one every time the edit form round-trips this value. Every
            // other date-cast field in the app already sidesteps this the
            // same way (MaterialRequestResource, WarehouseResource, etc.).
            'trip_date' => $this->trip_date?->format('Y-m-d'),
            // MySQL's TIME column (and this attribute has no cast) returns
            // "H:i:s" — the edit form's <input type="time"> holds that
            // verbatim and never fires an input event just from being
            // populated, so resubmitting a trip with a time set unchanged
            // sent "14:30:00" against `date_format:H:i` and hard-failed
            // validation on a field the user never touched.
            'trip_time' => $this->trip_time ? substr($this->trip_time, 0, 5) : null,
            'asset_id' => $this->asset_id,
            'driver_contact_id' => $this->driver_contact_id,

            'departure_type' => $this->departure_type,
            'departure_warehouse_id' => $this->departure_warehouse_id,
            'departure_warehouse_ids' => $this->departure_warehouse_ids ?? [],
            'departure_contact_id' => $this->departure_contact_id,
            'departure_transport_location_id' => $this->departure_transport_location_id,
            'departure_label' => $this->departure_label,

            'destination_type' => $this->destination_type,
            'warehouse_id' => $this->warehouse_id,
            'destination_warehouse_ids' => $this->destination_warehouse_ids ?? [],
            'destination_contact_id' => $this->destination_contact_id,
            'transport_location_id' => $this->transport_location_id,
            'destination_label' => $this->destination_label,

            'departure_location' => $this->departure_location,
            'destination_location' => $this->destination_location,
            'delivery_note_ref' => $this->delivery_note_ref,
            'purpose' => $this->purpose,

            'default_amount' => (float) $this->default_amount,
            'trips_count' => (int) ($this->trips_count ?: 1),
            'unit_amount' => $this->unit_price,
            'is_campus' => (bool) $this->is_campus,
            'amount' => (float) $this->amount,
            'amount_overridden' => (bool) $this->amount_overridden,
            'amount_change_reason' => $this->amount_change_reason,
            'amount_changed_at' => $this->amount_changed_at,

            'is_verified' => $this->verified_at !== null,
            'verified_at' => $this->verified_at,

            'payment_status' => $this->payment_status,
            'paid_amount' => (float) $this->paid_amount,
            'outstanding_amount' => $this->outstanding_amount,
            'payment_date' => $this->payment_date?->format('Y-m-d'),
            'payment_reference' => $this->payment_reference,
            'payment_method' => $this->payment_method,
            'payment_notes' => $this->payment_notes,
            'payment_voucher_id' => $this->payment_voucher_id,
            // A trip can only ever reach Paid without a voucher via
            // markPaid() directly — that action itself requires
            // payment_voucher_id === null (see can_mark_paid below) — so
            // this is fully derived, never a stored flag, and stays correct
            // even for trips paid before this indicator existed.
            'paid_without_voucher' => $this->payment_status === \App\Models\TransportRecord::STATUS_PAID && $this->payment_voucher_id === null,

            'notes' => $this->notes,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,

            // The Transfer this trip was generated from (link opens it); only shown to people who may view transfers.
            'transfer_id' => $this->transfer_id,
            'transfer' => $this->when($this->transfer_id && $this->relationLoaded('transfer') && $this->transfer && $user && $user->can('read-transfers'), fn () => [
                'id' => $this->transfer->id,
                'reference' => $this->transfer->reference,
                'url' => route('transfers.show', $this->transfer->id),
                'deleted' => $this->transfer->trashed(),
            ]),

            'asset' => $this->whenLoaded('asset'),
            'driver' => $this->whenLoaded('driver'),
            'warehouse' => $this->whenLoaded('warehouse'),
            'destination_contact' => $this->whenLoaded('destinationContact'),
            'transport_location' => $this->whenLoaded('transportLocation'),
            'payment_voucher' => $this->whenLoaded('paymentVoucher'),
            'creator' => $this->whenLoaded('creator'),
            'paid_by' => $this->whenLoaded('paidBy'),
            'amount_changed_by' => $this->whenLoaded('amountChangedBy'),
            'verified_by' => $this->whenLoaded('verifiedBy'),
            'attachments' => $this->whenLoaded('attachments'),
            'activity' => $this->when(isset($this->resource->activity), fn () => $this->resource->activity),

            // Unpaid trips edit normally; a Partially Paid/Paid trip with no
            // linked voucher can still be corrected but only through the
            // password-confirmed flow (requires_password_to_edit below) —
            // Pending Payment (always voucher-linked) and Cancelled stay
            // fully blocked either way, matching what update()/updatePaid()
            // actually enforce server-side.
            'can_edit' => $user ? (
                ($isOwner || $user->can('update-transport-records'))
                && $this->payment_voucher_id === null
                && $this->payment_status !== \App\Models\TransportRecord::STATUS_CANCELLED
            ) : false,
            'requires_password_to_edit' => in_array($this->payment_status, [
                \App\Models\TransportRecord::STATUS_PARTIALLY_PAID, \App\Models\TransportRecord::STATUS_PAID,
            ], true),
            'can_delete' => $user ? ($isOwner || $user->can('delete-transport-records')) : false,
            'can_override_amount' => $user ? $user->can('override-transport-amount') : false,
            'can_mark_paid' => $user ? (
                $user->can('mark-paid-transport-records')
                && $this->payment_voucher_id === null
                && $this->verified_at !== null
                && ! in_array($this->payment_status, [\App\Models\TransportRecord::STATUS_PAID, \App\Models\TransportRecord::STATUS_CANCELLED], true)
            ) : false,
            // Mirrors the controller's own enforcement (createPaymentVoucher()
            // 422s an unverified trip) — the Policy alone doesn't check
            // verified_at, so without this the button would show for an
            // unverified trip and fail on click.
            'can_create_payment_voucher' => $user ? ($user->can('createPaymentVoucher', $this->resource) && $this->verified_at !== null) : false,
            'can_verify' => $user ? (
                $user->can('verify-transport-records')
                && $this->verified_at === null
                && $this->payment_status !== \App\Models\TransportRecord::STATUS_CANCELLED
            ) : false,
            'can_cancel' => $user ? (
                ($isOwner || $user->can('delete-transport-records'))
                && $this->payment_voucher_id === null
                && $this->payment_status === \App\Models\TransportRecord::STATUS_UNPAID
            ) : false,
            'can_print' => $user ? $user->can('print-transport-records') : false,
        ];
    }
}
