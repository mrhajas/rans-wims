<?php

namespace App\Models;

use App\Traits\HasAttachments;

class GoodsReceipt extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_CONFIRMED = 'Confirmed';

    protected $fillable = [
        'reference', 'purchase_order_id', 'warehouse_id', 'received_by_user_id', 'date_received',
        'status', 'remarks', 'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'date_received' => 'date:Y-m-d',
    ];

    public function items()
    {
        return $this->hasMany(GoodsReceiptItem::class)->orderBy('id');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
