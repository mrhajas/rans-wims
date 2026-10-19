<?php

namespace App\Models;

/**
 * Base for the WIMSChat records that keep message content. Generic attribute auditing is switched off on purpose:
 * everything that happens is written explicitly by WimsChatLogger (who / what / when / IP), and message text must never
 * be copied into the System Logs.
 */
abstract class WimsChatModel extends Model
{
    protected static function eventsToBeRecorded(): \Illuminate\Support\Collection
    {
        return collect();
    }
}
