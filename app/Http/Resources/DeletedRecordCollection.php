<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class DeletedRecordCollection extends ResourceCollection
{
    /**
     * The records are already transformed into plain arrays by the controller,
     * so return them as-is. Pagination meta/links are still added by the
     * PaginatedResourceResponse since this wraps a paginator.
     */
    public function toArray($request)
    {
        return $this->collection->all();
    }
}
