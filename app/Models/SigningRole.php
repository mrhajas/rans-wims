<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** Prepared By, Approved By ... (an editable list). The key is stable; the label is what reports print. */
class SigningRole extends Eloquent
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}
