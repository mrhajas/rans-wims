<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'category'   => $this->category,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'location'   => $this->location,
            'details'    => $this->details,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
