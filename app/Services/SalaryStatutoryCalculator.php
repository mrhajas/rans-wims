<?php

namespace App\Services;

use App\Models\SalaryStatutorySetting;

/**
 * PAYE/ZSSF/WCF/SDL math only — reads its rates from SalaryStatutorySetting
 * (softcoded per account, editable in Salary Settings) rather than
 * hardcoding them here, since tax law changes independently of this app.
 */
class SalaryStatutoryCalculator
{
    public function __construct(private SalaryStatutorySetting $settings)
    {
    }

    /** Progressive band tax on monthly taxable pay — standard "X + rate% of amount exceeding the band floor" bracket math. */
    public function paye(float $grossPay): float
    {
        $tax = 0.0;
        $lowerBound = 0.0;

        foreach ($this->settings->paye_bands as $band) {
            $upperBound = $band['up_to'] ?? null;
            $bandCeiling = $upperBound === null ? $grossPay : min($grossPay, (float) $upperBound);
            $taxableInBand = max(0.0, $bandCeiling - $lowerBound);

            if ($taxableInBand > 0) {
                $tax += $taxableInBand * (float) $band['rate'];
            }

            if ($upperBound !== null && $grossPay <= $upperBound) {
                break;
            }

            $lowerBound = $upperBound === null ? $grossPay : (float) $upperBound;
        }

        return round($tax, 2);
    }

    public function nssfEmployee(float $grossPay): float
    {
        return round($grossPay * $this->settings->nssf_employee_rate, 2);
    }

    public function nssfEmployer(float $grossPay): float
    {
        return round($grossPay * $this->settings->nssf_employer_rate, 2);
    }

    public function wcf(float $grossPay): float
    {
        return round($grossPay * $this->settings->wcf_rate, 2);
    }

    public function sdl(float $grossPay): float
    {
        return round($grossPay * $this->settings->sdl_rate, 2);
    }
}
