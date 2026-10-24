<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of the user audit history (see App\Support\UserAudit). Written once, never edited. */
class UserAuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];
}
