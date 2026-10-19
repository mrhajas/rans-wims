<?php

namespace App\Models;

use App\Traits\HasAttachments;

class AssetMaintenanceRecord extends Model
{
    use HasAttachments;

    public static $hasAccount = false;

    public static $tracksDeletes = false;

    public const MAINTENANCE_TYPES = [
        'Routine Service', 'Preventive Maintenance', 'Corrective Maintenance', 'Repair',
        'Major Overhaul', 'Inspection', 'Calibration', 'Other',
    ];

    public $casts = [
        'maintenance_date' => 'date',
        'next_service_date' => 'date',
        'under_warranty' => 'boolean',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
