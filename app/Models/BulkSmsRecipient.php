<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulkSmsRecipient extends Model
{
    protected $fillable = [
        'bulk_sms_message_id', 'name', 'phone', 'status',
        'provider_message_id', 'error_message', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public const STATUSES = ['Pending', 'Sending', 'Sent', 'Failed'];

    public function message()
    {
        return $this->belongsTo(BulkSmsMessage::class, 'bulk_sms_message_id');
    }
}
