<?php

namespace Tests\Unit;

use App\Models\Licence;
use App\Models\User;
use App\Services\Licensing\LicenceService;
use Carbon\Carbon;
use Tests\TestCase;

/** Licence keys (128 characters, signed) and the state shown for a licence. No database needed. */
class LicenceTest extends TestCase
{
    private function details(): array
    {
        return ['licence_number' => 'WIMS-LIC-2026-0007', 'licensee' => 'Acme Ltd', 'starts_at' => '2026-10-10', 'expires_at' => '2028-10-10'];
    }

    public function test_key_has_128_characters_and_verifies(): void
    {
        $key = LicenceService::makeKey($this->details());
        $this->assertSame(128, strlen($key));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{128}$/', $key);
        $this->assertTrue(LicenceService::keyMatchesDetails($key, $this->details()));
        $this->assertTrue(LicenceService::keyMatchesDetails(chunk_split($key, 4, '-'), $this->details()), 'dashes and spaces are ignored');
    }

    public function test_key_is_bound_to_its_details(): void
    {
        $key = LicenceService::makeKey($this->details());
        foreach (['expires_at' => '2030-01-01', 'licensee' => 'Other Ltd', 'licence_number' => 'WIMS-LIC-2026-0008'] as $field => $value) {
            $this->assertFalse(LicenceService::keyMatchesDetails($key, [...$this->details(), $field => $value]), "changing {$field} must break the key");
        }
        $this->assertFalse(LicenceService::keyMatchesDetails(substr($key, 0, 127).($key[127] === 'A' ? 'B' : 'A'), $this->details()));
    }

    public function test_two_keys_differ(): void
    {
        $this->assertNotSame(LicenceService::makeKey($this->details()), LicenceService::makeKey($this->details()));
    }

    public function test_states_follow_status_and_dates(): void
    {
        Carbon::setTestNow('2026-10-20');
        $make = fn (string $status, string $from, string $to): Licence => new Licence(['status' => $status, 'starts_at' => $from, 'expires_at' => $to]);

        $this->assertSame('active', $make('Active', '2026-10-10', '2028-10-10')->state);
        $this->assertSame('expiring', $make('Active', '2026-01-01', '2026-11-05')->state);
        $this->assertSame('active', $make('Active', '2026-01-01', '2026-10-20')->state === 'expiring' ? 'active' : 'x', 'last day is still valid');
        $this->assertTrue($make('Active', '2026-01-01', '2026-10-20')->is_valid, 'valid through the whole last day');
        $this->assertSame('expired', $make('Active', '2025-01-01', '2026-10-19')->state);
        $this->assertSame('scheduled', $make('Active', '2026-12-01', '2027-12-01')->state);
        $this->assertSame('deactivated', $make('Deactivated', '2026-10-10', '2028-10-10')->state);
        $this->assertFalse($make('Deactivated', '2026-10-10', '2028-10-10')->is_valid);
        $this->assertFalse($make('Issued', '2026-10-10', '2028-10-10')->is_valid);
        Carbon::setTestNow();
    }

    public function test_duration_label(): void
    {
        $this->assertSame('2 years', (new Licence(['starts_at' => '2026-10-10', 'expires_at' => '2028-10-10']))->duration_label);
        $this->assertSame('6 months', (new Licence(['starts_at' => '2026-01-15', 'expires_at' => '2026-07-15']))->duration_label);
    }

    public function test_only_dr_hajas_is_the_manager(): void
    {
        $this->assertTrue(LicenceService::isManager((new User)->forceFill(['id' => 55, 'name' => 'Someone', 'email' => 'extriontech@gmail.com'])));
        $this->assertTrue(LicenceService::isManager((new User)->forceFill(['id' => 1, 'name' => 'DrHajas', 'email' => 'new@example.com'])));
        $this->assertFalse(LicenceService::isManager((new User)->forceFill(['id' => 2, 'name' => 'DrHajas', 'email' => 'x@example.com'])));
        $this->assertFalse(LicenceService::isManager((new User)->forceFill(['id' => 3, 'name' => 'Super Admin', 'email' => 'boss@example.com'])));
        $this->assertFalse(LicenceService::isManager(null));
    }
}
