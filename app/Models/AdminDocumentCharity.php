<?php

namespace App\Models;

/** One line of the Charity section of an Administration document: pulled from the Charity list, and editable on the document. */
class AdminDocumentCharity extends Model
{
    public static $hasAccount = false;

    protected $table = 'admin_document_charities';

    protected $fillable = ['admin_document_id', 'admin_charity_person_id', 'name', 'description', 'amount', 'monthly_amount', 'sort_order'];

    public $casts = ['amount' => 'decimal:2', 'monthly_amount' => 'decimal:2'];

    public function document()
    {
        return $this->belongsTo(AdminDocument::class, 'admin_document_id');
    }

    public function person()
    {
        return $this->belongsTo(AdminCharityPerson::class, 'admin_charity_person_id')->withTrashed();
    }
}
