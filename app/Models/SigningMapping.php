<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** Which signing role a document carries, which recorded workflow action qualifies the signer, and what happens when it is missing. */
class SigningMapping extends Eloquent
{
    protected $guarded = [];

    protected $casts = ['is_required' => 'boolean', 'blocks_final' => 'boolean', 'is_active' => 'boolean'];

    public function role()
    {
        return $this->belongsTo(SigningRole::class, 'signing_role_id');
    }
}
