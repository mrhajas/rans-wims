<?php

namespace App\Models;

/** One greeting to one address on one channel, with how it went. Kept as the record of the holiday. */
class HolidayDelivery extends Model
{
    protected $table = 'holiday_deliveries';

    // the status of a row changes several times per message: the holiday's own record (and the Delivery Logs) already say what was sent
    protected static $recordEvents = [];

    public static $hasAccount = false;

    protected $fillable = ['holiday_id', 'contact_id', 'channel', 'address', 'title', 'name', 'status', 'attempts', 'error', 'provider_message_id', 'sent_by_user_id', 'bundle_name', 'queued_at', 'last_attempt_at', 'sent_at'];

    public $casts = ['queued_at' => 'datetime', 'last_attempt_at' => 'datetime', 'sent_at' => 'datetime', 'attempts' => 'integer'];

    public function holiday()
    {
        return $this->belongsTo(HolidayDay::class, 'holiday_id');
    }

    public function fullName(): string
    {
        return trim(($this->title ? $this->title . ' ' : '') . $this->name);
    }
}
