<?php

namespace Tests\Unit;

use App\Services\Face\FaceService;
use Tests\TestCase;

class FaceServiceTest extends TestCase
{
    private function vec(float $base, float $jitter = 0.0): array
    {
        return array_map(fn ($i) => $base + sin($i) * 0.1 + $jitter * cos($i * 3), range(0, 127));
    }

    public function test_clean_accepts_only_128_finite_numbers(): void
    {
        $this->assertCount(128, FaceService::clean($this->vec(0.0)));
        $this->assertNull(FaceService::clean(array_slice($this->vec(0.0), 0, 127)));
        $this->assertNull(FaceService::clean(array_merge(array_slice($this->vec(0.0), 0, 127), ['x'])));
        $this->assertNull(FaceService::clean(array_merge(array_slice($this->vec(0.0), 0, 127), [99])));
        $this->assertNull(FaceService::clean('nope'));
    }

    public function test_distance_and_best(): void
    {
        $a = $this->vec(0.0);
        $this->assertEqualsWithDelta(0.0, FaceService::distance($a, $a), 1e-9);
        $b = $this->vec(0.0, 0.02);
        $this->assertLessThan(FaceService::distance($a, $this->vec(0.2)), FaceService::distance($a, $b));
        $this->assertEqualsWithDelta(FaceService::distance($a, $b), FaceService::best($a, [$this->vec(0.5), $b]), 1e-9);
    }

    public function test_samples_must_be_the_same_face(): void
    {
        $this->assertTrue(FaceService::consistent([$this->vec(0.0), $this->vec(0.0, 0.01), $this->vec(0.0, 0.02)]));
        $this->assertFalse(FaceService::consistent([$this->vec(0.0), $this->vec(0.4)]));
    }

    public function test_identical_frames_are_not_live(): void
    {
        $a = $this->vec(0.0);
        $this->assertFalse(FaceService::looksLive([$a, $a, $a]));
        $this->assertTrue(FaceService::looksLive([$a, $this->vec(0.0, 0.02), $a]));
    }

    public function test_the_plan_is_two_blinks_normally_and_adds_a_turn_when_unusual(): void
    {
        $normal = FaceService::plan(false);
        $this->assertCount(2, $normal);
        $this->assertSame(['cue', 'cue'], array_column($normal, 'k'));
        $risky = FaceService::plan(true);
        $this->assertCount(4, $risky);
        $this->assertSame(3, count(array_filter($risky, fn ($p) => $p['k'] === 'cue')));
        $this->assertSame(1, count(array_filter($risky, fn ($p) => in_array($p['k'], ['left', 'right'], true))));
    }

    public function test_the_lock_grows_with_every_strike(): void
    {
        $this->assertSame([15, 30, 60, 1440], [FaceService::lockMinutes(0), FaceService::lockMinutes(1), FaceService::lockMinutes(2), FaceService::lockMinutes(30)]);
    }

    public function test_telemetry_must_match_the_challenge(): void
    {
        $plan = [['k' => 'cue', 'delay' => 1500], ['k' => 'cue', 'delay' => 2000]];
        $good = ['camera' => 'FaceTime HD Camera', 'span' => 5000, 'steps' => [['k' => 'front'], ['k' => 'cue', 'delay' => 1500, 'latency' => 400, 'dur' => 150, 'misses' => 0], ['k' => 'cue', 'delay' => 2000, 'latency' => 450, 'dur' => 150, 'misses' => 1]]];
        $issued = now()->timestamp - 8;
        $this->assertNull(FaceService::telemetryError($good, $plan, $issued));
        $this->assertSame('virtual_camera', FaceService::telemetryError(['camera' => 'OBS Virtual Camera'] + $good, $plan, $issued));
        $this->assertSame('cue', FaceService::telemetryError(array_replace_recursive($good, ['steps' => [1 => ['delay' => 999]]]), $plan, $issued));
        $this->assertSame('latency', FaceService::telemetryError(array_replace_recursive($good, ['steps' => [2 => ['latency' => 20]]]), $plan, $issued));
        $this->assertSame('too_fast', FaceService::telemetryError($good, $plan, now()->timestamp));
        $this->assertSame('steps', FaceService::telemetryError(['steps' => []], $plan, $issued));
    }

    public function test_a_replayed_template_and_a_blend_of_frames_are_caught(): void
    {
        $a = $this->vec(0.0);
        $this->assertTrue(FaceService::tooPerfect([$a], [$a]));
        $this->assertFalse(FaceService::tooPerfect([$this->vec(0.0, 0.05)], [$a]));
        $this->assertTrue(FaceService::meanOk([0.2, 0.25, 0.3]));
        $this->assertFalse(FaceService::meanOk([0.4, 0.44, 0.45]));
    }
}
