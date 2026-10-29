<?php

namespace App\Models;

/** One person on one channel (email, SMS or app alert) of a reminder, and how it went. */
class MaterialRequestReminderDelivery extends Model
{
    protected $table = 'mr_reminder_deliveries';

    protected static $recordEvents = [];

    public static $hasAccount = false;

    protected $fillable = ['reminder_id', 'user_id', 'name', 'channel', 'address', 'status', 'error', 'sent_at'];

    public $casts = ['sent_at' => 'datetime'];

    public function reminder()
    {
        return $this->belongsTo(MaterialRequestReminder::class, 'reminder_id');
    }
}
