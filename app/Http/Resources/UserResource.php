<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request)
    {
        $roles = $this->relationLoaded('roles')
            ? $this->roles
            : $this->roles()->get(['name']);

        $warehouses = $this->relationLoaded('warehouses')
            ? $this->warehouses
            : $this->warehouses()->get(['warehouses.id', 'warehouses.name']);

        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'email'              => $this->email,
            'phone'              => data_get($this->extra_attributes, 'phone'),
            'username'           => $this->username,
            'view_all'           => $this->view_all,
            'edit_all'           => $this->edit_all,
            'deleted_at'         => $this->deleted_at,
            'warehouse_id'       => $this->warehouse_id,
            'warehouses'         => $warehouses->pluck('id')->values(),
            'warehouses_access'  => $warehouses->pluck('name')->filter()->values(),
            'photo'              => $this->profile_photo_url,
            'two_factor_enabled' => $this->resource->hasAnyTwoFactorMethod(),
            'roles'              => $roles->pluck('name')->values(),
            'is_active'          => $this->is_active === null ? true : (bool) $this->is_active,
            'last_seen_at'       => optional($this->last_seen_at)->toIso8601String(),
            'staff_number'       => $this->relationLoaded('staff') ? optional($this->staff)->staff_number : null,
            'disabled_at'        => $this->disabled_at,
            'disabled_by_name'   => optional($this->disabledBy)->name,
        ];
    }
}
