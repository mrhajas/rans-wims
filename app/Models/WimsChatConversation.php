<?php

namespace App\Models;

class WimsChatConversation extends WimsChatModel
{
    protected $table = 'wimschat_conversations';

    public $casts = ['last_message_at' => 'datetime'];

    public function members()
    {
        return $this->hasMany(WimsChatMember::class, 'conversation_id');
    }

    public function activeMembers()
    {
        return $this->members()->whereNull('left_at');
    }

    public function messages()
    {
        return $this->hasMany(WimsChatMessage::class, 'conversation_id');
    }

    public function isGroup(): bool
    {
        return $this->type === 'group';
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }

    public function memberFor(int $userId): ?WimsChatMember
    {
        return $this->members()->where('user_id', $userId)->first();
    }
}
