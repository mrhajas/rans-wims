<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** A person's own chat choices: manual status plus personal settings (sound, receipts, density …). */
class WimsChatUserPref extends Eloquent
{
    protected $table = 'wimschat_user_prefs';

    protected $guarded = [];

    protected $casts = ['prefs' => 'array'];
}
