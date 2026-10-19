<?php

namespace App\Models;

class QuotationItem extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    protected $fillable = [
        'quotation_id', 'item_id', 'item_name', 'item_code', 'unit_label', 'quantity', 'unit_price',
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
