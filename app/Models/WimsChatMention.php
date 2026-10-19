<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class WimsChatMention extends Eloquent
{
    public $timestamps = false;

    protected $table = 'wimschat_mentions';

    protected $guarded = [];
}
