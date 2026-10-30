<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** A pass held by someone for a while (a visitor pass handed to a visitor at check-in, taken back at check-out). Kept for good: who had pass 001, from when to when. */
class IdPassAssignment extends Eloquent
{
    public $timestamps = false;

    protected $table = 'idpass_assignments';

    protected $guarded = [];

    protected $casts = ['assigned_at' => 'datetime', 'released_at' => 'datetime'];

    protected static function booted()
    {
        static::deleting(fn () => false);
        static::creating(function ($m) {
            if (! $m->account_id) {
                $m->account_id = getAccountId();
            }
        });
    }

    public function card()
    {
        return $this->belongsTo(IdPassCard::class, 'card_id')->withTrashed();
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function releasedBy()
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    public function scopeOpen($q)
    {
        return $q->whereNull('released_at');
    }
}
