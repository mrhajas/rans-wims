<?php

namespace App\Events;

use App\Models\LabourList;

class LabourStatusChanged
{
    public function __construct(public LabourList $labourList, public string $actorName = '')
    {
    }
}
