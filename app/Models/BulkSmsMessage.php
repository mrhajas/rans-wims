<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BulkSmsMessage extends Model
{
    protected $fillable = [
        'channel', 'body', 'status', 'scheduled_at', 'sent_by_user_id',
        'total_recipients', 'sent_count', 'failed_count',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'total_recipients' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
    ];

    public const STATUSES = ['Draft', 'Scheduled', 'Sending', 'Completed', 'Cancelled'];

    public function recipients()
    {
        return $this->hasMany(BulkSmsRecipient::class);
    }

    public function sentBy()
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function getBodyPreviewAttribute(): string
    {
        return Str::limit($this->body, 60);
    }
}
