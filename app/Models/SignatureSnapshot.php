<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** The signature set of a finalised document, frozen when it became final so a later change of signature never rewrites it. */
class SignatureSnapshot extends Eloquent
{
    protected $guarded = [];

    protected $casts = ['action_at' => 'datetime', 'frozen_at' => 'datetime'];
}
