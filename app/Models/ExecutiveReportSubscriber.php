<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExecutiveReportSubscriber extends Model
{
    protected $fillable = ['name', 'email', 'active', 'account_id', 'user_id'];

    protected $casts = ['active' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOfAccount($query, $accountId = null)
    {
        return $query->where('account_id', $accountId ?? auth()->user()?->account_id);
    }
}
