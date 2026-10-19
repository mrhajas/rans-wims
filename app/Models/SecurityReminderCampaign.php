<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityReminderCampaign extends Model
{
    protected $fillable = [
        'sent_by_user_id', 'message', 'recipient_mode', 'recipient_count',
    ];

    protected $casts = [
        'recipient_count' => 'integer',
    ];

    public function sentBy()
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function recipients()
    {
        return $this->hasMany(SecurityReminderRecipient::class, 'campaign_id');
    }
}
