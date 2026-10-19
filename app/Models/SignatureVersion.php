<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** One signature picture of a signatory. Never edited after it is confirmed: a change is a new version. */
class SignatureVersion extends Eloquent
{
    public const PENDING = 'pending_verification';
    public const ACTIVE = 'active';
    public const REPLACED = 'replaced';
    public const REJECTED = 'rejected';
    public const SUSPENDED = 'suspended';
    public const REVOKED = 'revoked';

    protected $guarded = [];

    protected $casts = ['metrics' => 'array', 'uploaded_at' => 'datetime', 'consent_at' => 'datetime', 'verified_at' => 'datetime', 'activated_at' => 'datetime', 'ended_at' => 'datetime', 'original_purged_at' => 'datetime'];

    protected $hidden = ['processed_path', 'thumb_path', 'original_path'];

    public function signatory()
    {
        return $this->belongsTo(Signatory::class);
    }
}
