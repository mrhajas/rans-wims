<?php

namespace App\Http\Controllers\SiteMeetings;

use App\Services\SiteMeetings\SiteMeetingSettings;

/** Tiny lookup so controllers validate against the one list of statuses. */
class SiteMeetingSettingsKeys
{
    public static function status(): array
    {
        return SiteMeetingSettings::STATUS_KEYS;
    }

    public static function report(): array
    {
        return SiteMeetingSettings::REPORT_KEYS;
    }
}
