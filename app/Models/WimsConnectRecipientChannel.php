<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WimsConnectRecipientChannel extends Model
{
    protected $fillable = ['user_id', 'channel', 'address', 'is_verified', 'is_enabled', 'auto_filled'];

    protected $casts = [
        'is_verified' => 'boolean',
        'is_enabled' => 'boolean',
        'auto_filled' => 'boolean',
    ];

    protected static function booted(): void
    {
        // a mobile number is always stored as +255XXXXXXXXX, whoever writes it (Meta is a WhatsApp number too; a number that is not a Tanzanian mobile is left as typed)
        static::saving(function (self $row) {
            if (in_array($row->channel, ['sms', 'whatsapp', 'meta'], true) && filled($row->address) && ($tz = \App\Support\TzPhone::normalize($row->address))) {
                $row->address = $tz;
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
