<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/** Paginated-collection wrapper for the Case Tracking Index page — mirrors App\Http\Resources\WorkforceAgreementCollection exactly. */
class CaseTicketCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return $this->collection->all();
    }
}
