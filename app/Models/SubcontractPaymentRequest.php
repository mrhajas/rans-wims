<?php

namespace App\Models;

use App\Traits\HasAttachments;

/** A request to pay a subcontractor. Approving it is not paying it: the money moves only through a Payment Voucher (see SubcontractPayment). */
class SubcontractPaymentRequest extends Model
{
    use HasAttachments;

    public static $tracksDeletes = true;

    public const DRAFT = 'Draft';
    public const SUBMITTED = 'Submitted';
    public const REVIEW = 'Under Review';
    public const RETURNED = 'Returned';
    public const REJECTED = 'Rejected';
    public const APPROVED = 'Approved';
    public const CANCELLED = 'Cancelled';

    public const STATUSES = [self::DRAFT, self::SUBMITTED, self::REVIEW, self::RETURNED, self::REJECTED, self::APPROVED, self::CANCELLED];

    public const KINDS = ['work' => 'Work done', 'advance' => 'Advance payment', 'variation' => 'Variation', 'other' => 'Other'];

    public $casts = ['extra_attributes' => 'array', 'request_date' => 'date', 'requested_amount' => 'decimal:2', 'approved_amount' => 'decimal:2', 'override' => 'boolean',
        'reviewed_at' => 'datetime', 'approved_at' => 'datetime'];

    public function agreement()
    {
        return $this->belongsTo(SubcontractAgreement::class, 'agreement_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function items()
    {
        return $this->hasMany(SubcontractRequestItem::class, 'request_id');
    }

    public function payments()
    {
        return $this->hasMany(SubcontractPayment::class, 'request_id')->orderBy('id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public static function nextReference(): string
    {
        $prefix = 'SPR-' . now()->format('Y') . '-';
        $max = (int) \Illuminate\Support\Facades\DB::table('subcontract_payment_requests')->where('reference', 'like', $prefix . '%')->selectRaw('MAX(CAST(SUBSTRING(reference, ' . (strlen($prefix) + 1) . ') AS UNSIGNED)) m')->value('m');

        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
