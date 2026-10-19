<?php

namespace App\Events;

use App\Models\MaterialRequest;

class MaterialRequestStatusChanged
{
    public function __construct(public MaterialRequest $materialRequest, public string $remarks = '')
    {
    }
}
