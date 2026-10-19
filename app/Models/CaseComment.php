<?php

namespace App\Models;

class CaseComment extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = false;

    protected $fillable = ['case_ticket_id', 'user_id', 'body'];

    public function caseTicket()
    {
        return $this->belongsTo(CaseTicket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
