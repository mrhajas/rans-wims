<?php

namespace Tests\Unit;

use App\Models\Staff;
use App\Models\StaffContract;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class StaffContractTest extends TestCase
{
    private function contract(?string $end, string $status = 'Active'): StaffContract
    {
        $c = new StaffContract();
        $c->forceFill(['status' => $status, 'start_date' => '2025-01-01', 'end_date' => $end]);

        return $c;
    }

    public function test_days_left_and_state_follow_the_end_date(): void
    {
        $today = CarbonImmutable::parse('2026-10-12');
        $this->assertSame('open', $this->contract(null)->state($today));
        $this->assertNull($this->contract(null)->daysLeft($today));
        $this->assertSame('ok', $this->contract('2027-03-01')->state($today));
        $this->assertSame('soon', $this->contract('2026-11-20')->state($today));      // 39 days
        $this->assertSame('urgent', $this->contract('2026-10-20')->state($today));      // 8 days
        $this->assertSame('urgent', $this->contract('2026-10-12')->state($today));      // today
        $this->assertSame('expired', $this->contract('2026-10-11')->state($today));
        $this->assertSame(-1, $this->contract('2026-10-11')->daysLeft($today));
        $this->assertSame(60, $this->contract('2026-12-11')->daysLeft($today));
    }

    public function test_a_renewed_or_ended_contract_keeps_its_own_state(): void
    {
        $today = CarbonImmutable::parse('2026-10-12');
        $this->assertSame('renewed', $this->contract('2026-10-20', 'Renewed')->state($today));
        $this->assertSame('ended', $this->contract('2020-01-01', 'Ended')->state($today));
    }

    public function test_retired_active_is_paid_but_retired_is_not(): void
    {
        $this->assertContains('Retired Active', Staff::PAYROLL_STATUSES);
        $this->assertContains('Active', Staff::PAYROLL_STATUSES);
        $this->assertNotContains('Retired', Staff::PAYROLL_STATUSES);
        $this->assertNotContains('Terminated', Staff::PAYROLL_STATUSES);
        $this->assertContains('Retired', Staff::STATUSES);
        $this->assertContains('Retired Active', Staff::STATUSES);
    }
}
