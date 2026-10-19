<?php

namespace App\Models;

class WimsChatMessage extends WimsChatModel
{
    protected $table = 'wimschat_messages';

    public static $tracksDeletes = true;

    public $casts = ['edited_at' => 'datetime', 'pinned_at' => 'datetime'];

    public function conversation()
    {
        return $this->belongsTo(WimsChatConversation::class, 'conversation_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function attachments()
    {
        return $this->hasMany(WimsChatAttachment::class, 'message_id');
    }

    public function reactions()
    {
        return $this->hasMany(WimsChatReaction::class, 'message_id');
    }

    public function mentions()
    {
        return $this->hasMany(WimsChatMention::class, 'message_id');
    }

    public function replyTo()
    {
        return $this->belongsTo(self::class, 'reply_to_id')->withTrashed();
    }
}
