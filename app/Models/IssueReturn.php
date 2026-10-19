<?php

namespace App\Models;

use App\Traits\HasAttachments;

class IssueReturn extends Model
{
    use HasAttachments;

    public static $hasUser = true;

    protected $fillable = [
        'issue_transaction_id',
        'return_date',
        'returned_by',
        'received_by',
        'contact_number',
        'remarks',
        'user_id',
        'account_id',
    ];

    public $casts = [
        'return_date' => 'date',
    ];

    public function issueTransaction()
    {
        return $this->belongsTo(IssueTransaction::class)->withTrashed();
    }

    public function items()
    {
        return $this->hasMany(IssueReturnItem::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
