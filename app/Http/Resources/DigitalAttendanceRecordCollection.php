<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Paginated-collection wrapper for the Digital Attendance history/records
 * pages — mirrors App\Http\Resources\SupplierRecords\SupplierRecordsCollection
 * exactly. Item-level transformation happens on the paginator itself via
 * ->through() before wrapping, so toArray() returns the pre-shaped plain
 * arrays as-is; the default ResourceCollection::toArray() (which calls
 * ->toArray() on every item) would fail on them since they're no longer
 * Resource/model instances. Pagination meta/links are still added by the
 * PaginatedResourceResponse since this wraps a paginator.
 */
class DigitalAttendanceRecordCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return $this->collection->all();
    }
}
