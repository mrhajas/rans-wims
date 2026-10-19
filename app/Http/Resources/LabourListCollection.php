<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Paginated-collection wrapper for the Labour Index page — mirrors
 * App\Http\Resources\PaymentVoucherCollection exactly. Item-level
 * transformation happens on the paginator itself via ->transform() before
 * wrapping, so toArray() returns the pre-shaped plain arrays as-is.
 */
class LabourListCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return $this->collection->all();
    }
}
