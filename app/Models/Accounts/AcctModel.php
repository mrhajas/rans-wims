<?php

namespace App\Models\Accounts;

use Illuminate\Database\Eloquent\Model;

/** Accounts Tracking models live in their own schema (connection `accounts`). */
abstract class AcctModel extends Model
{
    protected $connection = 'accounts';

    protected $guarded = [];
}
