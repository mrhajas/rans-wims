<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use App\Models\Warehouse;
use App\Models\WorkPackage;
use App\Models\WorkPackageEquipmentForecast;
use App\Models\WorkPackageIssue;
use App\Models\WorkPackageLabourForecast;
use App\Models\WorkPackageMaterialForecast;
use App\Services\WorkPackageScheduleService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class ProjectExecutionController extends Controller
{
    public function dashboard(WorkPackageScheduleService $scheduleService)
    {
        $this->authorize('viewAny', WorkPackage::class);

        $projects = $this->projectSummaries($scheduleService);

        return Inertia::render('ProjectExecution/Dashboard', [
            'projectCount' => $projects->count(),
            'activeWorkPackages' => WorkPackage::ofAccount()->where('status', WorkPackage::STATUS_IN_PROGRESS)->count(),
            'delayedProjects' => $projects->whereIn('health', ['orange', 'red'])->count(),
            'onTrackProjects' => $projects->whereIn('health', ['green', 'yellow'])->count(),
            'projects' => $projects->take(6)->values(),
        ]);
    }

    public function projects(WorkPackageScheduleService $scheduleService)
    {
        $this->authorize('viewAny', WorkPackage::class);

        return Inertia::render('ProjectExecution/Projects/Index', [
            'projects' => $this->projectSummaries($scheduleService)->values(),
        ]);
    }

    protected function projectSummaries(WorkPackageScheduleService $scheduleService)
    {
        $warehouses = Warehouse::ofAccount()
            ->active()
            ->visible()
            ->whereNotIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses'])
            ->when(warehouse_scope_ids() !== null, fn ($q) => $q->whereIn('id', warehouse_scope_ids()))
            ->with(['workPackages' => fn ($q) => $q->orderByDesc('id')])
            ->get();

        return $warehouses->map(function (Warehouse $warehouse) use ($scheduleService) {
            $workPackages = $warehouse->workPackages;

            $evaluations = $workPackages->map(fn (WorkPackage $wp) => $scheduleService->evaluate($wp));

            $totalWeight = $workPackages->sum('expected_progress_weight') ?: 1;
            $progressIsBaseline = $workPackages->isEmpty();
            $overallProgress = $progressIsBaseline
                ? (float) ($warehouse->completion_percentage ?? 0)
                : round(
                    $workPackages->sum(fn ($wp) => $wp->actual_progress_percent * $wp->expected_progress_weight) / $totalWeight,
                    1
                );

            $healthOrder = ['red' => 4, 'orange' => 3, 'yellow' => 2, 'green' => 1];

            if ($progressIsBaseline) {
                // No work packages yet, so there is no schedule/variance data to judge —
                // a baseline project is either fully done or simply not tracked yet.
                $worstHealth = $overallProgress >= 100 ? 'green' : 'slate';
                $healthLabel = $overallProgress >= 100 ? 'Completed' : 'Not Yet Tracked';
            } else {
                $worstHealth = $evaluations->isEmpty() ? 'green' : $evaluations->sortByDesc(fn ($e) => $healthOrder[$e['health']])->first()['health'];
                $healthLabel = WorkPackageScheduleService::healthLabel($worstHealth);
            }

            $plannedFinish = $workPackages->max('planned_finish');
            $forecastFinish = $evaluations->pluck('estimated_finish')->filter()->max();
            $delayDays = $evaluations->max('estimated_delay_days') ?? 0;

            $lastUpdate = $workPackages->max('updated_at');
            $workPackageIds = $workPackages->pluck('id');

            $openIssues = $workPackageIds->isEmpty() ? 0 : WorkPackageIssue::whereIn('work_package_id', $workPackageIds)
                ->whereIn('status', [WorkPackageIssue::STATUS_OPEN, WorkPackageIssue::STATUS_IN_PROGRESS])
                ->count();

            $materialRisks = 0;
            $labourForecastCount = 0;
            $equipmentForecastCount = 0;

            if ($workPackageIds->isNotEmpty()) {
                $materialRisks = WorkPackageMaterialForecast::whereIn('work_package_id', $workPackageIds)
                    ->get(['item_id', 'required_quantity'])
                    ->filter(function ($forecast) use ($warehouse) {
                        $stock = (float) (Stock::query()->main()->ofWarehouse($warehouse->id)->ofItem($forecast->item_id)->value('quantity') ?? 0);

                        return $forecast->required_quantity > $stock;
                    })
                    ->count();

                $labourForecastCount = WorkPackageLabourForecast::whereIn('work_package_id', $workPackageIds)->count();
                $equipmentForecastCount = WorkPackageEquipmentForecast::whereIn('work_package_id', $workPackageIds)->count();
            }

            return [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'stage' => $warehouse->stage,
                'overall_progress' => $overallProgress,
                'progress_is_baseline' => $progressIsBaseline,
                'health' => $worstHealth,
                'health_label' => $healthLabel,
                'planned_finish' => $plannedFinish ? Carbon::parse($plannedFinish)->format('Y-m-d') : optional($warehouse->expected_end_date)->format('Y-m-d'),
                'forecast_finish' => $forecastFinish,
                'delay_days' => max(0, (int) $delayDays),
                'active_work_packages' => $workPackages->where('status', WorkPackage::STATUS_IN_PROGRESS)->count(),
                'total_work_packages' => $workPackages->count(),
                'open_issues' => $openIssues,
                'material_risks' => $materialRisks,
                'labour_forecast' => $labourForecastCount,
                'equipment_forecast' => $equipmentForecastCount,
                'last_update' => $lastUpdate,
            ];
        });
    }
}
