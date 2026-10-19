<?php

namespace App\Http\Controllers;

use App\Models\WorkPackage;
use App\Models\WorkPackageLabourForecast;
use App\Http\Requests\WorkPackageLabourForecastStoreRequest;
use App\Traits\LogsPemAudit;
use App\Traits\GuardsCompletedWarehouse;

class WorkPackageLabourForecastController extends Controller
{
    use LogsPemAudit;
    use GuardsCompletedWarehouse;

    public function store(WorkPackageLabourForecastStoreRequest $request, WorkPackage $work_package)
    {
        $this->authorize('manageForecast', $work_package);
        if ($response = $this->rejectIfWarehouseCompleted($work_package->warehouse_id)) {
            return $response;
        }
        $data = $request->validated();
        $data['work_package_id'] = $work_package->id;
        $data['created_by'] = $request->user()->id;

        $forecast = WorkPackageLabourForecast::create($data);

        $this->logPemAudit($work_package, 'Labour Forecast Added', null, [
            'trade' => $forecast->trade,
            'quantity' => $forecast->quantity,
        ]);

        return back()->with('message', __('Labour forecast added.'));
    }

    public function destroy(WorkPackage $work_package, WorkPackageLabourForecast $labour_forecast)
    {
        $this->authorize('manageForecast', $work_package);
        if ($response = $this->rejectIfWarehouseCompleted($work_package->warehouse_id)) {
            return $response;
        }

        $labour_forecast->delete();

        $this->logPemAudit($work_package, 'Labour Forecast Removed', ['trade' => $labour_forecast->trade], null);

        return back()->with('message', __('Labour forecast removed.'));
    }
}
