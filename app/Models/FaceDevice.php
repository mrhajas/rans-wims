<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A browser/device a person registered for Face ID; its (non-exportable) private key signs every face sign-in. */
class FaceDevice extends Model
{
    protected $guarded = [];

    protected $hidden = ['public_key'];

    protected $casts = ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($q)
    {
        return $q->whereNull('revoked_at');
    }
}
