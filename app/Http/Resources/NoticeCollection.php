<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Paginated-collection wrapper for the Notices Index page. The controller
 * paginates raw Notice models (no ->through()), so returning
 * $this->collection->all() here preserves exactly the same per-item JSON
 * shape as before (each model still serializes via its own toArray()/
 * jsonSerialize()) while letting Laravel's PaginatedResourceResponse add
 * the nested meta/links shape the frontend Pagination.vue component
 * requires.
 */
class NoticeCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return $this->collection->all();
    }
}
