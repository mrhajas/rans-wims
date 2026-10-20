<?php

namespace App\Models;

/** A record of a document being sent by email: who sent it, to whom, and whether it went. */
class AdminDocumentSend extends Model
{
    public static $hasAccount = false;

    protected $table = 'admin_document_sends';

    protected $fillable = ['admin_document_id', 'user_id', 'recipients', 'to_super_admins', 'status', 'error'];

    public $casts = ['recipients' => 'array', 'to_super_admins' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
