<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class WimsChatReaction extends Eloquent
{
    public const UPDATED_AT = null;

    protected $table = 'wimschat_reactions';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
