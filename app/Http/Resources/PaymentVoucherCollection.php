<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Paginated-collection wrapper for the Payment Vouchers Index page. Item-level
 * transformation happens on the paginator itself via ->through() before
 * wrapping (see App\Http\Resources\SupplierRecords\SupplierRecordsCollection,
 * which this mirrors) — so toArray() must return the pre-shaped plain arrays
 * as-is rather than calling ->toArray() on each (they're not Resource
 * objects).
 */
class PaymentVoucherCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return $this->collection->all();
    }
}
