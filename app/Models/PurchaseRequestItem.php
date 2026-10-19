<?php

namespace App\Models;

class PurchaseRequestItem extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    protected $fillable = [
        'purchase_request_id', 'item_id', 'item_name', 'item_code', 'unit_label', 'quantity', 'remarks',
    ];

    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class)->withoutGlobalScope('workflow_visibility');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
