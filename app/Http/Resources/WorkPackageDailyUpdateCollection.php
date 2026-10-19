<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class WorkPackageDailyUpdateCollection extends ResourceCollection
{
    public $collects = WorkPackageDailyUpdateResource::class;

    public function toArray($request)
    {
        return parent::toArray($request);
    }
}
