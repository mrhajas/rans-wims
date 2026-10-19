<?php

namespace App\Events;

class CheckinEvent
{
    public $checkin;

    public $method;

    public $original;

    /** True when the record was removed for good (so the workflow page can tell "deleted" from "deleted for good"). */
    public $permanent = false;

    public function __construct($checkin, $method = 'created', $original = null)
    {
        $this->method = $method;
        $this->checkin = $checkin;
        $this->original = $original;
    }
}
