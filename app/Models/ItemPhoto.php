<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** One of the (up to) three photos taken or attached for an item, besides its main photo. */
class ItemPhoto extends Eloquent
{
    public const MAX = 3;

    protected $guarded = [];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
