<?php

namespace App\Models;

class AssetMovement extends Model
{
    public static $hasAccount = false;

    public const TYPES = ['Registered', 'Assigned', 'Check-Out', 'Check-In', 'Transfer'];

    public $casts = [
        'movement_date' => 'date',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function fromResponsibleUser()
    {
        return $this->belongsTo(User::class, 'from_responsible_user_id');
    }

    public function toResponsibleUser()
    {
        return $this->belongsTo(User::class, 'to_responsible_user_id');
    }

    public function authorisedBy()
    {
        return $this->belongsTo(User::class, 'authorised_by_user_id');
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function scopeForAsset($query, $assetId)
    {
        return $query->where('asset_id', $assetId);
    }
}
