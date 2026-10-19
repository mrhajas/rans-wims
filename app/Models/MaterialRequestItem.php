<?php

namespace App\Models;

class MaterialRequestItem extends Model
{
    public static $hasAccount = false;

    public static $tracksDeletes = true;

    protected $fillable = [
        'material_request_id',
        'item_id',
        'unit_id',
        'quantity',
        'priority',
        'procedure',
        'procedure_contact_id',
        'procedure_warehouse_id',
        'procedure_note',
        'delivery_status',
        'delivered_by_user_id',
        'delivered_at',
        'remarks',
    ];

    public $casts = [
        'quantity' => 'float',
        'delivered_at' => 'datetime',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function procedureContact()
    {
        return $this->belongsTo(Contact::class, 'procedure_contact_id');
    }

    public function procedureWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'procedure_warehouse_id');
    }

    public function deliveredBy()
    {
        return $this->belongsTo(User::class, 'delivered_by_user_id');
    }

    public function materialRequest()
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
