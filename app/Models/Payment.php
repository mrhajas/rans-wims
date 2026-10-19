<?php

namespace App\Models;

use App\Traits\HasAttachments;

class Payment extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const METHODS = ['Bank Transfer', 'Cheque', 'Cash', 'Mobile Money'];

    public const VERIFICATION_PENDING = 'Pending Verification';
    public const VERIFICATION_VERIFIED = 'Verified';

    protected $fillable = [
        'reference', 'payment_request_id', 'supplier_id', 'amount', 'method', 'currency', 'cheque_number',
        'reference_number', 'paid_on', 'paid_by_user_id', 'remarks',
        'verification_status', 'verified_by_user_id', 'verified_on', 'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'paid_on' => 'date:Y-m-d',
        'verified_on' => 'datetime',
    ];

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * FIFO-allocate an account-balance payment across a supplier's
     * outstanding invoices, oldest invoice_date first, until the amount is
     * exhausted. Updates each invoice's amount_paid/status and creates the
     * PaymentAllocation rows recording exactly what applied where.
     */
    public function allocateAgainstBalance(int $supplierId, float $amount): void
    {
        $remaining = $amount;

        $outstanding = SupplierInvoice::where('supplier_id', $supplierId)
            ->whereColumn('amount_paid', '<', 'total')
            ->orderBy('invoice_date')
            ->get();

        foreach ($outstanding as $invoice) {
            if ($remaining <= 0.01) {
                break;
            }

            $balance = $invoice->total - $invoice->amount_paid;
            $applied = min($balance, $remaining);
            $newAmountPaid = $invoice->amount_paid + $applied;

            $this->applyToInvoice($invoice, $newAmountPaid, $applied);

            $this->allocations()->create(['supplier_invoice_id' => $invoice->id, 'amount' => round($applied, 2)]);
            $remaining -= $applied;
        }
    }

    public function allocateAgainstInvoice(SupplierInvoice $invoice, float $amount): void
    {
        $newAmountPaid = $invoice->amount_paid + $amount;
        $this->applyToInvoice($invoice, $newAmountPaid, $amount);

        $this->allocations()->create(['supplier_invoice_id' => $invoice->id, 'amount' => round($amount, 2)]);
    }

    /** Records the payment on the invoice (amount_paid + the Partially Paid / Paid status) and leaves an audit row naming who made the status change. */
    private function applyToInvoice(SupplierInvoice $invoice, float $newAmountPaid, float $applied): void
    {
        $from = $invoice->status;
        $to = $newAmountPaid >= $invoice->total - 0.01 ? SupplierInvoice::STATUS_PAID : SupplierInvoice::STATUS_PARTIALLY_PAID;
        $invoice->update(['amount_paid' => $newAmountPaid, 'status' => $to]);
        log_activity($to === SupplierInvoice::STATUS_PAID ? __('Supplier Invoice paid.') : __('Supplier Invoice partly paid.'), [
            'reference' => $invoice->reference, 'from' => $from, 'to' => $to, 'payment_reference' => $this->reference, 'applied' => round($applied, 2), 'by_user_id' => auth()->id(),
        ], $invoice, 'Supplier Invoice');
    }
}
