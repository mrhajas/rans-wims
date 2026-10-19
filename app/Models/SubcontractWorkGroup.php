<?php

namespace App\Models;

class SubcontractWorkGroup extends Model
{
    public static $hasAccount = false;

    public const KINDS = ['location' => 'Location / building', 'category' => 'Work category', 'phase' => 'Phase', 'original' => 'Original works', 'additional' => 'Additional works', 'revised' => 'Revised works', 'other' => 'Other'];

    public function agreement()
    {
        return $this->belongsTo(SubcontractAgreement::class, 'agreement_id');
    }

    public function items()
    {
        return $this->hasMany(SubcontractWorkItem::class, 'group_id')->orderBy('line_no')->orderBy('id');
    }
}
