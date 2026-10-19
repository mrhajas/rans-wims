<?php

namespace App\Models;

class MaterialRequestStatusLog extends Model
{
    public static $hasAccount = false;

    public static $tracksDeletes = false;

    protected $fillable = [
        'material_request_id',
        'user_id',
        'status',
        'remarks',
    ];

    public function materialRequest()
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
