<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** A user who is registered as a signatory. The person is the existing User (and Staff) record; this row only holds the signature status. */
class Signatory extends Eloquent
{
    public const PENDING = 'pending_verification';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const REVOKED = 'revoked';

    protected $guarded = [];

    protected $casts = ['registered_at' => 'datetime', 'verified_at' => 'datetime', 'suspended_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    public function versions()
    {
        return $this->hasMany(SignatureVersion::class)->orderByDesc('version_no');
    }

    public function activeVersion()
    {
        return $this->belongsTo(SignatureVersion::class, 'active_version_id');
    }

    /** Can this person's signature be put on a NEW report right now? */
    public function usable(): bool
    {
        return $this->status === self::ACTIVE && $this->active_version_id !== null;
    }
}
