<?php

namespace App\Models;

use App\Traits\HasAttachments;

/**
 * A Contract Payment Request: one request to pay a subcontractor for several completed agreements. It is decided line by line (SubcontractClaimLine) and its status is
 * always worked out from its lines (App\Services\Subcontracts\SubcontractClaims::recalc), never typed. Approving a line creates the ordinary payment request of that
 * agreement, so nothing here ever moves money: only a paid Payment Voucher does.
 */
class SubcontractClaim extends Model
{
    use HasAttachments;

    public static $tracksDeletes = true;

    public const DRAFT = 'Draft';
    public const SUBMITTED = 'Submitted';
    public const REVIEW = 'Under Review';
    public const PARTIAL = 'Partially Approved';
    public const APPROVED = 'Approved';
    public const RETURNED = 'Returned';
    public const DEFERRED = 'Deferred';
    public const REJECTED = 'Rejected';
    public const CANCELLED = 'Cancelled';

    public const STATUSES = [self::DRAFT, self::SUBMITTED, self::REVIEW, self::PARTIAL, self::APPROVED, self::RETURNED, self::DEFERRED, self::REJECTED, self::CANCELLED];

    public $casts = ['extra_attributes' => 'array', 'request_date' => 'date', 'requested_total' => 'decimal:2', 'approved_total' => 'decimal:2', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function lines()
    {
        return $this->hasMany(SubcontractClaimLine::class, 'claim_id')->orderBy('id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class)->withoutGlobalScopes()->withTrashed();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function payments()
    {
        return $this->hasMany(SubcontractPayment::class, 'claim_id')->orderBy('id');
    }

    public static function nextReference(): string
    {
        $prefix = 'SCL-' . now()->format('Y') . '-';
        $max = (int) \Illuminate\Support\Facades\DB::table('subcontract_claims')->where('reference', 'like', $prefix . '%')->selectRaw('MAX(CAST(SUBSTRING(reference, ' . (strlen($prefix) + 1) . ') AS UNSIGNED)) m')->value('m');

        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
