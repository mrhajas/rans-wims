<?php

namespace App\Models;

use App\Traits\HasAttachments;

class AssetDisposal extends Model
{
    use HasAttachments;

    public static $hasAccount = false;

    public static $tracksDeletes = false;

    public const DISPOSAL_METHODS = [
        'Sold', 'Scrapped', 'Written Off', 'Donated', 'Returned to Owner', 'Lost', 'Stolen', 'Destroyed', 'Other',
    ];

    // How each disposal method maps onto the asset's own `status` column.
    public const STATUS_MAP = [
        'Sold' => 'Sold',
        'Scrapped' => 'Disposed',
        'Written Off' => 'Written Off',
        'Donated' => 'Disposed',
        'Returned to Owner' => 'Disposed',
        'Lost' => 'Lost',
        'Stolen' => 'Stolen',
        'Destroyed' => 'Disposed',
        'Other' => 'Disposed',
    ];

    public $casts = [
        'disposal_date' => 'date',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
