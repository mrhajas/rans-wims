<?php

namespace App\Models;

class GoodsReceiptItem extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    protected $fillable = [
        'goods_receipt_id', 'item_id', 'item_name', 'item_code', 'unit_label', 'ordered_quantity', 'received_quantity',
    ];

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
