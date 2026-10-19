<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class WorkPackageIssueCollection extends ResourceCollection
{
    public $collects = WorkPackageIssueResource::class;
}
