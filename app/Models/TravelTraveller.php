<?php

namespace App\Models;

/** One person on a travel: a staff member, a user or someone else typed in, with their own ticket number and amounts. */
class TravelTraveller extends Model
{
    public static $hasAccount = false;

    protected $table = 'travel_travellers';

    protected $fillable = ['travel_id', 'traveller_type', 'staff_id', 'user_id', 'name', 'phone', 'ticket_number', 'ticket_amount', 'visa_amount', 'sort_order'];

    public $casts = ['ticket_amount' => 'decimal:2', 'visa_amount' => 'decimal:2'];

    public function travel()
    {
        return $this->belongsTo(Travel::class, 'travel_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id')->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
