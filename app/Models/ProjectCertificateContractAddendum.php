<?php

namespace App\Models;

use App\Traits\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectCertificateContractAddendum extends Model
{
    use HasAttachments;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL, self::STATUS_APPROVED, self::STATUS_REJECTED];
    }

    protected $fillable = [
        'contract_id', 'addendum_number', 'title', 'reason', 'addendum_date', 'reference_number',
        'effective_date', 'status', 'notes', 'signed_date', 'signed_by', 'total_amount',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'addendum_date' => 'date',
        'effective_date' => 'date',
        'signed_date' => 'date',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function contract()
    {
        return $this->belongsTo(ProjectCertificateContract::class, 'contract_id');
    }

    public function items()
    {
        return $this->hasMany(ProjectCertificateContractAddendumItem::class, 'addendum_id')->orderBy('sort_order');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Recomputes and persists total_amount from the items relation — called
     * after any item add/update/remove so the contract page's summary
     * (Total Addendum Sum) never has to re-sum items itself.
     */
    public function recalculateTotal(): void
    {
        $this->update(['total_amount' => round((float) $this->items()->sum('amount'), 2)]);
    }
}
