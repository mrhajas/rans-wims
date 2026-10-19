<?php

namespace App\Models;

/** What a ticket agent / visa issuer was owed before Travels started (per currency). Travels booked before `as_of_date` are already inside it. */
class TravelAgentOpening extends Model
{
    public static $tracksDeletes = true;

    protected $table = 'travel_agent_openings';

    protected $fillable = ['account_id', 'contact_id', 'currency', 'amount', 'as_of_date', 'notes', 'user_id', 'deleted_by'];

    public $casts = ['amount' => 'decimal:2', 'as_of_date' => 'date'];

    public function contact()
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
