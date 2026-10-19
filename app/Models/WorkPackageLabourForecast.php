<?php

namespace App\Models;

class WorkPackageLabourForecast extends Model
{
    public static $tracksDeletes = true;

    protected $fillable = [
        'work_package_id', 'contact_id', 'trade', 'quantity', 'duration_days', 'required_date', 'notes', 'created_by',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'required_date' => 'date',
        'quantity' => 'integer',
        'duration_days' => 'integer',
    ];

    public function workPackage()
    {
        return $this->belongsTo(WorkPackage::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
