<?php

namespace App\Models;

/** One line of the work schedule. The amount is calculated on the server (quantity x rate) or entered (fixed / lump sum); a group total is always the sum of its items. */
class SubcontractWorkItem extends Model
{
    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const PRICING = ['qty_rate' => 'Quantity x rate', 'fixed' => 'Fixed amount', 'lump_sum' => 'Lump sum'];

    public const TYPES = ['original' => 'Original', 'additional' => 'Additional', 'revised' => 'Revised'];

    public $casts = ['extra_attributes' => 'array', 'quantity' => 'decimal:4', 'rate' => 'decimal:4', 'amount' => 'decimal:2'];

    public function agreement()
    {
        return $this->belongsTo(SubcontractAgreement::class, 'agreement_id');
    }

    public function group()
    {
        return $this->belongsTo(SubcontractWorkGroup::class, 'group_id');
    }

    public function variation()
    {
        return $this->belongsTo(SubcontractVariation::class, 'variation_id');
    }
}
