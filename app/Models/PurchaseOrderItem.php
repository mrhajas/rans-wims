<?php

namespace App\Models;

class PurchaseOrderItem extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    protected $fillable = [
        'purchase_order_id', 'item_id', 'item_name', 'item_code', 'unit_label', 'quantity', 'unit_price', 'received_quantity',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
