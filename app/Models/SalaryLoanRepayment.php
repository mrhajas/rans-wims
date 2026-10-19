<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryLoanRepayment extends Model
{
    protected $fillable = ['salary_loan_id', 'salary_record_id', 'principal_portion', 'interest_portion', 'amount'];

    protected $casts = [
        'principal_portion' => 'decimal:2',
        'interest_portion' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function loan()
    {
        return $this->belongsTo(SalaryLoan::class, 'salary_loan_id');
    }

    public function record()
    {
        return $this->belongsTo(SalaryRecord::class, 'salary_record_id');
    }
}
