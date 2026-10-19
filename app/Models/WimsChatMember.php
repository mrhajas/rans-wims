<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** A person's seat in a conversation, with their private state (read cursor, pin, archive, mute …). */
class WimsChatMember extends Eloquent
{
    protected $table = 'wimschat_members';

    protected $guarded = [];

    protected $casts = [
        'pinned_at' => 'datetime', 'archived_at' => 'datetime', 'muted' => 'boolean', 'muted_until' => 'datetime',
        'hidden_at' => 'datetime', 'marked_unread' => 'boolean', 'joined_at' => 'datetime', 'left_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(WimsChatConversation::class, 'conversation_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function isMuted(): bool
    {
        return $this->muted && (! $this->muted_until || $this->muted_until->isFuture());
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
