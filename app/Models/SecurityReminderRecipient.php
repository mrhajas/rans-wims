<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityReminderRecipient extends Model
{
    protected $fillable = [
        'campaign_id', 'user_id', 'two_factor_enabled_at_send', 'notified_at',
    ];

    protected $casts = [
        'two_factor_enabled_at_send' => 'boolean',
        'notified_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(SecurityReminderCampaign::class, 'campaign_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
