<?php

namespace App\Models;

class NoticeHistory extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $tracksDeletes = false;

    protected $fillable = [
        'notice_id',
        'user_id',
        'sent_by',
        'sent_at',
        'extra_attributes',
    ];

    public $casts = [
        'sent_at' => 'datetime',
    ];

    public function notice()
    {
        return $this->belongsTo(Notice::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
