<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean', 'bell' => 'boolean', 'sms' => 'boolean', 'email' => 'boolean', 'skip_actor' => 'boolean', 'notify_owner' => 'boolean',
        'user_ids' => 'array', 'role_names' => 'array', 'send_days' => 'array', 'last_sent_at' => 'datetime',
    ];
}
