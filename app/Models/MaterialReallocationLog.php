<?php

namespace App\Models;

class MaterialReallocationLog extends Model
{
    public static $hasAccount = false;

    public static $tracksDeletes = false;

    protected $fillable = [
        'material_reallocation_id',
        'user_id',
        'role',
        'action',
        'previous_status',
        'new_status',
        'remarks',
        'ip_address',
        'user_agent',
    ];

    public function materialReallocation()
    {
        return $this->belongsTo(MaterialReallocation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
