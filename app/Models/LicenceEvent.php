<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of a licence's history: issued, activated, deactivated, downloaded, printed... */
class LicenceEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function licence()
    {
        return $this->belongsTo(Licence::class);
    }
}
