<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** A person's previous (brought forward) Loading & Unloading balance. One per person; changing or removing it is logged. */
class TransportLoadingOpeningBalance extends Eloquent
{
    protected $guarded = [];

    protected $casts = ['as_of_date' => 'date', 'amount' => 'float'];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
