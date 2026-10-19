<?php

namespace App\Models;

/** How many pieces of an asset are at one place (project / warehouse, free-text location, and who holds them). */
class AssetPlacement extends Model
{
    public static $hasAccount = false;

    public static $hasReference = false;

    public static $hasUser = false;

    public $casts = ['quantity' => 'float'];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function responsibleUser()
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
