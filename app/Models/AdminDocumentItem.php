<?php

namespace App\Models;

/** One numbered line of an Administration document. */
class AdminDocumentItem extends Model
{
    public static $hasAccount = false;

    protected $table = 'admin_document_items';

    protected $fillable = ['admin_document_id', 'admin_item_id', 'description', 'beneficiary', 'beneficiary_user_id', 'category', 'staff_id', 'contact_id', 'amount', 'sort_order'];

    public $casts = ['amount' => 'decimal:2'];

    public function document()
    {
        return $this->belongsTo(AdminDocument::class, 'admin_document_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id')->withTrashed();
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id')->withTrashed();
    }

    public function item()
    {
        return $this->belongsTo(AdminItem::class, 'admin_item_id')->withTrashed();
    }
}
