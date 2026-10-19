<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class WorkPackageCollection extends ResourceCollection
{
    public $collects = WorkPackageResource::class;

    public function toArray($request)
    {
        return parent::toArray($request);
    }
}
