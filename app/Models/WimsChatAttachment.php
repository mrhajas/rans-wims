<?php

namespace App\Models;

class WimsChatAttachment extends WimsChatModel
{
    protected $table = 'wimschat_attachments';

    protected $hidden = ['stored_path', 'thumb_path', 'checksum'];

    public static $tracksDeletes = true;

    public function message()
    {
        return $this->belongsTo(WimsChatMessage::class, 'message_id')->withTrashed();
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function isImage(): bool
    {
        return $this->category === 'images';
    }
}
