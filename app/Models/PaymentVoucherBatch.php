<?php

namespace App\Models;

use App\Traits\HasAttachments;

class PaymentVoucherBatch extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    protected $fillable = [
        'batch_number', 'type', 'payee_type', 'warehouse_id', 'voucher_date',
        'common_particulars', 'reference', 'common_notes', 'created_by_user_id', 'labour_list_id',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'voucher_date' => 'date:Y-m-d',
    ];

    /** Same rule as the vouchers: not limited to projects sees all; otherwise the batches of their projects, company-wide ones and the ones they made. */
    public function scopeVisibleTo($query, ?User $u)
    {
        $ids = PaymentVoucher::projectIdsFor($u);
        if ($ids === null) {
            return $query;
        }

        return $query->where(fn ($w) => $w->whereIn('warehouse_id', $ids)->orWhereNull('warehouse_id')->orWhere('created_by_user_id', $u->id));
    }

    public function visibleToUser(?User $u): bool
    {
        $ids = PaymentVoucher::projectIdsFor($u);

        return $ids === null || $this->warehouse_id === null || in_array((int) $this->warehouse_id, $ids, true) || (int) $this->created_by_user_id === (int) $u->id;
    }

    public function vouchers()
    {
        return $this->hasMany(PaymentVoucher::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function labourList()
    {
        return $this->belongsTo(LabourList::class)->withoutGlobalScope('workflow_visibility');
    }
}
