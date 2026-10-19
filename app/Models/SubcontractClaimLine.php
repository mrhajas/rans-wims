<?php

namespace App\Models;

/** One agreement on a claim: how much is asked for it, and what was decided. The money figures of its agreement always come from SubcontractLedger. */
class SubcontractClaimLine extends Model
{
    public static $hasAccount = false;

    public const DRAFT = 'Draft';
    public const PENDING = 'Pending';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';
    public const RETURNED = 'Returned';
    public const DEFERRED = 'Deferred';
    public const CANCELLED = 'Cancelled';

    public const STATUSES = [self::DRAFT, self::PENDING, self::APPROVED, self::REJECTED, self::RETURNED, self::DEFERRED, self::CANCELLED];

    /** Lines that hold back (reserve) part of the agreement's balance while their claim is not a draft: nothing else can be requested against it. */
    public const RESERVING = [self::PENDING, self::RETURNED, self::DEFERRED];

    public $casts = ['requested_amount' => 'decimal:2', 'approved_amount' => 'decimal:2', 'override' => 'boolean', 'snapshot' => 'array', 'decided_at' => 'datetime'];

    public function claim()
    {
        return $this->belongsTo(SubcontractClaim::class, 'claim_id');
    }

    public function agreement()
    {
        return $this->belongsTo(SubcontractAgreement::class, 'agreement_id');
    }

    public function request()
    {
        return $this->belongsTo(SubcontractPaymentRequest::class, 'request_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
