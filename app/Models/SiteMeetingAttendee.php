<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** Someone invited to a meeting: a WIMS user (user_id) or an outside person (name / organisation only). */
class SiteMeetingAttendee extends Eloquent
{
    public const ATTENDANCE = ['Invited', 'Present', 'Absent', 'Apology'];

    protected $table = 'site_meeting_attendees';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
