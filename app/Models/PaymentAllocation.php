<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * Deliberately extends Eloquent's own base Model, not App\Models\Model —
 * this is a simple append-only ledger row (how much of one payment applied
 * to one invoice) with no soft-deletes or account scoping of its own.
 */
class PaymentAllocation extends Eloquent
{
    protected $guarded = [];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function supplierInvoice()
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
