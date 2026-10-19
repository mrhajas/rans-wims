<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MaterialReallocationEvent
{
    use Dispatchable, SerializesModels;

    public $materialReallocation;

    public $method;

    public function __construct($materialReallocation, $method)
    {
        $this->materialReallocation = $materialReallocation;
        $this->method = $method;
    }
}
