<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** One loading / unloading worker's day: Present earns the daily rate that applied that day, Absent earns nothing. */
class TransportLoadingAttendance extends Eloquent
{
    public const PRESENT = 'Present';

    public const ABSENT = 'Absent';

    public const STATUSES = [self::PRESENT, self::ABSENT];

    protected $guarded = [];

    protected $casts = ['work_date' => 'date', 'daily_rate' => 'float', 'amount' => 'float'];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
