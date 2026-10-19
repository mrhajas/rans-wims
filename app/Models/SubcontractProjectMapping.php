<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** One Excel project name matched to a Project(s) / Warehouse(s) (warehouse). confirmed_at is null while the match is only a draft. */
class SubcontractProjectMapping extends Eloquent
{
    protected $guarded = [];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class)->withoutGlobalScopes()->withTrashed();
    }
}
