<?php

namespace App\Http\Resources\SupplierRecords;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * One reusable paginated-collection wrapper for every Supplier Records
 * Index page (Purchase Requests, Quotations, Purchase Orders, Goods
 * Receipts, Supplier Invoices, Payment Requests, Payments). Unlike
 * App\Http\Resources\CheckinCollection (which paginates raw models),
 * each paginator here is already shaped into plain arrays via ->through()
 * before wrapping, so the default ResourceCollection::toArray() — which
 * calls ->toArray() on every item — would fail on plain arrays.
 */
class SupplierRecordsCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return $this->collection->all();
    }
}
