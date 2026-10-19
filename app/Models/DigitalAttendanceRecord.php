<?php

namespace App\Models;

class DigitalAttendanceRecord extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public const STATUS_CLOCKED_IN = 'CLOCKED_IN';

    public const STATUS_CLOCKED_OUT = 'CLOCKED_OUT';

    public const STATUS_MISSING_CLOCK_OUT = 'MISSING_CLOCK_OUT';

    public const STATUS_VOIDED = 'VOIDED';

    public const STATUSES = [
        self::STATUS_CLOCKED_IN,
        self::STATUS_CLOCKED_OUT,
        self::STATUS_MISSING_CLOCK_OUT,
        self::STATUS_VOIDED,
    ];

    public const LOCATION_CAPTURED = 'captured';

    public const LOCATION_DENIED = 'denied';

    public const LOCATION_UNAVAILABLE = 'unavailable';

    public $casts = [
        'attendance_date' => 'date',
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
        'first_clock_in_at' => 'datetime',
        'voided_at' => 'datetime',
        'is_late' => 'boolean',
        'corrected' => 'boolean',
        'clock_in_latitude' => 'float',
        'clock_in_longitude' => 'float',
        'clock_in_accuracy' => 'float',
        'clock_out_latitude' => 'float',
        'clock_out_longitude' => 'float',
        'clock_out_accuracy' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function events()
    {
        return $this->hasMany(DigitalAttendanceEvent::class)->orderByDesc('id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', '!=', self::STATUS_VOIDED);
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['date'] ?? null, fn ($q, $d) => $q->whereDate('attendance_date', $d))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('attendance_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('attendance_date', '<=', $d))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->whereHas('user', fn ($sq) => $sq->where('name', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%"));
            });
    }

    public function getWorkedMinutesLiveAttribute(): int
    {
        $accumulated = (int) ($this->accumulated_minutes ?? 0);

        if ($this->clock_in_at && $this->status === self::STATUS_CLOCKED_IN) {
            return $accumulated + max(0, now()->diffInMinutes($this->clock_in_at));
        }

        if ($this->worked_minutes !== null) {
            return $this->worked_minutes;
        }

        return $accumulated;
    }

    public function getWorkedLabelAttribute(): string
    {
        $minutes = $this->worked_minutes_live;
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return sprintf('%02dh %02dm', $hours, $mins);
    }
}
