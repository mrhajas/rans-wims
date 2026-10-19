<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** One line of a section of a meeting's minutes. The cells live in `data`, keyed by the template's column keys. */
class SiteMeetingRow extends Eloquent
{
    protected $table = 'site_meeting_rows';

    protected $guarded = [];

    protected $casts = ['data' => 'array'];
}
