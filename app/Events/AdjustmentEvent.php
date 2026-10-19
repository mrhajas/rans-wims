<?php

namespace App\Events;

class AdjustmentEvent
{
    public $adjustment;

    public $method;

    public $original;

    /** True when the record was removed for good (so the workflow page can tell "deleted" from "deleted for good"). */
    public $permanent = false;

    public function __construct($adjustment, $method = 'created', $original = null)
    {
        $this->method = $method;
        $this->original = $original;
        $this->adjustment = $adjustment;
    }
}
