<?php

namespace App\Models;

use App\Traits\HasAttachments;

/** A subcontract agreement between RANS and a subcontractor (a Contact in the SubContractors category). Money figures are derived by App\Services\Subcontracts\SubcontractLedger. */
class SubcontractAgreement extends Model
{
    use HasAttachments;

    public static $tracksDeletes = true;

    public const DRAFT = 'Draft';
    public const SUBMITTED = 'Submitted';
    public const REVIEW = 'Under Review';
    public const APPROVED = 'Approved';
    public const ACTIVE = 'Active';
    public const SUSPENDED = 'Suspended';
    public const COMPLETED = 'Completed';
    public const TERMINATED = 'Terminated';
    public const CANCELLED = 'Cancelled';
    /** Kept for reference: old versions of an account and finished accounts. Read-only, never paid, left out of the totals of the dashboard and the register; its own balance is still shown. */
    public const ARCHIVED = 'Archived';

    public const STATUSES = [self::DRAFT, self::SUBMITTED, self::REVIEW, self::APPROVED, self::ACTIVE, self::SUSPENDED, self::COMPLETED, self::TERMINATED, self::CANCELLED, self::ARCHIVED];

    /** Statuses in which the agreement is a payable commitment. */
    public const PAYABLE = [self::APPROVED, self::ACTIVE, self::COMPLETED];

    public const TYPES = ['quantity' => 'Quantity based', 'lump_sum' => 'Lump sum', 'mixed' => 'Mixed', 'labour' => 'Labour only'];

    public $casts = ['extra_attributes' => 'array', 'agreement_date' => 'date', 'start_date' => 'date', 'expected_completion' => 'date', 'actual_completion' => 'date', 'as_at_date' => 'date',
        'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'completed_at' => 'datetime', 'completion_verified_at' => 'datetime'];

    protected static $logAttributesToIgnore = ['account_id', 'extra_attributes'];

    public function getRouteKeyName()
    {
        return 'id';
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class)->withoutGlobalScopes()->withTrashed();
    }

    public function administrator()
    {
        return $this->belongsTo(User::class, 'administrator_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function groups()
    {
        return $this->hasMany(SubcontractWorkGroup::class, 'agreement_id')->orderBy('sort_order')->orderBy('id');
    }

    public function items()
    {
        return $this->hasMany(SubcontractWorkItem::class, 'agreement_id')->orderBy('line_no')->orderBy('id');
    }

    public function variations()
    {
        return $this->hasMany(SubcontractVariation::class, 'agreement_id')->orderBy('id');
    }

    public function requests()
    {
        return $this->hasMany(SubcontractPaymentRequest::class, 'agreement_id')->orderBy('id');
    }

    public function payments()
    {
        return $this->hasMany(SubcontractPayment::class, 'agreement_id')->orderBy('id');
    }

    public function historicalPayments()
    {
        return $this->hasMany(SubcontractHistoricalPayment::class, 'agreement_id')->orderBy('id');
    }

    public function reconciliation()
    {
        return $this->hasOne(SubcontractReconciliation::class, 'agreement_id');
    }

    public function isPayable(): bool
    {
        return in_array($this->status, self::PAYABLE, true);
    }

    public static function nextReference(): string
    {
        $prefix = 'SCA-' . now()->format('Y') . '-';
        $max = (int) \Illuminate\Support\Facades\DB::table('subcontract_agreements')->where('reference', 'like', $prefix . '%')->selectRaw('MAX(CAST(SUBSTRING(reference, ' . (strlen($prefix) + 1) . ') AS UNSIGNED)) m')->value('m');

        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Who may see which agreements: Super Admins and people who see everything, the administrator and the person who made it, and people who work in the project. Approving, reviewing or seeing the money (view-financials) does not widen it: that is a separate matter from which projects a person may see. */
    public function scopeVisibleTo($q, User $u)
    {
        if ($u->hasRole('Super Admin') || $u->view_all) {
            return $q;
        }
        $ids = accessible_warehouse_ids();

        return $q->where(function ($w) use ($u, $ids) {
            $w->where('administrator_user_id', $u->id)->orWhere('user_id', $u->id);
            if ($ids) {
                $w->orWhereIn('warehouse_id', $ids);
            }
        });
    }
}
