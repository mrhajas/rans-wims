<?php

namespace App\Http\Controllers;

use App\Models\WorkPackage;
use App\Models\WorkPackageMaterialForecast;
use App\Http\Requests\WorkPackageMaterialForecastStoreRequest;
use App\Traits\LogsPemAudit;
use App\Traits\GuardsCompletedWarehouse;

class WorkPackageMaterialForecastController extends Controller
{
    use LogsPemAudit;
    use GuardsCompletedWarehouse;

    public function store(WorkPackageMaterialForecastStoreRequest $request, WorkPackage $work_package)
    {
        $this->authorize('manageForecast', $work_package);
        if ($response = $this->rejectIfWarehouseCompleted($work_package->warehouse_id)) {
            return $response;
        }
        $data = $request->validated();
        $data['work_package_id'] = $work_package->id;
        $data['created_by'] = $request->user()->id;

        $forecast = WorkPackageMaterialForecast::create($data);

        $this->logPemAudit($work_package, 'Material Forecast Added', null, [
            'item_id' => $forecast->item_id,
            'required_quantity' => $forecast->required_quantity,
        ]);

        return back()->with('message', __('Material forecast added.'));
    }

    public function destroy(WorkPackage $work_package, WorkPackageMaterialForecast $material_forecast)
    {
        $this->authorize('manageForecast', $work_package);
        if ($response = $this->rejectIfWarehouseCompleted($work_package->warehouse_id)) {
            return $response;
        }

        $material_forecast->delete();

        $this->logPemAudit($work_package, 'Material Forecast Removed', ['item_id' => $material_forecast->item_id], null);

        return back()->with('message', __('Material forecast removed.'));
    }
}
