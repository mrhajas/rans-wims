<?php

namespace App\Models;

class LabourListItem extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public const PAYEE_TYPE_STAFF = 'Internal Staff';

    public const PAYEE_TYPE_EXTERNAL = 'External Personnel';

    public const RATE_TYPE_DAY = 'day';

    public const RATE_TYPE_HOUR = 'hour';

    public const RATE_TYPE_FIXED = 'fixed';

    public const PRIORITY_LOW = 'Low';

    public const PRIORITY_NORMAL = 'Normal';

    public const PRIORITY_HIGH = 'High';

    public const PRIORITY_EXTREME = 'Extreme';

    protected $fillable = [
        'labour_list_id', 'workforce_agreement_id', 'payee_type', 'contact_id', 'staff_id', 'payee_name',
        'priority', 'work_description', 'days', 'hours', 'rate_type', 'rate', 'amount',
        'approved_amount', 'revision_reason',
    ];

    public $casts = [
        'days' => 'decimal:2',
        'hours' => 'decimal:2',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
    ];

    public static function rateTypes(): array
    {
        return [self::RATE_TYPE_DAY, self::RATE_TYPE_HOUR, self::RATE_TYPE_FIXED];
    }

    public static function priorities(): array
    {
        return [self::PRIORITY_LOW, self::PRIORITY_NORMAL, self::PRIORITY_HIGH, self::PRIORITY_EXTREME];
    }

    /** Severity order for rolling up "the worst priority among this list's items" — both in PHP (index() row transform) and in the raw SQL CASE below (sort-by-Priority). */
    public static function priorityRank(?string $priority): int
    {
        return array_flip(self::priorities())[$priority] ?? 1;
    }

    /** Raw SQL fragment mapping the text priority to the same rank order as priorityRank(), for use inside an ORDER BY subquery (see LabourController::SORTABLE_COLUMNS). */
    public static function priorityRankSql(string $column = 'priority'): string
    {
        return "CASE {$column} "
            . "WHEN '" . self::PRIORITY_LOW . "' THEN 0 "
            . "WHEN '" . self::PRIORITY_NORMAL . "' THEN 1 "
            . "WHEN '" . self::PRIORITY_HIGH . "' THEN 2 "
            . "WHEN '" . self::PRIORITY_EXTREME . "' THEN 3 "
            . 'ELSE 1 END';
    }

    /** Server-side source of truth for the amount — never trust a client-submitted total. */
    public static function computeAmount(string $rateType, float $rate, ?float $days, ?float $hours): float
    {
        return match ($rateType) {
            self::RATE_TYPE_DAY => round($rate * (float) $days, 2),
            self::RATE_TYPE_HOUR => round($rate * (float) $hours, 2),
            default => round($rate, 2),
        };
    }

    public function labourList()
    {
        return $this->belongsTo(LabourList::class)->withoutGlobalScope('workflow_visibility');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function agreement()
    {
        return $this->belongsTo(WorkforceAgreement::class, 'workforce_agreement_id');
    }

    /** The one voucher generated from this line, if the parent list has been converted. */
    public function paymentVoucher()
    {
        return $this->hasOne(PaymentVoucher::class, 'labour_list_item_id');
    }

    /** The definitive amount once Super Admin has (optionally) revised it — use this everywhere downstream, never `amount` alone. */
    public function finalAmount(): float
    {
        return (float) ($this->approved_amount ?? $this->amount);
    }
}
