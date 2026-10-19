<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Paginated-collection wrapper for the Issue Materials Index page. The
 * controller paginates raw IssueTransaction models (no ->through()), so
 * returning $this->collection->all() here preserves exactly the same
 * per-item JSON shape as before while letting Laravel's
 * PaginatedResourceResponse add the nested meta/links shape the frontend
 * Pagination.vue component requires.
 */
class IssueMaterialCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return $this->collection->all();
    }
}
