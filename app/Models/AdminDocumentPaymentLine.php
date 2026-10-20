<?php

namespace App\Models;

/** What one payment pays on one line (a line of the document or of its Charity section). */
class AdminDocumentPaymentLine extends Model
{
    public static $hasAccount = false;

    protected $table = 'admin_document_payment_lines';

    protected $fillable = ['admin_document_payment_id', 'line_type', 'line_id', 'amount'];

    public $casts = ['amount' => 'decimal:2'];

    public function payment()
    {
        return $this->belongsTo(AdminDocumentPayment::class, 'admin_document_payment_id');
    }
}
