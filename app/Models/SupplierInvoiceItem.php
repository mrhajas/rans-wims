<?php

namespace App\Models;

class SupplierInvoiceItem extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    protected $fillable = [
        'supplier_invoice_id', 'item_id', 'item_name', 'item_code', 'unit_label', 'quantity', 'unit_price',
    ];

    public function supplierInvoice()
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
