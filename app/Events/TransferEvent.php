<?php

namespace App\Events;

class TransferEvent
{
    public $method;

    public $original;

    /** True when the record was removed for good (so the workflow page can tell "deleted" from "deleted for good"). */
    public $permanent = false;

    public $transfer;

    public function __construct($transfer, $method = 'created', $original = null)
    {
        $this->method = $method;
        $this->original = $original;
        $this->transfer = $transfer;
    }
}
