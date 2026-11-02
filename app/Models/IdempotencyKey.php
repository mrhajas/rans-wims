<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as BaseModel;

/** A form submission that already created its record (see App\Support\IdempotencyGuard). Deliberately outside the account/audit model traits: it is plumbing, not business data. */
class IdempotencyKey extends BaseModel
{
    public const UPDATED_AT = null;

    protected $table = 'idempotency_keys';

    protected $guarded = [];
}
