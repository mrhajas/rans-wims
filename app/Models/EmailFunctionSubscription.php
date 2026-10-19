<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailFunctionSubscription extends Model
{
    protected $fillable = ['user_id', 'function_key', 'account_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOfAccount($query, $accountId = null)
    {
        return $query->where('account_id', $accountId ?? auth()->user()?->account_id);
    }
}
