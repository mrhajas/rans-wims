<?php

namespace App\Http\Controllers;

use App\Models\WorkPackage;
use App\Models\WorkPackageEquipmentForecast;
use App\Http\Requests\WorkPackageEquipmentForecastStoreRequest;
use App\Traits\LogsPemAudit;
use App\Traits\GuardsCompletedWarehouse;

class WorkPackageEquipmentForecastController extends Controller
{
    use LogsPemAudit;
    use GuardsCompletedWarehouse;

    public function store(WorkPackageEquipmentForecastStoreRequest $request, WorkPackage $work_package)
    {
        $this->authorize('manageForecast', $work_package);
        if ($response = $this->rejectIfWarehouseCompleted($work_package->warehouse_id)) {
            return $response;
        }
        $data = $request->validated();
        $data['work_package_id'] = $work_package->id;
        $data['created_by'] = $request->user()->id;

        $forecast = WorkPackageEquipmentForecast::create($data);

        $this->logPemAudit($work_package, 'Equipment Forecast Added', null, [
            'item_id' => $forecast->item_id,
            'required_quantity' => $forecast->required_quantity,
        ]);

        return back()->with('message', __('Equipment forecast added.'));
    }

    public function destroy(WorkPackage $work_package, WorkPackageEquipmentForecast $equipment_forecast)
    {
        $this->authorize('manageForecast', $work_package);
        if ($response = $this->rejectIfWarehouseCompleted($work_package->warehouse_id)) {
            return $response;
        }

        $equipment_forecast->delete();

        $this->logPemAudit($work_package, 'Equipment Forecast Removed', ['item_id' => $equipment_forecast->item_id], null);

        return back()->with('message', __('Equipment forecast removed.'));
    }
}
