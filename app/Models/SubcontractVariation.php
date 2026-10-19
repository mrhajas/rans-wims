<?php

namespace App\Models;

use App\Traits\HasAttachments;

/** Additional works or a revised cost. Changes the revised contract value only once Approved; the original amount is never touched. */
class SubcontractVariation extends Model
{
    use HasAttachments;

    public static $tracksDeletes = true;

    public const DRAFT = 'Draft';
    public const SUBMITTED = 'Submitted';
    public const REVIEWED = 'Reviewed';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';

    public const STATUSES = [self::DRAFT, self::SUBMITTED, self::REVIEWED, self::APPROVED, self::REJECTED];

    public $casts = ['extra_attributes' => 'array', 'amount' => 'decimal:2', 'effective_date' => 'date', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime'];

    public function agreement()
    {
        return $this->belongsTo(SubcontractAgreement::class, 'agreement_id');
    }

    public function items()
    {
        return $this->hasMany(SubcontractWorkItem::class, 'variation_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public static function nextReference(): string
    {
        $prefix = 'VAR-' . now()->format('Y') . '-';
        $max = (int) \Illuminate\Support\Facades\DB::table('subcontract_variations')->where('reference', 'like', $prefix . '%')->selectRaw('MAX(CAST(SUBSTRING(reference, ' . (strlen($prefix) + 1) . ') AS UNSIGNED)) m')->value('m');

        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
