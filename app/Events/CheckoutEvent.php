<?php

namespace App\Events;

class CheckoutEvent
{
    public $checkout;

    public $method;

    public $original;

    /** True when the record was removed for good (so the workflow page can tell "deleted" from "deleted for good"). */
    public $permanent = false;

    public function __construct($checkout, $method = 'created', $original = null)
    {
        $this->method = $method;
        $this->checkout = $checkout;
        $this->original = $original;
    }
}
