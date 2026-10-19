<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IssueReturnLog extends Model
{
    protected $fillable = [
        'account_id',
        'user_id',
        'issue_transaction_id',
        'issue_return_id',
        'warehouse_id',
        'action',
        'ip_address',
        'details',
    ];

    public function issueTransaction()
    {
        return $this->belongsTo(IssueTransaction::class);
    }

    public function issueReturn()
    {
        return $this->belongsTo(IssueReturn::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
