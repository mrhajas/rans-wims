<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Paginated-collection wrapper for the Asset Register Index page —
 * mirrors App\Http\Resources\PaymentVoucherCollection. Item-level
 * transformation happens on the paginator itself via ->through() before
 * wrapping, so toArray() returns the pre-shaped plain arrays as-is.
 */
class AssetCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return $this->collection->all();
    }
}
