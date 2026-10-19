<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** A signature a report needed and could not show, and why. Feeds the "missing signatures" dashboard and report. */
class SignatureMissing extends Eloquent
{
    protected $table = 'signature_missing';

    protected $guarded = [];

    protected $casts = ['blocked' => 'boolean', 'seen_at' => 'datetime'];
}
