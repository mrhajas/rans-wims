<?php

namespace App\Services;

use App\Models\WorkPackage;
use Carbon\Carbon;

class WorkPackageScheduleService
{
    public const HEALTH_GREEN = 'green';
    public const HEALTH_YELLOW = 'yellow';
    public const HEALTH_ORANGE = 'orange';
    public const HEALTH_RED = 'red';

    /**
     * Compute the automatic schedule-monitoring numbers for a single Work Package.
     * Nothing here is stored — it is always derived live from planned_start,
     * planned_finish, planned_duration and actual_progress_percent.
     */
    public function evaluate(WorkPackage $workPackage, ?Carbon $today = null): array
    {
        $today = $today ?: Carbon::today();
        $start = Carbon::parse($workPackage->planned_start)->startOfDay();
        $finish = Carbon::parse($workPackage->planned_finish)->startOfDay();

        $plannedDuration = max(0, (int) ($workPackage->planned_duration ?: $start->diffInDays($finish)));

        $elapsedDays = $today->lessThan($start)
            ? 0
            : min($plannedDuration, $start->diffInDays($today));

        $expectedProgress = $plannedDuration > 0
            ? round(($elapsedDays / $plannedDuration) * 100, 1)
            : ($today->greaterThanOrEqualTo($start) ? 100.0 : 0.0);

        $actualProgress = round((float) $workPackage->actual_progress_percent, 1);
        $variance = round($actualProgress - $expectedProgress, 1);

        $estimatedFinish = null;
        $estimatedDelayDays = 0;

        if ($actualProgress > 0 && $elapsedDays > 0) {
            $projectedTotalDuration = (int) ceil($elapsedDays / ($actualProgress / 100));
            $estimatedFinish = $start->copy()->addDays($projectedTotalDuration);
            $estimatedDelayDays = (int) $finish->diffInDays($estimatedFinish, false);
        } elseif ($today->greaterThan($finish) && $actualProgress < 100) {
            // Past the planned finish date with no progress recorded yet — flag using elapsed overrun.
            $estimatedFinish = $today->copy();
            $estimatedDelayDays = (int) $finish->diffInDays($today, false);
        }

        $health = $this->health($workPackage, $variance, $estimatedDelayDays);

        return [
            'planned_duration' => $plannedDuration,
            'elapsed_days' => $elapsedDays,
            'expected_progress' => $expectedProgress,
            'actual_progress' => $actualProgress,
            'variance' => $variance,
            'estimated_finish' => $estimatedFinish?->toDateString(),
            'estimated_delay_days' => max(0, $estimatedDelayDays),
            'health' => $health,
        ];
    }

    protected function health(WorkPackage $workPackage, float $variance, int $delayDays): string
    {
        if ($workPackage->status === WorkPackage::STATUS_COMPLETED) {
            return self::HEALTH_GREEN;
        }

        if ($variance >= -5 && $delayDays <= 2) {
            return self::HEALTH_GREEN;
        }

        if ($variance >= -15 && $delayDays <= 7) {
            return self::HEALTH_YELLOW;
        }

        if ($variance >= -30 && $delayDays <= 14) {
            return self::HEALTH_ORANGE;
        }

        return self::HEALTH_RED;
    }

    public static function healthLabel(string $health): string
    {
        return match ($health) {
            self::HEALTH_GREEN => 'Ahead of Schedule',
            self::HEALTH_YELLOW => 'Slight Delay',
            self::HEALTH_ORANGE => 'Behind Schedule',
            self::HEALTH_RED => 'Critical Delay',
            default => 'Unknown',
        };
    }
}
