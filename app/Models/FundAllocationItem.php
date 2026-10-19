<?php

namespace App\Models;

class FundAllocationItem extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = false;

    protected $fillable = [
        'fund_allocation_id', 'fund_category_id', 'sort_order', 'description', 'beneficiary', 'contact_id', 'beneficiary_user_id', 'beneficiary_warehouse_id', 'staff_id',
        'warehouse_id', 'request_amount', 'allocated_amount', 'notes', 'payment_voucher_id', 'paid_at',
    ];

    public $casts = ['request_amount' => 'float', 'allocated_amount' => 'float', 'paid_at' => 'datetime'];

    public function allocation()
    {
        return $this->belongsTo(FundAllocation::class, 'fund_allocation_id')->withoutGlobalScope('workflow_visibility');
    }

    public function category()
    {
        return $this->belongsTo(FundCategory::class, 'fund_category_id')->withTrashed();
    }

    public function lines()
    {
        return $this->hasMany(FundAllocationItemLine::class)->orderBy('id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function beneficiaryUser()
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id')->withTrashed();
    }

    public function beneficiaryWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'beneficiary_warehouse_id')->withTrashed();
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function paymentVoucher()
    {
        return $this->belongsTo(PaymentVoucher::class)->withTrashed();
    }

    /** All payment lines ticked (and there is at least one) - the "paid" state of an item. */
    public function isFullyPaid(): bool
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return $lines->isNotEmpty() && $lines->every(fn ($l) => $l->is_paid);
    }
}
