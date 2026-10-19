<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryRecordItem extends Model
{
    public const TYPE_EARNING = 'earning';
    public const TYPE_DEDUCTION = 'deduction';

    /**
     * An employer-only statutory cost (ZSSF employer share, WCF, SDL) —
     * tracked for audit/reporting alongside the record it was computed
     * against, but deliberately never folded into total_earnings/
     * total_deductions/net_salary, since it is never actually paid to or
     * withheld from the employee.
     */
    public const TYPE_EMPLOYER_COST = 'employer_cost';

    public const SOURCE_COMPONENT = 'component';
    public const SOURCE_ADVANCE = 'advance';
    public const SOURCE_LOAN = 'loan';
    public const SOURCE_OUTSTANDING = 'outstanding';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_STATUTORY = 'statutory';

    protected $fillable = ['salary_record_id', 'salary_component_id', 'label', 'amount', 'type', 'source'];

    protected $casts = ['amount' => 'decimal:2'];

    public function record()
    {
        return $this->belongsTo(SalaryRecord::class, 'salary_record_id');
    }

    public function component()
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
