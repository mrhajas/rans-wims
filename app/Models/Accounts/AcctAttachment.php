<?php

namespace App\Models\Accounts;

class AcctAttachment extends AcctModel
{
    protected $table = 'acct_attachments';
    public $timestamps = false;

    protected $casts = ['restricted' => 'boolean', 'created_at' => 'datetime'];
}
