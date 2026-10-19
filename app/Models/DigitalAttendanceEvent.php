<?php

namespace App\Models;

class DigitalAttendanceEvent extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public const CLOCK_IN = 'CLOCK_IN';

    public const CLOCK_OUT = 'CLOCK_OUT';

    public const ADMIN_CORRECTION = 'ADMIN_CORRECTION';

    public const ADMIN_VOID = 'ADMIN_VOID';

    public const LOCATION_CAPTURE = 'LOCATION_CAPTURE';

    public const SETTINGS_CHANGED = 'SETTINGS_CHANGED';

    public $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'metadata' => 'array',
    ];

    public static function record(?DigitalAttendanceRecord $record, string $eventType, ?string $description = null, array $options = []): self
    {
        return self::create([
            // scheduled commands have no logged-in user, so getAccountId() is null there: fall back to the record's own account
            'account_id' => $options['account_id'] ?? $record?->account_id ?? getAccountId(),
            'digital_attendance_record_id' => $record?->id,
            'user_id' => $options['user_id'] ?? $record?->user_id,
            'actor_id' => $options['actor_id'] ?? auth()->id(),
            'event_type' => $eventType,
            'description' => $description,
            'old_value' => $options['old_value'] ?? null,
            'new_value' => $options['new_value'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ]);
    }

    public function attendanceRecord()
    {
        return $this->belongsTo(DigitalAttendanceRecord::class, 'digital_attendance_record_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
