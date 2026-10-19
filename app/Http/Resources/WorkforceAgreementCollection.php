<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/** Paginated-collection wrapper for the Workforce Agreements Index page — mirrors App\Http\Resources\LabourListCollection exactly. */
class WorkforceAgreementCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return $this->collection->all();
    }
}
